<?php

namespace Sanar\WCProductScheduler\Util;

use Sanar\WCProductScheduler\Plugin;

class Logger {
    public static function info( string $event, array $context = [] ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        self::log_system_event( $event, $context );
    }

    public static function log_event( int $revision_id, string $event, array $context = [] ): void {
        $log = get_post_meta( $revision_id, Plugin::META_LOG, true );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        $log[] = [
            'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ),
            'event' => $event,
            'context' => $context,
        ];

        update_post_meta( $revision_id, Plugin::META_LOG, $log );
    }

    public static function set_error( int $revision_id, string $message ): void {
        update_post_meta( $revision_id, Plugin::META_ERROR, $message );
    }

    public static function log_system_event( string $event, array $context = [] ): void {
        $log = get_option( 'sanar_wcps_system_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        $log[] = [
            'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ),
            'event' => $event,
            'context' => $context,
        ];

        update_option( 'sanar_wcps_system_log', $log, false );
    }
}
