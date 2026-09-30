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
];

foreach ($glpe_options as $glpe_option) {
    delete_option($glpe_option);
}

if ($glpe_page_id) {
    wp_delete_post((int)$glpe_page_id, true);
}
