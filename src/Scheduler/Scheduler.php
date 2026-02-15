<?php

namespace Sanar\WCProductScheduler\Scheduler;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Util\Logger;

class Scheduler {
    public static function init(): void {
        // Deprecated: kept only for backward compatibility.
    }

    public static function schedule_revision( int $revision_id, int $timestamp ): bool {
        if ( $revision_id <= 0 ) {
            return false;
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, gmdate( 'Y-m-d H:i:s', $timestamp ) );
        Logger::log_event( $revision_id, 'scheduled', [ 'scheduled_utc' => gmdate( 'Y-m-d H:i:s', $timestamp ) ] );
        return true;
    }

    public static function clear_scheduled_revision( int $revision_id ): void {
        if ( $revision_id <= 0 ) {
            return;
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_CANCELLED );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME );
    }

    public static function handle_publish( int $revision_id ): void {
        // Deprecated: publication now runs only through WP-CLI runner.
    }
}
