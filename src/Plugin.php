<?php

namespace Sanar\WCProductScheduler;

use Sanar\WCProductScheduler\Admin\ProductMetaBox;
use Sanar\WCProductScheduler\Admin\ProductListColumn;
use Sanar\WCProductScheduler\Admin\RevisionAdmin;
use Sanar\WCProductScheduler\Admin\AdminStatusBox;
use Sanar\WCProductScheduler\Admin\SchedulesPage;
use Sanar\WCProductScheduler\Revision\RevisionPostType;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;
use Sanar\WCProductScheduler\Scheduler\Scheduler;

class Plugin {
    // WordPress limita post_type a 20 caracteres.
    public const CPT = 'sanar_product_revisi';
    public const CPT_LEGACY = 'sanar_product_revision';

    public const META_PARENT_ID = '_sanar_wcps_parent_product_id';
    public const META_SCHEDULED_DATETIME = '_sanar_wcps_scheduled_datetime';
    public const META_SCHEDULED_UTC = '_sanar_wcps_scheduled_datetime_utc';
    public const META_STATUS = '_sanar_wcps_revision_status';
    public const META_CREATED_BY = '_sanar_wcps_created_by';
    public const META_LOG = '_sanar_wcps_log';
    public const META_ERROR = '_sanar_wcps_error_message';
    public const META_TAXONOMIES = '_sanar_wcps_taxonomies';
    public const META_TIMEZONE = '_sanar_wcps_timezone';
    public const META_PUBLISHED_AT = '_sanar_wcps_published_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function init(): void {
        self::includes();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SANAR_WCPS] Plugin::init loaded' );
        }

        add_action( 'init', [ RevisionPostType::class, 'register_meta' ], 5 );

        ProductMetaBox::init();
        ProductListColumn::init();
        AdminStatusBox::init();

        RevisionAdmin::init();
        SchedulesPage::init();
        Scheduler::init();

        add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    private static function includes(): void {
        require_once SANAR_WCPS_PATH . 'src/Admin/RevisionAdmin.php';
        require_once SANAR_WCPS_PATH . 'src/Admin/SchedulesPage.php';
        require_once SANAR_WCPS_PATH . 'src/Admin/ProductListColumn.php';
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook === 'woocommerce_page_' . SchedulesPage::MENU_SLUG ) {
            wp_enqueue_style(
                'sanar_wcps_admin',
                SANAR_WCPS_URL . 'assets/css/admin.css',
                [],
                SANAR_WCPS_VERSION
            );
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $allowed = array_merge( [ 'product' ], RevisionTypeCompat::compatible_types() );
        if ( ! in_array( $screen->post_type, $allowed, true ) ) {
            return;
        }

        wp_enqueue_style(
            'sanar_wcps_admin',
            SANAR_WCPS_URL . 'assets/css/admin.css',
            [],
            SANAR_WCPS_VERSION
        );

        wp_enqueue_script(
            'sanar_wcps_admin',
            SANAR_WCPS_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            SANAR_WCPS_VERSION,
            true
        );
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'sanar-wc-product-scheduler',
            false,
            dirname( plugin_basename( SANAR_WCPS_PATH . 'sanar-wc-product-scheduler.php' ) ) . '/languages'
        );
    }

    public static function local_to_utc( string $local ): array {
        $local = trim( $local );
        if ( $local === '' ) {
            throw new \Exception( 'Data/hora vazia.' );
        }

        $timezone = wp_timezone();
        $format = strpos( $local, 'T' ) !== false ? 'Y-m-d\\TH:i' : 'Y-m-d H:i';

        $dt = \DateTime::createFromFormat( $format, $local, $timezone );
        if ( ! $dt ) {
            throw new \Exception( 'Formato de data/hora invalido.' );
        }

        $dt->setTimezone( new \DateTimeZone( 'UTC' ) );

        return [
            'utc' => $dt->format( 'Y-m-d H:i:s' ),
            'timestamp' => $dt->getTimestamp(),
            'timezone' => $timezone->getName(),
        ];
    }

    public static function scheduled_timestamp_from_meta( $raw ): int {
        if ( is_int( $raw ) ) {
            return $raw > 0 ? $raw : 0;
        }

        if ( is_float( $raw ) ) {
            $timestamp = (int) $raw;
            return $timestamp > 0 ? $timestamp : 0;
        }

        if ( is_string( $raw ) ) {
            $raw = trim( $raw );
            if ( $raw === '' ) {
                return 0;
            }

            if ( ctype_digit( $raw ) ) {
                $timestamp = (int) $raw;
                return $timestamp > 0 ? $timestamp : 0;
            }

            $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $raw, new \DateTimeZone( 'UTC' ) );
            if ( $dt instanceof \DateTime ) {
                $timestamp = $dt->getTimestamp();
                return $timestamp > 0 ? $timestamp : 0;
            }

            try {
                $fallback = new \DateTime( $raw, new \DateTimeZone( 'UTC' ) );
                $timestamp = $fallback->getTimestamp();
                return $timestamp > 0 ? $timestamp : 0;
            } catch ( \Throwable $throwable ) {
                return 0;
            }
        }

        return 0;
    }

    public static function scheduled_timestamp_to_utc( int $timestamp ): string {
        if ( $timestamp <= 0 ) {
            return '';
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    public static function format_scheduled_local( int $timestamp, string $format = 'd/m H:i' ): string {
        if ( $timestamp <= 0 ) {
            return '';
        }

        return wp_date( $format, $timestamp, wp_timezone() );
    }

    public static function utc_to_site( string $utc ): string {
        $dt = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
        $dt->setTimezone( wp_timezone() );
        return $dt->format( 'Y-m-d H:i' );
    }

    public static function format_site_datetime( string $utc, string $format = 'd/m H:i' ): string {
        $dt = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
        $dt->setTimezone( wp_timezone() );
        return $dt->format( $format );
    }

}
