<?php

namespace Sanar\WCProductScheduler;

use Sanar\WCProductScheduler\Admin\ProductMetaBox;
use Sanar\WCProductScheduler\Admin\RevisionAdmin;
use Sanar\WCProductScheduler\Revision\RevisionPostType;
use Sanar\WCProductScheduler\Scheduler\Scheduler;

class Plugin {
    public const CPT = 'sanar_product_revision';

    public const META_PARENT_ID = '_sanar_wcps_parent_product_id';
    public const META_SCHEDULED_DATETIME = '_sanar_wcps_scheduled_datetime';
    public const META_STATUS = '_sanar_wcps_revision_status';
    public const META_CREATED_BY = '_sanar_wcps_created_by';
    public const META_LOG = '_sanar_wcps_log';
    public const META_ERROR = '_sanar_wcps_error_message';
    public const META_TAXONOMIES = '_sanar_wcps_taxonomies';
    public const META_TIMEZONE = '_sanar_wcps_timezone';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public const ACTION_PUBLISH = 'sanar_wcps_publish_revision';

    public static function init(): void {
        self::includes();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SANAR_WCPS] Plugin::init loaded' );
        }

        add_action( 'plugins_loaded', [ RevisionPostType::class, 'register' ], 0 );
        add_action( 'init', [ RevisionPostType::class, 'register' ], 0 );
        add_action( 'init', [ RevisionPostType::class, 'register_meta' ], 5 );

        RevisionPostType::register();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SANAR_WCPS] cpt_registered=' . ( post_type_exists( self::CPT ) ? '1' : '0' ) );
        }

        add_action( 'admin_post_sanar_wcps_schedule_update', [ ProductMetaBox::class, 'handle_schedule' ], 10 );
        ProductMetaBox::init();
        Scheduler::init();

        RevisionAdmin::init();

        add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ __CLASS__, 'wp_cron_notice' ] );
    }

    private static function includes(): void {
        require_once SANAR_WCPS_PATH . 'src/Util/Logger.php';
        require_once SANAR_WCPS_PATH . 'src/Util/Lock.php';
        require_once SANAR_WCPS_PATH . 'src/Revision/RevisionPostType.php';
        require_once SANAR_WCPS_PATH . 'src/Revision/RevisionManager.php';
        require_once SANAR_WCPS_PATH . 'src/Scheduler/Scheduler.php';
        require_once SANAR_WCPS_PATH . 'src/Admin/ProductMetaBox.php';
        require_once SANAR_WCPS_PATH . 'src/Admin/RevisionAdmin.php';
    }

    public static function wp_cron_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__( 'WP-Cron esta ativo. Para maior confiabilidade, defina DISABLE_WP_CRON=true e configure um cron de servidor para chamar wp-cron.php a cada 1 minuto.', 'sanar-wc-product-scheduler' );
        echo '</p></div>';
    }

    public static function enqueue_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $allowed = [ 'product', self::CPT ];
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
