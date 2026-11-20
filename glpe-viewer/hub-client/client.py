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
# 2. Node management (multi-node round-robin + failover)
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
            self.sessions[n["active"]] = self._build_session(n["token"], cfg)

        self._cycle = itertools.cycle(range(len(self.nodes)))

    def _build_session(self, token, cfg):
        session = requests.Session()
        session.trust_env = False
        session.proxies = {"http": "", "https": ""}
        session.headers.update({
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Accept": "*/*",
            # Identity transport: intermediaries (LiteSpeed, CDN edges) must
            # NOT compress the stream — compressed responses buffer in chunks
            # and stall interactive TLS handshakes.
            "Accept-Encoding": "identity",
            "Connection": "keep-alive"
        })
        retry_strategy = Retry(total=CFG.get('max_retries', 2), backoff_factor=0.3,
                               status_forcelist=[429, 500, 502, 503, 504])
        adapter = HTTPAdapter(pool_connections=min(10, CFG.get('max_pool_connections', 10)),
                              pool_maxsize=CFG.get('max_pool_connections', 10),
                              max_retries=retry_strategy)
        session.mount('https://', adapter)
        session.mount('http://', adapter)
        return session

    def _session_for(self, url):
        sess = self.sessions.get(url)
        if sess is None:
            # Token rides the obfuscated envelope, not headers — any node's
            # session builder is identical.
            sess = self._build_session("", CFG)
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
