<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$revision_types = [ 'sanar_product_revisi', 'sanar_product_revision' ];
$revisions = get_posts( [
    'post_type' => $revision_types,
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
] );

foreach ( $revisions as $revision_id ) {
    wp_delete_post( $revision_id, true );
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sanar_wcps_lock_%'" );
