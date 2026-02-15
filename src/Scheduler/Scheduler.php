<?php

namespace Sanar\WCProductScheduler\Scheduler;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Runner\Runner;
use Sanar\WCProductScheduler\Util\Logger;

class Scheduler {
    public const CRON_HOOK = 'sanar_wcps_tick';
    public const CRON_SCHEDULE = 'minute';
    public const FALLBACK_ACTION = 'sanar_wcps_run_due';
    public const MIGRATION_BATCH_SIZE = 100;

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
        add_action( self::CRON_HOOK, [ Runner::class, 'run_due_now' ] );
        add_action( 'init', [ __CLASS__, 'ensure_tick_scheduled' ], 20 );
        add_action( 'init', [ __CLASS__, 'run_incremental_migration' ], 25 );
        add_action( 'admin_post_' . self::FALLBACK_ACTION, [ __CLASS__, 'handle_run_due_endpoint' ] );
        add_action( 'admin_post_nopriv_' . self::FALLBACK_ACTION, [ __CLASS__, 'handle_run_due_endpoint' ] );
    }

    public static function activate(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
        self::ensure_tick_scheduled( true );
        self::migrate_legacy_scheduled_meta_batch( self::MIGRATION_BATCH_SIZE );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function cron_schedules( array $schedules ): array {
        if ( isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            return $schedules;
        }

        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => 60,
            'display' => __( 'Every minute', 'sanar-wc-product-scheduler' ),
        ];

        return $schedules;
    }

    public static function ensure_tick_scheduled( bool $force = false ): void {
        if ( $force ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }

        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
    }

    public static function run_incremental_migration(): void {
        if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        self::migrate_legacy_scheduled_meta_batch( self::MIGRATION_BATCH_SIZE );
    }

    public static function migrate_legacy_scheduled_meta_batch( int $limit = self::MIGRATION_BATCH_SIZE ): array {
        global $wpdb;

        $limit = max( 1, min( 500, $limit ) );
        $result = [
            'scanned' => 0,
            'migrated' => 0,
            'invalid' => 0,
            'errors' => 0,
        ];

        $sql = $wpdb->prepare(
            "SELECT meta_id, post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
               AND meta_value <> ''
               AND meta_value NOT REGEXP '^[0-9]+$'
             ORDER BY meta_id ASC
             LIMIT %d",
            Plugin::META_SCHEDULED_DATETIME,
            $limit
        );

        if ( ! is_string( $sql ) ) {
            $result['errors']++;
            return $result;
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return $result;
        }

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $result['scanned']++;
            $meta_id = isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0;
            $post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $raw = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            if ( $meta_id <= 0 || $post_id <= 0 || $raw === '' ) {
                $result['invalid']++;
                continue;
            }

            $timestamp = Plugin::scheduled_timestamp_from_meta( $raw );
            if ( $timestamp <= 0 ) {
                $result['invalid']++;
                continue;
            }

            $updated = update_metadata_by_mid( 'post', $meta_id, (string) $timestamp, Plugin::META_SCHEDULED_DATETIME );
            if ( $updated === false ) {
                $result['errors']++;
                continue;
            }

            $existing_utc = (string) get_post_meta( $post_id, Plugin::META_SCHEDULED_UTC, true );
            if ( $existing_utc === '' ) {
                update_post_meta( $post_id, Plugin::META_SCHEDULED_UTC, trim( $raw ) );
            }

            $result['migrated']++;
        }

        if ( $result['migrated'] > 0 || $result['errors'] > 0 || $result['invalid'] > 0 ) {
            Logger::log_system_event( 'scheduled_meta_migration', $result );
        }

        return $result;
    }

    public static function schedule_revision( int $revision_id, int $timestamp ): bool {
        if ( $revision_id <= 0 || $timestamp <= 0 ) {
            return false;
        }

        $scheduled_utc = Plugin::scheduled_timestamp_to_utc( $timestamp );
        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, $timestamp );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, $scheduled_utc );
        Logger::log_event( $revision_id, 'scheduled', [ 'scheduled_utc' => $scheduled_utc ] );
        return true;
    }

    public static function clear_scheduled_revision( int $revision_id ): void {
        if ( $revision_id <= 0 ) {
            return;
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_CANCELLED );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC );
        delete_post_meta( $revision_id, Plugin::META_TIMEZONE );
    }

    public static function handle_run_due_endpoint(): void {
        nocache_headers();

        $configured_key = defined( 'SANAR_WCPS_CRON_KEY' ) && is_string( SANAR_WCPS_CRON_KEY )
            ? trim( SANAR_WCPS_CRON_KEY )
            : '';

        if ( $configured_key === '' ) {
            wp_send_json_error(
                [
                    'error' => 'cron_key_missing',
                    'message' => 'SANAR_WCPS_CRON_KEY nao configurada.',
                ],
                503
            );
        }

        $provided_key = isset( $_REQUEST['key'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) )
            : '';

        if ( $provided_key === '' || ! hash_equals( $configured_key, $provided_key ) ) {
            wp_send_json_error(
                [
                    'error' => 'invalid_key',
                    'message' => 'Chave invalida.',
                ],
                403
            );
        }

        $limit = isset( $_REQUEST['limit'] ) ? absint( wp_unslash( $_REQUEST['limit'] ) ) : Runner::DEFAULT_BATCH_SIZE;
        if ( $limit <= 0 ) {
            $limit = Runner::DEFAULT_BATCH_SIZE;
        }

        $result = Runner::run_due_now( $limit );
        wp_send_json_success(
            [
                'executed_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                'result' => $result,
            ]
        );
    }
}
