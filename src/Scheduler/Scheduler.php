<?php

namespace Sanar\WCProductScheduler\Scheduler;

use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Plugin;

class Scheduler {
    public static function init(): void {
        add_action( Plugin::ACTION_PUBLISH, [ __CLASS__, 'handle_publish' ], 10, 1 );
    }

    public static function schedule_revision( int $revision_id, int $timestamp ): bool {
        $existing = wp_next_scheduled( Plugin::ACTION_PUBLISH, [ $revision_id ] );
        if ( $existing ) {
            if ( (int) $existing === $timestamp ) {
                return true;
            }
            wp_unschedule_event( $existing, Plugin::ACTION_PUBLISH, [ $revision_id ] );
        }

        return (bool) wp_schedule_single_event( $timestamp, Plugin::ACTION_PUBLISH, [ $revision_id ] );
    }

    public static function clear_scheduled_revision( int $revision_id ): void {
        $timestamp = wp_next_scheduled( Plugin::ACTION_PUBLISH, [ $revision_id ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, Plugin::ACTION_PUBLISH, [ $revision_id ] );
        }

        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( Plugin::ACTION_PUBLISH, [ $revision_id ] );
        }
    }

    public static function handle_publish( int $revision_id ): void {
        $revision_id = (int) $revision_id;
        if ( $revision_id <= 0 ) {
            return;
        }

        RevisionManager::apply_revision_to_product( $revision_id );
    }
}
