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
    'glpe_db_version',
];

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
