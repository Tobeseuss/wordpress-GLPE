<?php
/**
 * GLPE Viewer — clean uninstall.
 * Removes all plugin options and the generated viewer page.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$glpe_page_id = get_option('glpe_page_id');

$glpe_options = [
    'glpe_secret',
    'glpe_page_id',
    'glpe_slug',
    'glpe_rewrite_links',
    'glpe_toolbar',
    'glpe_blank_title',
    'glpe_no_scripts',
    'glpe_no_images',
    'glpe_ssl_verify',
    'glpe_hub_enabled',
    'glpe_hub_token',
    'glpe_hub_cfg',
    'glpe_hub_nodes',
    'glpe_hub_tuning',
    'glpe_hub_max_streams',
    'glpe_dns_mode',
    'glpe_dns_ttl',
    'glpe_doh_endpoint',
    'glpe_fetch_connect_timeout',
    'glpe_fetch_timeout',
    'glpe_fetch_retries',
    'glpe_ipresolve_v4',
    'glpe_db_version',
    'glpe_diag_enabled',
    'glpe_diag_key',
    'glpe_diag_enabled_at',
    'glpe_vb_key',
    'glpe_vb_port',
    'glpe_vb_endpoint',
    'glpe_vb_engine',
    'glpe_vb_vbs_index',
    'glpe_path_entry',
    'glpe_res_cache',
];

// Virtual browser sandbox sessions: reap the live temporary environments
// (their transient records) alongside the options themselves.
$glpe_vbs_index = get_option('glpe_vb_vbs_index');
if (is_array($glpe_vbs_index)) {
    foreach (array_keys($glpe_vbs_index) as $glpe_vbs_token) {
        if (is_string($glpe_vbs_token) && $glpe_vbs_token !== '') {
            delete_transient('glpe_vbs_' . $glpe_vbs_token);
        }
    }
}
// Sandbox jar records inside the current session are gone with the session
// itself — nothing more to do here.

foreach ($glpe_options as $glpe_option) {
    delete_option($glpe_option);
}

// Remove the viewer permission (glpe_browser) from every role, administrator included.
if (function_exists('wp_roles')) {
    foreach (wp_roles()->get_names() as $glpe_role_name => $glpe_role_label) {
        $glpe_role = get_role($glpe_role_name);
        if ($glpe_role && $glpe_role->has_cap('glpe_browser')) {
            $glpe_role->remove_cap('glpe_browser');
        }
    }
}

// Remove per-user permission overrides (both grants and explicit denies)
// and the per-account session snapshots.
foreach (get_users(['fields' => ['ID']]) as $glpe_user_ref) {
    delete_user_meta($glpe_user_ref->ID, '_glpe_account_jar');
    $glpe_user = get_userdata($glpe_user_ref->ID);
    if ($glpe_user && array_key_exists('glpe_browser', (array)$glpe_user->caps)) {
        $glpe_user->remove_cap('glpe_browser');
    }
}

if ($glpe_page_id) {
    wp_delete_post((int)$glpe_page_id, true);
}

// Relay core: wipe the stored-chunk directory (chunk files, metrics,
// vacuum lock) — never leave tunnel traffic on the disk.
$glpe_hub_dir = wp_normalize_path(trailingslashit((string)wp_upload_dir()['basedir']) . 'glpe-relay-tmp');
if (is_dir($glpe_hub_dir)) {
    foreach ((array)scandir($glpe_hub_dir) as $glpe_hub_node) {
        if ($glpe_hub_node === '.' || $glpe_hub_node === '..') {
            continue;
        }
        $glpe_hub_path = $glpe_hub_dir . '/' . $glpe_hub_node;
        if (is_file($glpe_hub_path)) {
            @unlink($glpe_hub_path);
        }
    }
    @rmdir($glpe_hub_dir);
}

// Diagnostics + DNS store directory (guarded journals and resolve cache).
$glpe_diag_dir = wp_normalize_path(trailingslashit((string)wp_upload_dir()['basedir']) . 'glpe-viewer-tmp');
if (is_dir($glpe_diag_dir)) {
    foreach ((array)scandir($glpe_diag_dir) as $glpe_diag_node) {
        if ($glpe_diag_node === '.' || $glpe_diag_node === '..') {
            continue;
        }
        $glpe_diag_path = $glpe_diag_dir . '/' . $glpe_diag_node;
        if (is_file($glpe_diag_path)) {
            @unlink($glpe_diag_path);
        }
    }
    @rmdir($glpe_diag_dir);
}

// Subresource artifact cache (v7.3.0): every stored entry leaves the disk.
$glpe_res_dir = wp_normalize_path(trailingslashit((string)wp_upload_dir()['basedir']) . 'glpe-res-cache');
if (is_dir($glpe_res_dir)) {
    foreach ((array)scandir($glpe_res_dir) as $glpe_res_node) {
        if ($glpe_res_node === '.' || $glpe_res_node === '..') {
            continue;
        }
        $glpe_res_path = $glpe_res_dir . '/' . $glpe_res_node;
        if (is_file($glpe_res_path)) {
            @unlink($glpe_res_path);
        }
    }
    @rmdir($glpe_res_dir);
}
