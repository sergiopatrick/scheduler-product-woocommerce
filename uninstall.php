<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$revision_type = 'sanar_product_revision';

$hook = 'sanar_wcps_publish_revision';
if ( function_exists( '_get_cron_array' ) ) {
    $cron = _get_cron_array();
    if ( is_array( $cron ) ) {
        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks[ $hook ] ) ) {
                continue;
            }
            foreach ( $hooks[ $hook ] as $event ) {
                $args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : [];
                wp_unschedule_event( $timestamp, $hook, $args );
            }
        }
    }
}

$revisions = get_posts( [
    'post_type' => $revision_type,
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
] );

foreach ( $revisions as $revision_id ) {
    wp_delete_post( $revision_id, true );
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sanar_wcps_lock_%'" );
