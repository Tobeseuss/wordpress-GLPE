#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GLPE Relay Core — client side.
SOCKS5/HTTP listener that encapsulates TCP/UDP traffic inside ordinary
HTTP GET/POST exchanges with the WordPress relay node, with multi-node
round-robin failover, GeoSite/GeoIP direct routing, adaptive pacing,
watchdogs and a live console dashboard.

Wire protocol (must stay byte-compatible with includes/Hub.php):
  - every request carries one opaque parameter `_v` =
    reverse( base64( json(envelope) ) ),
    envelope = {"a":action,"s":session,"t":channel,"h":host,"p":port,
                "k":secret,"net":"tcp"|"udp"}
  - action "c" : server wipes the session's stored chunks      -> "K"
  - action "w" : POST body = reverse(base64(raw bytes))        -> "K"
  - action "t" : GET stream; response body is a text/event-stream of
                 reverse(base64(raw bytes)) lines, prefixed by padding
                 that breaks CDN buffering.
"""

import base64
import os
import re
import socket
import struct
import threading
import time
import uuid
import requests
import json
import textwrap
import itertools
import ipaddress
import urllib.parse
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

try:
    with open('config.json', 'r', encoding='utf-8') as f:
        CFG = json.load(f)
except FileNotFoundError:
    print("[!] ERROR: config.json not found next to client.py!")
    exit(1)

MAX_DELAY = CFG.get("max_adaptive_delay", 1.0)
IDLE_TIMEOUT = CFG.get("idle_timeout", 12.0)
MAX_UPTIME = CFG.get("max_tunnel_uptime", 3600.0)
LOW_TRAFFIC_TIMEOUT = CFG.get("low_traffic_timeout", 35.0)
LOW_TRAFFIC_THRESHOLD_BYTES = CFG.get("low_traffic_threshold_mb", 0.15) * 1024 * 1024
ACTIVE_TRANSFER_THRESHOLD = CFG.get("active_transfer_threshold_kbps", 10.0) * 1024

# Split timeouts: on slow shared hosts a full node exchange may take seconds
# (that used to silently kill TLS handshakes inside the old single 10s cap).
CONNECT_TIMEOUT = CFG.get("connect_timeout", 15.0)
STREAM_READ_TIMEOUT = CFG.get("stream_read_timeout", CFG.get("request_timeout", 30.0))
WRITE_TIMEOUT = CFG.get("write_timeout", 20.0)

RELAY_PORT = CFG.get("relay_port", 41927)
SESSION_ID = uuid.uuid4().hex[:12]

# ======================================================================
# 1b. Edge challenge solver (free-hosting JS cookie challenge)
# ======================================================================
# Some shared/free hosting fronts gate every request that arrives from an
# external address behind a JavaScript cookie challenge: the response is a
# small HTML page that computes a cookie with slowAES and reloads. A browser
# passes transparently; a headless client used to be locked out COMPLETELY
# (every wire request answered with challenge HTML). The challenge is pure
# AES-128-CBC — three hex numbers in the page — so it is solved here without
# a browser and without any external dependency.

def _aes_xt(a):
    """Multiply by 2 in GF(2^8) modulo the AES polynomial."""
    a <<= 1
    if a & 0x100:
        a ^= 0x11B
    return a & 0xFF


def _aes_build_sbox():
    exp = [0] * 256
    log = [0] * 256
    x = 1
    for i in range(255):
        exp[i] = x
        log[x] = i
        x ^= _aes_xt(x)  # multiply by the generator 0x03
    inv = [0] * 256
    for v in range(1, 256):
        inv[v] = exp[(255 - log[v]) % 255]
    sbox = [0] * 256
    for v in range(256):
        b = inv[v]
        r = b
        s = b
        for _ in range(4):
            s = ((s << 1) | (s >> 7)) & 0xFF
            r ^= s
        sbox[v] = r ^ 0x63
    return sbox


_AES_SBOX = _aes_build_sbox()
_AES_INV_SBOX = [0] * 256
for _i, _v in enumerate(_AES_SBOX):
    _AES_INV_SBOX[_v] = _i


def _aes_gmul(a, b):
    p = 0
    for _ in range(8):
        if b & 1:
            p ^= a
        a = _aes_xt(a)
        b >>= 1
    return p


def _aes_expand_key(key):
    rcon = 1
    w = list(struct.unpack('>4I', key))
    for i in range(4, 44):
        t = w[i - 1]
        if i % 4 == 0:
            t = ((t << 8) & 0xFFFFFFFF) | (t >> 24)  # RotWord
            t = ((_AES_SBOX[(t >> 24) & 0xFF] << 24) | (_AES_SBOX[(t >> 16) & 0xFF] << 16)
                 | (_AES_SBOX[(t >> 8) & 0xFF] << 8) | _AES_SBOX[t & 0xFF])  # SubWord
            t ^= (rcon << 24)
            rcon = _aes_xt(rcon)
        w.append(w[i - 4] ^ t)
    return w


def _aes_decrypt_block(block, w):
    state = list(block)
    for c in range(4):
        word = w[4 * 10 + c]
        for r in range(4):
            state[r + 4 * c] ^= (word >> (8 * (3 - r))) & 0xFF
    for rd in range(9, 0, -1):
        out = [0] * 16
        for r in range(4):
            for c in range(4):
                out[r + 4 * c] = state[r + 4 * ((c - r) % 4)]  # InvShiftRows
        state[:] = out
        for i in range(16):
            state[i] = _AES_INV_SBOX[state[i]]  # InvSubBytes
        for c in range(4):
            word = w[4 * rd + c]
            for r in range(4):
                state[r + 4 * c] ^= (word >> (8 * (3 - r))) & 0xFF
        for c in range(4):  # InvMixColumns
            a0, a1, a2, a3 = state[4 * c:4 * c + 4]
            state[4 * c + 0] = _aes_gmul(a0, 14) ^ _aes_gmul(a1, 11) ^ _aes_gmul(a2, 13) ^ _aes_gmul(a3, 9)
            state[4 * c + 1] = _aes_gmul(a0, 9) ^ _aes_gmul(a1, 14) ^ _aes_gmul(a2, 11) ^ _aes_gmul(a3, 13)
            state[4 * c + 2] = _aes_gmul(a0, 13) ^ _aes_gmul(a1, 9) ^ _aes_gmul(a2, 14) ^ _aes_gmul(a3, 11)
            state[4 * c + 3] = _aes_gmul(a0, 11) ^ _aes_gmul(a1, 13) ^ _aes_gmul(a2, 9) ^ _aes_gmul(a3, 14)
    out = [0] * 16
    for r in range(4):
        for c in range(4):
            out[r + 4 * c] = state[r + 4 * ((c - r) % 4)]
    state[:] = out
    for i in range(16):
        state[i] = _AES_INV_SBOX[state[i]]
    for c in range(4):
        word = w[c]
        for r in range(4):
            state[r + 4 * c] ^= (word >> (8 * (3 - r))) & 0xFF
    return bytes(state)


def aes128_cbc_decrypt(ct, key, iv):
    """AES-128-CBC decrypt WITHOUT padding removal — the challenge page's
    browser-side toHex() serializes every decrypted byte, padding included,
    so the cookie value must keep it byte-for-byte."""
    if len(key) != 16 or len(iv) != 16 or len(ct) == 0 or len(ct) % 16 != 0:
        return None
    w = _aes_expand_key(key)
    out = b''
    prev = iv
    for i in range(0, len(ct), 16):
        blk = ct[i:i + 16]
        dec = _aes_decrypt_block(blk, w)
        out += bytes(a ^ b for a, b in zip(dec, prev))
        prev = blk
    return out


class EdgeGuard:
    """Detects and solves the hosting edge's JS cookie challenge."""

    COOKIE = "__test"
    BROWSER_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")

    @staticmethod
    def looks_like(body):
        if not body:
            return False
        return b"__test" in body and (b"aes.js" in body or b"slowAES" in body)

    @staticmethod
    def solve(body):
        """Extracts the (key, iv, ciphertext) triple and returns the cookie
        value (hex string), or None when the page does not parse."""
        vals = re.findall(rb'toNumbers\("([0-9a-fA-F]{32})"\)', body)
        if len(vals) < 3:
            return None
        try:
            key = bytes.fromhex(vals[0].decode('ascii'))
            iv = bytes.fromhex(vals[1].decode('ascii'))
            ct = bytes.fromhex(vals[2].decode('ascii'))
        except (ValueError, UnicodeDecodeError):
            return None
        plain = aes128_cbc_decrypt(ct, key, iv)
        return plain.hex() if plain else None

    @staticmethod
    def set_cookie(session, base_url, value):
        host = urllib.parse.urlparse(base_url).hostname or ''
        try:
            session.cookies.set(EdgeGuard.COOKIE, value, domain=host)
        except Exception:
            pass

    @staticmethod
    def cookie_header(existing, value):
        """Merges the solved cookie into an existing Cookie header value."""
        parts = [p.strip() for p in (existing or '').split(';') if p.strip()]
        parts = [p for p in parts if not p.startswith(EdgeGuard.COOKIE + '=')]
        parts.append(EdgeGuard.COOKIE + '=' + value)
        return '; '.join(parts)


class EdgeGuardAdapter(HTTPAdapter):
    """Mounts on every node session: when the edge answers a non-stream
    request with its challenge page, the request is replayed once with the
    freshly solved cookie. This is what protects the fire-and-forget writer
    POSTs — a challenge page there would silently swallow tunnel bytes."""

    def send(self, request, **kwargs):
        response = super().send(request, **kwargs)
        if kwargs.get('stream'):
            return response  # a live stream must never be consumed here
        try:
            if response.status_code == 200 and EdgeGuard.looks_like(response.content):
                value = EdgeGuard.solve(response.content)
                if value:
                    request.headers['Cookie'] = EdgeGuard.cookie_header(
                        request.headers.get('Cookie'), value)
                    response = super().send(request, **kwargs)
        except Exception:
            pass
        return response


# ======================================================================
# 1. Smart routing engine (GeoSite & GeoIP direct route)
# ======================================================================
class RoutingEngine:
    def __init__(self, cfg):
        self.routing_cfg = cfg.get("routing", {})
        self.direct_enabled = self.routing_cfg.get("direct_iran", False)
        self.geoip_url = self.routing_cfg.get("geoip_url", "https://raw.githubusercontent.com/v2fly/geoip/release/text/ir.txt")
        self.geosite_base_url = self.routing_cfg.get("geosite_base_url", "https://raw.githubusercontent.com/v2fly/domain-list-community/refs/heads/master/data/")
        self.geosite_main = self.routing_cfg.get("geosite_main_category", "category-ir")

        self.ir_domains = set()
        self.ir_cidrs = []

        if self.direct_enabled:
            self._update_and_load_rules()

    def _fetch_geosite_recursive(self, category_name, visited=None):
        if visited is None:
            visited = set()
        if category_name in visited:
            return []
        visited.add(category_name)

        domains = []
        try:
            url = self.geosite_base_url + category_name
            print(f"   [-] Fetching GeoSite segment: [{category_name}]...")
            r = requests.get(url, timeout=10)
            if r.status_code == 200:
                for line in r.text.split('\n'):
                    line = line.strip()
                    if not line or line.startswith('#') or line.startswith('@'):
                        continue

                    if line.startswith('include:'):
                        sub_cat = line.split(':')[1].strip()
                        domains.extend(self._fetch_geosite_recursive(sub_cat, visited))
                    else:
                        domain = line.split()[0].split('@')[0]
                        if domain.startswith('full:'):
                            domain = domain[5:]
                        elif domain.startswith('domain:'):
                            domain = domain[7:]
                        if domain:
                            domains.append(domain.lower())
        except Exception as e:
            print(f"   [!] Failed to fetch {category_name}: {e}")
        return domains

    def _update_and_load_rules(self):
        print("[*] Checking routing rules (GeoIP & GeoSite)...")
        if not os.path.exists("geoip_ir.txt"):
            try:
                print("   [-] Downloading GeoIP (IR)...")
                r = requests.get(self.geoip_url, timeout=15)
                if r.status_code == 200:
                    with open("geoip_ir.txt", "w", encoding="utf-8") as f:
                        f.write(r.text)
            except Exception as e:
                print(f"   [!] Failed to download GeoIP: {e}")

        if not os.path.exists("geosite_ir.txt"):
            print("   [*] Compiling modular GeoSite list from repositories...")
            all_domains = self._fetch_geosite_recursive(self.geosite_main)
            if all_domains:
                unique_domains = sorted(list(set(all_domains)))
                with open("geosite_ir.txt", "w", encoding="utf-8") as f:
                    f.write("\n".join(unique_domains))
                print(f"   [+] Successfully compiled {len(unique_domains)} domains.")

        try:
            if os.path.exists("geosite_ir.txt"):
                with open("geosite_ir.txt", "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if line:
                            self.ir_domains.add(line.lower())

            if os.path.exists("geoip_ir.txt"):
                with open("geoip_ir.txt", "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if line and not line.startswith("#"):
                            try:
                                self.ir_cidrs.append(ipaddress.ip_network(line))
                            except Exception:
                                pass

            if self.ir_domains or self.ir_cidrs:
                print(f"[+] Routing engine active: loaded {len(self.ir_cidrs)} subnets & {len(self.ir_domains)} domains.")
            else:
                print("[!] Routing files are empty. Defaulting all traffic to the relay.")
                self.direct_enabled = False
        except Exception as e:
            print(f"[!] Routing engine error: {e}. Defaulting all traffic to the relay.")
            self.direct_enabled = False

    def is_direct(self, host):
        """Domains/subnets resolved locally must not touch the relay nodes."""
        if not self.direct_enabled:
            return False
        host = host.lower()
        if host.endswith(".ir"):
            return True
        if host in self.ir_domains:
            return True

        parts = host.split(".")
        for i in range(len(parts) - 1):
            if ".".join(parts[i:]) in self.ir_domains:
                return True

        try:
            ip = ipaddress.ip_address(host.strip("[]"))
            for net in self.ir_cidrs:
                if ip in net:
                    return True
        except ValueError:
            pass
        return False

# ======================================================================
# 2. Node management (round-robin, fallback, edge-cookie priming)
# ======================================================================
class ServerManager:
    """Round-robin across nodes; every node keeps its address + optional
    fallback address (CMS path) and autonomously switches to the fallback
    after repeated hard failures (404/timeout/network) — self-healing."""

    FAIL_LIMIT = 3

    def __init__(self, cfg):
        self.nodes = []
        if "servers" in cfg and isinstance(cfg["servers"], list):
            raw = cfg["servers"]
        elif "server_url" in cfg and "auth_token" in cfg:
            raw = [{"url": cfg["server_url"], "token": cfg["auth_token"]}]
        else:
            raw = []
        for s in raw:
            if not isinstance(s, dict) or not s.get("url") or not s.get("token"):
                continue
            self.nodes.append({
                "url": s["url"].rstrip("/"),
                "fallback": (s.get("fallback_url") or "").rstrip("/"),
                "token": s["token"],
                "active": s["url"].rstrip("/"),
                "fails": 0,
            })

        if not self.nodes:
            print("[!] ERROR: no nodes defined in config.json")
            exit(1)

        self.sessions = {}
        self.lock = threading.Lock()
        for n in self.nodes:
            self.sessions[n["active"]] = self._build_session(n["token"], cfg, n["active"])

        self._cycle = itertools.cycle(range(len(self.nodes)))

        # Keep the edge cookies alive: the free-hosting challenges expire
        # after ~6h. One cheap probe per node per hour re-solves them in the
        # background so long-running cores never degrade to challenge HTML.
        self._edge_refresh_stop = False
        threading.Thread(target=self._edge_refresh_loop, daemon=True).start()

    def _build_session(self, token, cfg, base_url=None):
        session = requests.Session()
        session.trust_env = False
        session.proxies = {"http": "", "https": ""}
        session.headers.update({
            # Full browser identity: hosting edges have been observed to
            # drop non-browser requests at the TLS layer outright, and to
            # hard-403 the CMS's own request identity.
            "User-Agent": EdgeGuard.BROWSER_UA,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
            # Identity transport: intermediaries (LiteSpeed, CDN edges) must
            # NOT compress the stream — compressed responses buffer in chunks
            # and stall interactive TLS handshakes.
            "Accept-Encoding": "identity",
            "Connection": "keep-alive"
        })
        retry_strategy = Retry(total=CFG.get('max_retries', 2), backoff_factor=0.3,
                               status_forcelist=[429, 500, 502, 503, 504])
        adapter = EdgeGuardAdapter(pool_connections=min(10, CFG.get('max_pool_connections', 10)),
                                   pool_maxsize=CFG.get('max_pool_connections', 10),
                                   max_retries=retry_strategy)
        session.mount('https://', adapter)
        session.mount('http://', adapter)
        if base_url:
            self._prime_session(session, base_url)
        return session

    def _prime_session(self, session, base_url):
        """One cheap request that triggers (and solves) the edge's JS-cookie
        challenge if one is in the way, parking the cookie in the jar before
        any wire traffic flows — the long-lived stream reads then start
        clean."""
        try:
            r = session.get(base_url, stream=True, timeout=(CONNECT_TIMEOUT, 10))
            try:
                head = b''
                for chunk in r.iter_content(4096):
                    head += chunk
                    if len(head) > 16384:
                        break
            finally:
                r.close()
            if EdgeGuard.looks_like(head):
                value = EdgeGuard.solve(head)
                if value:
                    EdgeGuard.set_cookie(session, base_url, value)
                    print(f"[+] Solved the hosting edge's JS-cookie challenge for {base_url}")
                    return value
        except Exception:
            pass
        return None

    def _edge_refresh_loop(self):
        while not self._edge_refresh_stop:
            time.sleep(3600)
            with self.lock:
                items = list(self.sessions.items())
            for url, sess in items:
                self._prime_session(sess, url)

    def _session_for(self, url):
        sess = self.sessions.get(url)
        if sess is None:
            # Token rides the obfuscated envelope, not headers — any node's
            # session builder is identical.
            sess = self._build_session("", CFG, url)
            self.sessions[url] = sess
        return sess

    def get_next_node(self):
        """Returns (active_url, token, session) of the next healthy node."""
        with self.lock:
            node = self.nodes[next(self._cycle)]
            url = node["active"]
        return url, node["token"], self._session_for(url)

    def get_all_nodes(self):
        return self.nodes

    def report_success(self, url):
        with self.lock:
            for n in self.nodes:
                if n["active"] == url:
                    n["fails"] = 0

    def report_failure(self, url):
        """Hard failure on the active address: after FAIL_LIMIT strikes the
        node transparently moves to its fallback address (CMS path)."""
        swapped = False
        with self.lock:
            for n in self.nodes:
                if n["active"] != url:
                    continue
                n["fails"] += 1
                if n["fails"] >= self.FAIL_LIMIT and n["fallback"] and n["fallback"] != n["active"]:
                    old = n["active"]
                    n["active"] = n["fallback"]
                    n["fails"] = 0
                    swapped = True
                    print(f"[*] Node {old} failing repeatedly -> switching to fallback {n['active']}")
        return swapped

# ======================================================================
# 3. Telemetry, watchdogs and console dashboard
# ======================================================================
class CoreTelemetry:
    def __init__(self, server_manager):
        self.lock = threading.Lock()
        self.server_manager = server_manager
        self.total_connections = 0
        self.queued_connections = 0
        self.successful_tunnels = 0
        self.direct_connections = 0  # locally routed connections
        self.failed_tunnels = 0
        self.total_bytes_sent = 0
        self.total_bytes_received = 0
        self.active_tunnels = {}
        self.error_log = []
        self.dynamic_delay = 0.0

        with open("serverlog.txt", "w", encoding="utf-8") as log:
            log.write(f"--- System Core Log Started at {time.strftime('%Y-%m-%d %H:%M:%S')} ---\n")

        threading.Thread(target=self.tunnel_watchdog_loop, daemon=True).start()
        threading.Thread(target=self.session_traffic_watchdog_loop, daemon=True).start()

    def _write_to_serverlog(self, err_msg):
        try:
            with open("serverlog.txt", "a", encoding="utf-8") as log:
                log.write(f"[{time.strftime('%H:%M:%S')}] {err_msg}\n")
        except Exception:
            pass

    def add_error(self, err_msg):
        self._write_to_serverlog(err_msg)
        with self.lock:
            self.error_log.append(f"[{time.strftime('%H:%M:%S')}] {err_msg}")
            if len(self.error_log) > 5:
                self.error_log.pop(0)

    def increase_delay(self, amount=0.5):
        with self.lock:
            self.dynamic_delay = min(MAX_DELAY, self.dynamic_delay + amount)

    def decrease_delay(self, amount=0.05):
        with self.lock:
            self.dynamic_delay = max(0.0, self.dynamic_delay - amount)

    def add_queued(self):
        with self.lock:
            self.total_connections += 1
            self.queued_connections += 1

    def add_direct(self):
        with self.lock:
            self.total_connections += 1
            self.direct_connections += 1

    def register_tunnel(self, t_id, host, port, client_socket, session, srv_url, protocol):
        with self.lock:
            if self.queued_connections > 0:
                self.queued_connections -= 1
            self.successful_tunnels += 1
            current_time = time.time()
            self.active_tunnels[t_id] = {
                "host": f"{host}:{port} [{protocol.upper()}]",
                "tx": 0, "rx": 0, "start": current_time, "last_active": current_time,
                "last_tx": 0, "last_rx": 0, "last_speed_check": current_time,
                "is_vip": False, "socket": client_socket, "session": session, "srv_url": srv_url
            }

    def mark_failed_queue(self):
        with self.lock:
            if self.queued_connections > 0:
                self.queued_connections -= 1
            self.failed_tunnels += 1

    def increment_fail(self):
        with self.lock:
            self.failed_tunnels += 1

    def update_tx(self, t_id, bytes_count):
        with self.lock:
            self.total_bytes_sent += bytes_count
            if t_id in self.active_tunnels:
                self.active_tunnels[t_id]["tx"] += bytes_count
                self.active_tunnels[t_id]["last_active"] = time.time()

    def update_rx(self, t_id, bytes_count):
        with self.lock:
            self.total_bytes_received += bytes_count
            if t_id in self.active_tunnels:
                self.active_tunnels[t_id]["rx"] += bytes_count
                self.active_tunnels[t_id]["last_active"] = time.time()

    def remove_tunnel(self, t_id):
        with self.lock:
            if t_id in self.active_tunnels:
                del self.active_tunnels[t_id]

    def format_bytes(self, size):
        for unit in ['B', 'KB', 'MB', 'GB']:
            if size < 1024.0:
                return f"{size:.2f} {unit}"
            size /= 1024.0
        return f"{size:.2f} TB"

    def tunnel_watchdog_loop(self):
        """Dual watchdog: idle kill, uptime kill, VIP immunity for busy pipes."""
        while True:
            time.sleep(2)
            now = time.time()
            to_kill = []
            with self.lock:
                for t_id, data in list(self.active_tunnels.items()):
                    idle_time = now - data["last_active"]
                    time_since_last_check = now - data.get("last_speed_check", now)
                    if time_since_last_check >= 1.0:
                        speed_bps = ((data["tx"] - data.get("last_tx", 0)) + (data["rx"] - data.get("last_rx", 0))) / time_since_last_check
                        if speed_bps >= ACTIVE_TRANSFER_THRESHOLD:
                            data["start"] = now
                            data["is_vip"] = True
                        else:
                            data["is_vip"] = False
                        data["last_tx"] = data["tx"]
                        data["last_rx"] = data["rx"]
                        data["last_speed_check"] = now

                    if idle_time > IDLE_TIMEOUT:
                        to_kill.append((t_id, data, f"Idle ({int(idle_time)}s)"))
                    elif (now - data["start"]) > MAX_UPTIME:
                        to_kill.append((t_id, data, f"Uptime ({int(now - data['start'])}s)"))

            for t_id, data, reason in to_kill:
                try:
                    data["socket"].close()
                except Exception:
                    pass
                try:
                    data["session"].close()
                except Exception:
                    pass
                self.remove_tunnel(t_id)

    def _notify_server_session_clear(self, old_sess_id):
        for n in self.server_manager.get_all_nodes():
            meta = {"a": "c", "s": old_sess_id, "t": "", "k": n["token"]}
            b64_meta = base64.b64encode(json.dumps(meta).encode()).decode()[::-1]
            try:
                requests.get(f"{n['active']}?_v={urllib.parse.quote(b64_meta, safe='')}",
                             timeout=(CONNECT_TIMEOUT, 8), verify=False,
                             headers={"Accept-Encoding": "identity"})
            except Exception:
                pass

    def session_traffic_watchdog_loop(self):
        """Rotates the session envelope on low traffic so stale state never
        locks the pool behind a dead queue."""
        global SESSION_ID
        last_checked_traffic = 0
        while True:
            time.sleep(LOW_TRAFFIC_TIMEOUT)
            with self.lock:
                current_total_traffic = self.total_bytes_sent + self.total_bytes_received
                if (current_total_traffic - last_checked_traffic) < LOW_TRAFFIC_THRESHOLD_BYTES:
                    old_session = SESSION_ID
                    SESSION_ID = uuid.uuid4().hex[:12]
                    threading.Thread(target=self._notify_server_session_clear, args=(old_session,), daemon=True).start()
                    tunnels_to_abort = list(self.active_tunnels.items())
                    self.active_tunnels.clear()
                else:
                    tunnels_to_abort = []
                last_checked_traffic = current_total_traffic

            for t_id, data in tunnels_to_abort:
                try:
                    data["socket"].close()
                except Exception:
                    pass

    def print_dashboard(self):
        while True:
            time.sleep(2)
            with self.lock:
                active_count = len(self.active_tunnels)
                os.system('cls' if os.name == 'nt' else 'clear')
                print("=" * 75)
                print(f"[CORE] ULTIMATE CORE HUB (SOCKS5/HTTP + ROUTING) | Env: {SESSION_ID}")
                print("=" * 75)
                print(f"[>] Handled: {self.total_connections:<7} | Queue: {self.queued_connections:<5} | [IR] Direct Route: {self.direct_connections}")
                print(f"[+] Synced:  {self.successful_tunnels:<7} | Drops: {self.failed_tunnels}")
                print(f"[^] Total UL: {self.format_bytes(self.total_bytes_sent):<10} | Total DL: {self.format_bytes(self.total_bytes_received)}")
                print(f"[*] Adaptive Delay: {self.dynamic_delay:.2f}s (Max: {MAX_DELAY}s)")
                print(f"[#] Core Limits -> Idle: {IDLE_TIMEOUT}s | Max Uptime: {MAX_UPTIME}s | VIP: >{CFG.get('active_transfer_threshold_kbps', 10.0)} KB/s")
                print("-" * 75)

                node_loads = {}
                for data in self.active_tunnels.values():
                    domain = data["srv_url"].split('/')[2] if '/' in data["srv_url"] else data["srv_url"]
                    node_loads[domain] = node_loads.get(domain, 0) + 1

                if node_loads:
                    print("[NET] Active nodes load distribution:")
                    for domain, count in node_loads.items():
                        print(f"   -> {domain}: {count} active channels")
                    print("-" * 75)

                print(f"[OK] Active channels ({active_count} / {CFG.get('max_pool_connections', 10)}):")
                if active_count == 0:
                    print("   [System is idle]")
                else:
                    for tid, data in list(self.active_tunnels.items())[:8]:
                        uptime = int(time.time() - data['start'])
                        icon = "[VIP]" if data.get("is_vip") else "  ->"
                        print(f" {icon} {data['host'][:26]:<26} | UP: {uptime}s | UL: {self.format_bytes(data['tx']):<6} | DL: {self.format_bytes(data['rx'])}")
                print("-" * 75)
                print("[!] Diagnostics:")
                if not self.error_log:
                    print("   [All systems nominal]")
                else:
                    for err in self.error_log:
                        wrapped = textwrap.wrap(err, width=70)
                        print(f" [X] {wrapped[0]}")
                        for extra in wrapped[1:]:
                            print(f"    {extra}")
                print("=" * 75)

# ======================================================================
# 4. Connection core (HTTP + SOCKS5, TCP/UDP encapsulation, IPv6)
# ======================================================================
class StatefulRelayCore:
    def __init__(self):
        self.router = RoutingEngine(CFG)
        self.server_manager = ServerManager(CFG)
        self.stats = CoreTelemetry(self.server_manager)
        self.pool_size = CFG.get('max_pool_connections', 10)
        self.connection_semaphore = threading.Semaphore(self.pool_size)
        self._last_dnsleak_warn = 0.0
        threading.Thread(target=self.stats.print_dashboard, daemon=True).start()

    def _encode_meta(self, action, tunnel_id, token, host="", port=80, protocol="tcp"):
        meta = {"a": action, "s": SESSION_ID, "t": tunnel_id, "h": host, "p": port, "k": token, "net": protocol}
        return base64.b64encode(json.dumps(meta).encode()).decode('utf-8')[::-1]

    def _parse_target(self, target_str, default_port):
        target_str = target_str.strip()
        if target_str.startswith("["):
            parts = target_str.split("]")
            host = parts[0][1:]
            port = default_port
            if len(parts) > 1 and parts[1].startswith(":"):
                try:
                    port = int(parts[1][1:])
                except Exception:
                    pass
            return host, port
        else:
            if ":" in target_str:
                host, port_str = target_str.rsplit(":", 1)
                try:
                    port = int(port_str)
                except Exception:
                    port = default_port
                return host, port
            else:
                return target_str, default_port

    def _setup_direct_connection(self, client_socket, host, port, initial_data=None):
        """Locally routed connection (GeoSite/GeoIP match)."""
        self.stats.add_direct()
        remote_socket = socket.socket(socket.AF_INET6 if ":" in host else socket.AF_INET, socket.SOCK_STREAM)
        remote_socket.settimeout(10)
        try:
            remote_socket.connect((host, port))
            if initial_data:
                remote_socket.sendall(initial_data)

            def relay(src, dst):
                try:
                    while True:
                        data = src.recv(16384)
                        if not data:
                            break
                        dst.sendall(data)
                except Exception:
                    pass
                finally:
                    try:
                        src.close()
                    except Exception:
                        pass
                    try:
                        dst.close()
                    except Exception:
                        pass

            threading.Thread(target=relay, args=(client_socket, remote_socket), daemon=True).start()
            threading.Thread(target=relay, args=(remote_socket, client_socket), daemon=True).start()

        except Exception:
            try:
                client_socket.close()
            except Exception:
                pass

    def _recover_edge_challenge(self, response, session, srv_url, stream_url):
        """A live stream answer is never short + framed: that shape is the
        edge's JS-cookie challenge (stale cookie mid-session). Solve it and
        re-open the stream once instead of feeding HTML into the tunnel."""
        try:
            cl = int(response.headers.get('Content-Length', '-1'))
        except (TypeError, ValueError):
            return response
        if cl < 0 or cl > 4096:
            return response
        try:
            body = response.content
        except Exception:
            return response
        if not EdgeGuard.looks_like(body):
            return response
        value = EdgeGuard.solve(body)
        response.close()
        if not value:
            return response
        EdgeGuard.set_cookie(session, srv_url, value)
        print(f"[+] Re-solved a stale edge challenge on {srv_url}")
        try:
            return session.get(stream_url, stream=True,
                               timeout=(CONNECT_TIMEOUT, STREAM_READ_TIMEOUT), verify=False)
        except Exception:
            return response

    def php_reader_loop(self, session, srv_url, srv_token, tunnel_id, host, port, client_socket, protocol="tcp"):
        try:
            obfuscated_param = self._encode_meta("t", tunnel_id, srv_token, host, port, protocol)
            stream_url = f"{srv_url}?_v={urllib.parse.quote(obfuscated_param, safe='')}"

            # 503 = the node's channel cap (host guard): the node is BUSY, not
            # broken. One polite retry after the advertised cool-down, and
            # never a strike that would swap the node to its fallback (that
            # would just double the load on the same host).
            response = session.get(stream_url, stream=True,
                                   timeout=(CONNECT_TIMEOUT, STREAM_READ_TIMEOUT), verify=False)
            if response.status_code == 503:
                self.stats.increase_delay(1.0)
                retry_after = 3.0
                try:
                    retry_after = min(10.0, float(response.headers.get("Retry-After", 3)))
                except (TypeError, ValueError):
                    pass
                time.sleep(retry_after)
                response = session.get(stream_url, stream=True,
                                       timeout=(CONNECT_TIMEOUT, STREAM_READ_TIMEOUT), verify=False)

            if response.status_code == 200:
                # Short framed answers on a stream URL are the edge's cookie
                # challenge — solve and re-open before any tunnel byte flows.
                response = self._recover_edge_challenge(response, session, srv_url, stream_url)

            if response.status_code == 200:
                self.server_manager.report_success(srv_url)
                self.stats.register_tunnel(tunnel_id, host, port, client_socket, session, srv_url, protocol)
                self.stats.decrease_delay(0.05)

                for line in response.iter_lines():
                    if not line or not line.strip():
                        continue
                    try:
                        raw_bytes = base64.b64decode(line.decode('utf-8')[::-1])
                        if raw_bytes.startswith(b"ERR_ST_CONN"):
                            self.stats.increment_fail()
                            self.stats.add_error(f"[Hub Reject] {host}:{port} -> {raw_bytes.decode('utf-8', errors='ignore')}")
                            break
                        client_socket.sendall(raw_bytes)
                        self.stats.update_rx(tunnel_id, len(raw_bytes))
                    except Exception:
                        break
            elif response.status_code == 404:
                self.server_manager.report_failure(srv_url)
                self.stats.mark_failed_queue()
                self.stats.add_error(f"[Node Reject] 404 on {srv_url} (wrong token or endpoint unreachable)")
            elif response.status_code == 503:
                # Still busy after the retry — back off without punishing the node.
                self.stats.mark_failed_queue()
                self.stats.add_error(f"[Node Busy] 503 channel cap on {srv_url} — node is at its concurrent-channel limit")
            else:
                self.server_manager.report_failure(srv_url)
                self.stats.mark_failed_queue()
                self.stats.increase_delay(0.5)
                self.stats.add_error(f"[Hub Load] HTTP {response.status_code} on {srv_url}")
        except Exception as e:
            self.server_manager.report_failure(srv_url)
            self.stats.mark_failed_queue()
            if "Pool" not in str(e):
                self.stats.add_error(f"[Network] {type(e).__name__} on {srv_url}")
        finally:
            self.stats.remove_tunnel(tunnel_id)
            try:
                client_socket.close()
            except Exception:
                pass

    def _setup_encapsulated_tunnel(self, client_socket, tunnel_id, host, port, protocol, initial_data=None):
        """Connection carried inside HTTP exchanges (TCP/UDP over HTTP)."""
        self.stats.add_queued()
        req_sess_id = SESSION_ID

        self.connection_semaphore.acquire()
        try:
            srv_url, srv_token, srv_sess = self.server_manager.get_next_node()

            if req_sess_id != SESSION_ID:
                self.stats.mark_failed_queue()
                return

            if self.stats.dynamic_delay > 0:
                time.sleep(self.stats.dynamic_delay)

            threading.Thread(
                target=self.php_reader_loop,
                args=(srv_sess, srv_url, srv_token, tunnel_id, host, int(port), client_socket, protocol),
                daemon=True,
            ).start()

            target_url = f"{srv_url}?_v={urllib.parse.quote(self._encode_meta('w', tunnel_id, srv_token, protocol=protocol), safe='')}"

            if initial_data:
                self.stats.update_tx(tunnel_id, len(initial_data))
                srv_sess.post(target_url, data=base64.b64encode(initial_data).decode('utf-8')[::-1],
                              timeout=(CONNECT_TIMEOUT, WRITE_TIMEOUT), verify=False)

            client_socket.settimeout(120)
            while True:
                data = client_socket.recv(16384)
                if not data:
                    break
                self.stats.update_tx(tunnel_id, len(data))
                srv_sess.post(target_url, data=base64.b64encode(data).decode('utf-8')[::-1],
                              timeout=(CONNECT_TIMEOUT, WRITE_TIMEOUT), verify=False)

        finally:
            self.connection_semaphore.release()
            try:
                client_socket.close()
            except Exception:
                pass

    def _handle_http(self, client_socket, request_bytes, tunnel_id):
        first_line = request_bytes.decode("utf-8", errors="ignore").split("\n")[0]
        host, port = None, 80
        is_connect = "CONNECT" in first_line

        if is_connect:
            host, port = self._parse_target(first_line.split(" ")[1], 443)
        else:
            for line in request_bytes.decode("utf-8", errors="ignore").split('\r\n'):
                if line.lower().startswith("host:"):
                    host, port = self._parse_target(line.split(":", 1)[1], 80)
                    break

        if host:
            if self.router.is_direct(host):
                if is_connect:
                    client_socket.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
                self._setup_direct_connection(client_socket, host, port, initial_data=(None if is_connect else request_bytes))
            else:
                if is_connect:
                    client_socket.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
                self._setup_encapsulated_tunnel(client_socket, tunnel_id, host, port, protocol="tcp", initial_data=(None if is_connect else request_bytes))

    def _handle_socks5(self, client_socket, request_bytes, tunnel_id):
        client_socket.sendall(b"\x05\x00")
        request = client_socket.recv(1024)
        if not request or request[0] != 5:
            return

        cmd, atyp = request[1], request[3]
        offset = 4

        if atyp == 1:
            host = socket.inet_ntoa(request[offset:offset + 4])
            offset += 4
            # DNS leak: the browser resolved the name LOCALLY and sent a raw
            # IP. For filtered domains the local ISP resolver answers with a
            # poisoned address, and the tunnel then faithfully connects to
            # that dead IP — the classic "BBC opens in the viewer but not
            # through the core" report. Recommend the hostname-preserving
            # modes (throttled to once a minute).
            now = time.time()
            if now - self._last_dnsleak_warn > 60:
                self._last_dnsleak_warn = now
                print("[!] DNS leak: browser sent a raw IP (local resolution) — for filtered sites connect the browser in HTTP mode (not SOCKS5) to 127.0.0.1:%d so domain names reach the node directly, or enable the Firefox option that routes DNS through SOCKS v5." % RELAY_PORT)
        elif atyp == 3:
            domain_len = request[offset]
            offset += 1
            host = request[offset:offset + domain_len].decode('utf-8')
            offset += domain_len
        elif atyp == 4:
            host = socket.inet_ntop(socket.AF_INET6, request[offset:offset + 16])
            offset += 16

        port = struct.unpack('>H', request[offset:offset + 2])[0]

        if cmd == 1:  # TCP CONNECT
            client_socket.sendall(b"\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00")
            if self.router.is_direct(host):
                self._setup_direct_connection(client_socket, host, port)
            else:
                self._setup_encapsulated_tunnel(client_socket, tunnel_id, host, port, protocol="tcp")

        elif cmd == 3:  # UDP ASSOCIATE
            client_socket.sendall(b"\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00")
            if self.router.is_direct(host):
                self._setup_direct_connection(client_socket, host, port)
            else:
                self._setup_encapsulated_tunnel(client_socket, tunnel_id, host, port, protocol="udp")

    def handle_client(self, client_socket, client_address):
        tunnel_id = str(uuid.uuid4())
        try:
            first_chunk = client_socket.recv(16384)
            if not first_chunk:
                return

            if first_chunk[0] == 5:
                self._handle_socks5(client_socket, first_chunk, tunnel_id)
            else:
                self._handle_http(client_socket, first_chunk, tunnel_id)

        except Exception:
            try:
                client_socket.close()
            except Exception:
                pass

    def start_core(self):
        try:
            server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            server.bind(("0.0.0.0", RELAY_PORT))
            server.listen(1000)
            os.system('cls' if os.name == 'nt' else 'clear')
            print(f"[+] Secure core (SOCKS5/HTTP + split routing) started on port {RELAY_PORT}...")
            while True:
                client_sock, addr = server.accept()
                threading.Thread(target=self.handle_client, args=(client_sock, addr), daemon=True).start()
        except Exception as e:
            print(f"\n[!] FATAL CORE ERROR: {e}")
            input("Press Enter...")

if __name__ == "__main__":
    client = StatefulRelayCore()
    client.start_core()
