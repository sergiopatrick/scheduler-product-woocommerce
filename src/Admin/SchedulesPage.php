<?php

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Revision\RevisionMigration;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;
use Sanar\WCProductScheduler\Runner\Runner;
use Sanar\WCProductScheduler\Scheduler\Scheduler;
use Sanar\WCProductScheduler\Util\Logger;

class SchedulesPage {
    public const MENU_SLUG = 'sanar-wcps-schedules';
    private const NOTICE_KEY = 'sanar_wcps_dashboard_notice';
    private const NOTICE_MESSAGE_KEY = 'sanar_wcps_dashboard_message';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_sanar_wcps_dashboard_cancel', [ __CLASS__, 'handle_cancel' ] );
        add_action( 'admin_post_sanar_wcps_dashboard_reschedule', [ __CLASS__, 'handle_reschedule' ] );
        add_action( 'admin_post_sanar_wcps_dashboard_run_now', [ __CLASS__, 'handle_run_now' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function register_menu(): void {
        $capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

        add_submenu_page(
            'woocommerce',
            __( 'Agendamentos', 'sanar-wc-product-scheduler' ),
            __( 'Agendamentos', 'sanar-wc-product-scheduler' ),
            $capability,
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page(): void {
        self::assert_capability();

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
        if ( $view === 'detail' ) {
            self::render_detail_page();
            return;
        }

        self::render_list_page();
    }

    public static function handle_cancel(): void {
        self::assert_capability();

        $revision_id = self::get_request_revision_id();
        self::assert_action_nonce( 'cancel', $revision_id );

        $status = (string) get_post_meta( $revision_id, Plugin::META_STATUS, true );
        if ( $status !== Plugin::STATUS_SCHEDULED ) {
            self::redirect_with_notice( 'invalid_state', [ self::NOTICE_MESSAGE_KEY => 'Somente revisoes scheduled podem ser canceladas.' ] );
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_CANCELLED );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC );
        delete_post_meta( $revision_id, Plugin::META_TIMEZONE );
        Logger::log_event( $revision_id, 'cancelled', [ 'source' => 'dashboard' ] );

        self::redirect_with_notice( 'cancelled' );
    }

    public static function handle_reschedule(): void {
        self::assert_capability();

        $revision_id = self::get_request_revision_id();
        self::assert_action_nonce( 'reschedule', $revision_id );

        $local_datetime = isset( $_POST['sanar_wcps_datetime'] )
            ? sanitize_text_field( wp_unslash( $_POST['sanar_wcps_datetime'] ) )
            : '';

        if ( $local_datetime === '' ) {
            self::redirect_with_notice( 'reschedule_failed', [ self::NOTICE_MESSAGE_KEY => 'Data/hora obrigatoria para reagendar.' ], $revision_id );
        }

        try {
            $utc = Plugin::local_to_utc( $local_datetime );
        } catch ( \Exception $exception ) {
            self::redirect_with_notice( 'reschedule_failed', [ self::NOTICE_MESSAGE_KEY => $exception->getMessage() ], $revision_id );
            return;
        }

        $parent_id = self::resolve_parent_id( $revision_id );
        if ( $parent_id <= 0 ) {
            self::redirect_with_notice( 'reschedule_failed', [ self::NOTICE_MESSAGE_KEY => 'Revisao orfa: parent_product_id ausente.' ], $revision_id );
        }

        if ( $parent_id > 0 && RevisionManager::has_schedule_conflict( $parent_id, (int) $utc['timestamp'], $revision_id ) ) {
            self::redirect_with_notice( 'reschedule_failed', [ self::NOTICE_MESSAGE_KEY => 'Ja existe revisao agendada para esse horario.' ], $revision_id );
        }

        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, (int) $utc['timestamp'] );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, $utc['utc'] );
        update_post_meta( $revision_id, Plugin::META_TIMEZONE, $utc['timezone'] );
        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
        delete_post_meta( $revision_id, Plugin::META_ERROR );
        Logger::log_event( $revision_id, 'rescheduled', [ 'scheduled_utc' => $utc['utc'], 'source' => 'dashboard' ] );

        self::redirect_with_notice( 'rescheduled', [], $revision_id );
    }

    public static function handle_run_now(): void {
        self::assert_capability();

        $revision_id = self::get_request_revision_id();
        self::assert_action_nonce( 'run_now', $revision_id );

        $parent_id = self::resolve_parent_id( $revision_id );
        if ( $parent_id <= 0 ) {
            self::redirect_with_notice( 'run_failed', [ self::NOTICE_MESSAGE_KEY => 'Revisao orfa: parent_product_id ausente.' ], $revision_id );
        }

        $result = Runner::run_revision( $revision_id );
        $notice = 'run_failed';
        if ( $result['status'] === 'published' ) {
            $notice = 'run_published';
        } elseif ( $result['status'] === 'locked' ) {
            $notice = 'run_locked';
        } elseif ( $result['status'] === 'skipped' ) {
            $notice = 'run_skipped';
        }

        $extra = [];
        if ( ! empty( $result['message'] ) ) {
            $extra[ self::NOTICE_MESSAGE_KEY ] = sanitize_text_field( $result['message'] );
        }

        self::redirect_with_notice( $notice, $extra, $revision_id );
    }

    public static function admin_notices(): void {
        if ( ! self::is_dashboard_screen() ) {
            return;
        }

        $notice = isset( $_GET[ self::NOTICE_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_KEY ] ) ) : '';
        if ( $notice === '' ) {
            return;
        }

        $message = isset( $_GET[ self::NOTICE_MESSAGE_KEY ] )
            ? sanitize_text_field( wp_unslash( $_GET[ self::NOTICE_MESSAGE_KEY ] ) )
            : '';

        $messages = [
            'cancelled' => [ 'success', __( 'Agendamento cancelado.', 'sanar-wc-product-scheduler' ) ],
            'rescheduled' => [ 'success', __( 'Agendamento atualizado.', 'sanar-wc-product-scheduler' ) ],
            'run_published' => [ 'success', __( 'Revisao executada e publicada.', 'sanar-wc-product-scheduler' ) ],
            'run_failed' => [ 'error', __( 'Falha ao executar revisao.', 'sanar-wc-product-scheduler' ) ],
            'run_locked' => [ 'warning', __( 'Revisao pulada por lock ativo do produto.', 'sanar-wc-product-scheduler' ) ],
            'run_skipped' => [ 'warning', __( 'Revisao nao pode ser executada no status atual.', 'sanar-wc-product-scheduler' ) ],
            'invalid_state' => [ 'error', __( 'Acao indisponivel para o status atual.', 'sanar-wc-product-scheduler' ) ],
            'reschedule_failed' => [ 'error', __( 'Falha ao reagendar revisao.', 'sanar-wc-product-scheduler' ) ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        $type = $messages[ $notice ][0];
        $text = $message !== '' ? $message : $messages[ $notice ][1];
        $class = $type === 'success' ? 'notice notice-success' : ( $type === 'warning' ? 'notice notice-warning' : 'notice notice-error' );

        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p></div>';
    }

    public static function can_manage(): bool {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    public static function detail_url( int $revision_id, array $extra_args = [] ): string {
        $args = array_merge( [
            'page' => self::MENU_SLUG,
            'view' => 'detail',
            'revision_id' => $revision_id,
        ], $extra_args );

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    public static function list_url( array $extra_args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::MENU_SLUG ], $extra_args ), admin_url( 'admin.php' ) );
    }

    public static function action_url( string $action, int $revision_id, array $extra_args = [] ): string {
        $args = array_merge( [
            'action' => 'sanar_wcps_dashboard_' . $action,
            'revision_id' => $revision_id,
            '_wpnonce' => wp_create_nonce( self::nonce_action( $action, $revision_id ) ),
            'redirect_to' => self::current_dashboard_url(),
        ], $extra_args );

        return add_query_arg( $args, admin_url( 'admin-post.php' ) );
    }

    public static function build_revision_data( int $revision_id ): ?array {
        $revision = get_post( $revision_id );
        if ( ! RevisionTypeCompat::is_compatible_revision_post( $revision ) ) {
            return null;
        }

        $parent_id = self::resolve_parent_id( $revision_id );

        $status = (string) get_post_meta( $revision_id, Plugin::META_STATUS, true );
        $scheduled_raw = get_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, true );
        $scheduled_timestamp = Plugin::scheduled_timestamp_from_meta( $scheduled_raw );
        $scheduled_utc = (string) get_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, true );
        if ( $scheduled_utc === '' ) {
            $scheduled_utc = Plugin::scheduled_timestamp_to_utc( $scheduled_timestamp );
        }
        $scheduled_local = Plugin::format_scheduled_local( $scheduled_timestamp, 'Y-m-d H:i' );
        if ( $scheduled_local === '' ) {
            $scheduled_local = self::format_utc_to_local( $scheduled_utc );
        }
        $timezone = (string) get_post_meta( $revision_id, Plugin::META_TIMEZONE, true );
        $timezone = $timezone !== '' ? $timezone : wp_timezone_string();
        $error = (string) get_post_meta( $revision_id, Plugin::META_ERROR, true );
        $published_utc = self::get_published_utc( $revision_id );

        $created_by = (int) get_post_meta( $revision_id, Plugin::META_CREATED_BY, true );
        if ( $created_by <= 0 ) {
            $created_by = (int) $revision->post_author;
        }

        $created_user = $created_by > 0 ? get_user_by( 'id', $created_by ) : null;
        $product_post = $parent_id > 0 ? get_post( $parent_id ) : null;
        $is_orphan = ! ( $product_post && $product_post->post_type === 'product' );
        $integrity_message = '';

        if ( $is_orphan ) {
            $integrity_message = 'Revisao orfa: parent_product_id invalido ou ausente.';
        } elseif ( $scheduled_timestamp <= 0 && $status === Plugin::STATUS_SCHEDULED ) {
            $integrity_message = 'Revisao scheduled sem data agendada.';
        }

        $product_title = '';
        if ( $product_post && $product_post->post_type === 'product' ) {
            $product_title = get_the_title( $parent_id );
        }

        if ( $product_title === '' ) {
            $product_title = $parent_id > 0 ? '#' . $parent_id : 'Produto nao vinculado';
        }

        return [
            'revision_id' => $revision_id,
            'revision_title' => $revision->post_title,
            'origin_post_type' => $revision->post_type,
            'parent_id' => $parent_id,
            'product_title' => $product_title,
            'product_edit_url' => ( $product_post && $product_post->post_type === 'product' ) ? get_edit_post_link( $parent_id ) : '',
            'product_view_url' => ( $product_post && $product_post->post_type === 'product' ) ? get_permalink( $parent_id ) : '',
            'status' => $status !== '' ? $status : Plugin::STATUS_DRAFT,
            'scheduled_timestamp' => $scheduled_timestamp,
            'scheduled_utc' => $scheduled_utc,
            'scheduled_local' => $scheduled_local,
            'timezone' => $timezone,
            'created_by_id' => $created_by,
            'created_by_name' => $created_user ? $created_user->display_name : ( $created_by > 0 ? (string) $created_by : '-' ),
            'created_at_utc' => get_post_time( 'Y-m-d H:i:s', true, $revision, true ),
            'created_at_local' => self::format_gmt_to_local( (string) $revision->post_date_gmt ),
            'published_at_utc' => $published_utc,
            'published_at_local' => self::format_utc_to_local( $published_utc ),
            'error' => $error,
            'is_orphan' => $is_orphan,
            'integrity_message' => $integrity_message,
            'log' => self::get_log_entries( $revision_id ),
        ];
    }

    public static function status_badge( string $status ): string {
        $status = $status !== '' ? $status : Plugin::STATUS_DRAFT;
        $class = 'sanar-wcps-status sanar-wcps-status-' . sanitize_html_class( $status );
        return '<span class="' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
    }

    public static function short_error( string $error, int $limit = 80 ): string {
        $error = trim( $error );
        if ( $error === '' ) {
            return '';
        }

        if ( strlen( $error ) <= $limit ) {
            return $error;
        }

        return substr( $error, 0, $limit - 3 ) . '...';
    }

    public static function can_cancel( string $status ): bool {
        return $status === Plugin::STATUS_SCHEDULED;
    }

    public static function can_run_now( string $status, bool $is_orphan = false ): bool {
        if ( $is_orphan ) {
            return false;
        }

        return in_array( $status, [ Plugin::STATUS_SCHEDULED, Plugin::STATUS_FAILED ], true );
    }

    public static function can_reschedule( string $status, bool $is_orphan = false ): bool {
        if ( $is_orphan ) {
            return false;
        }

        return $status !== Plugin::STATUS_PUBLISHED;
    }

    private static function render_list_page(): void {
        require_once SANAR_WCPS_PATH . 'src/Admin/SchedulesTable.php';
        RevisionMigration::run_on_demand_once_per_request();

        $table = new SchedulesTable();
        $table->prepare_items();
        $filters = $table->get_active_filters();
        $telemetry = self::build_runner_telemetry();

        $template = SANAR_WCPS_PATH . 'templates/admin-schedules-list.php';
        if ( file_exists( $template ) ) {
            require $template;
            return;
        }

        wp_die( 'Template admin-schedules-list.php nao encontrado.' );
    }

    private static function render_detail_page(): void {
        $revision_id = isset( $_GET['revision_id'] ) ? absint( wp_unslash( $_GET['revision_id'] ) ) : 0;
        if ( $revision_id <= 0 ) {
            wp_die( 'Revision ID invalido.' );
        }

        $revision = self::build_revision_data( $revision_id );
        if ( ! $revision ) {
            wp_die( 'Revisao nao encontrada.' );
        }

        $template = SANAR_WCPS_PATH . 'templates/admin-schedules-detail.php';
        if ( file_exists( $template ) ) {
            require $template;
            return;
        }

        wp_die( 'Template admin-schedules-detail.php nao encontrado.' );
    }

    private static function build_runner_telemetry(): array {
        $last_run_utc = (string) get_option( 'sanar_wcps_last_run_utc', '' );
        $last_run_local = self::format_utc_to_local( $last_run_utc );
        $last_run_count = (int) get_option( 'sanar_wcps_last_run_count', 0 );

        $next_tick = wp_next_scheduled( Scheduler::CRON_HOOK );
        $next_tick_utc = $next_tick ? gmdate( 'Y-m-d H:i:s', (int) $next_tick ) : '';
        $next_tick_local = $next_tick ? Plugin::format_scheduled_local( (int) $next_tick, 'Y-m-d H:i:s' ) : '';

        $cron_key = defined( 'SANAR_WCPS_CRON_KEY' ) && is_string( SANAR_WCPS_CRON_KEY )
            ? trim( SANAR_WCPS_CRON_KEY )
            : '';

        $fallback_base = add_query_arg(
            [ 'action' => Scheduler::FALLBACK_ACTION ],
            admin_url( 'admin-post.php' )
        );

        $fallback_url = '';
        $fallback_url_masked = $fallback_base . '&key=<configure-SANAR_WCPS_CRON_KEY>';
        if ( $cron_key !== '' ) {
            $fallback_url = add_query_arg(
                [
                    'action' => Scheduler::FALLBACK_ACTION,
                    'key' => $cron_key,
                ],
                admin_url( 'admin-post.php' )
            );
            $fallback_url_masked = add_query_arg(
                [
                    'action' => Scheduler::FALLBACK_ACTION,
                    'key' => self::mask_secret( $cron_key ),
                ],
                admin_url( 'admin-post.php' )
            );
        }

        return [
            'last_run_utc' => $last_run_utc,
            'last_run_local' => $last_run_local,
            'last_run_count' => $last_run_count,
            'next_tick_timestamp' => $next_tick ? (int) $next_tick : 0,
            'next_tick_utc' => $next_tick_utc,
            'next_tick_local' => $next_tick_local,
            'cron_missing' => ! $next_tick,
            'cron_key_defined' => $cron_key !== '',
            'fallback_url' => $fallback_url,
            'fallback_url_masked' => $fallback_url_masked,
        ];
    }

    private static function mask_secret( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        $length = strlen( $value );
        if ( $length <= 8 ) {
            return str_repeat( '*', $length );
        }

        return substr( $value, 0, 4 ) . str_repeat( '*', max( 2, $length - 8 ) ) . substr( $value, -4 );
    }

    private static function get_published_utc( int $revision_id ): string {
        $published = (string) get_post_meta( $revision_id, Plugin::META_PUBLISHED_AT, true );
        if ( $published !== '' ) {
            return $published;
        }

        $log = self::get_log_entries( $revision_id );
        foreach ( array_reverse( $log ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( ( $entry['event'] ?? '' ) !== 'published' ) {
                continue;
            }
            if ( isset( $entry['context']['published_utc'] ) && is_string( $entry['context']['published_utc'] ) ) {
                return $entry['context']['published_utc'];
            }
            if ( isset( $entry['timestamp_utc'] ) && is_string( $entry['timestamp_utc'] ) ) {
                return $entry['timestamp_utc'];
            }
        }

        return '';
    }

    private static function get_log_entries( int $revision_id ): array {
        $log = get_post_meta( $revision_id, Plugin::META_LOG, true );
        return is_array( $log ) ? $log : [];
    }

    private static function format_utc_to_local( string $utc ): string {
        if ( trim( $utc ) === '' ) {
            return '';
        }

        try {
            return Plugin::utc_to_site( $utc );
        } catch ( \Throwable $throwable ) {
            return '';
        }
    }

    private static function format_gmt_to_local( string $gmt ): string {
        if ( trim( $gmt ) === '' || $gmt === '0000-00-00 00:00:00' ) {
            return '';
        }

        try {
            $dt = new \DateTime( $gmt, new \DateTimeZone( 'UTC' ) );
            $dt->setTimezone( wp_timezone() );
            return $dt->format( 'Y-m-d H:i' );
        } catch ( \Throwable $throwable ) {
            return '';
        }
    }

    private static function get_request_revision_id(): int {
        if ( isset( $_POST['revision_id'] ) ) {
            $revision_id = absint( wp_unslash( $_POST['revision_id'] ) );
            self::assert_revision_exists( $revision_id );
            return $revision_id;
        }

        if ( isset( $_GET['revision_id'] ) ) {
            $revision_id = absint( wp_unslash( $_GET['revision_id'] ) );
            self::assert_revision_exists( $revision_id );
            return $revision_id;
        }

        wp_die( 'Revision ID invalido.' );
    }

    private static function assert_action_nonce( string $action, int $revision_id ): void {
        $nonce = '';
        if ( isset( $_REQUEST['_wpnonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        }

        if ( $revision_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $action, $revision_id ) ) ) {
            wp_die( 'Nonce invalida.' );
        }
    }

    private static function nonce_action( string $action, int $revision_id ): string {
        return 'sanar_wcps_dashboard_' . $action . '_' . $revision_id;
    }

    private static function assert_capability(): void {
        if ( self::can_manage() ) {
            return;
        }

        wp_die( 'Permissao insuficiente.' );
    }

    private static function redirect_with_notice( string $notice, array $extra_args = [], int $revision_id = 0 ): void {
        $base = self::sanitize_redirect_to();
        $args = array_merge( [
            self::NOTICE_KEY => $notice,
        ], $extra_args );

        if ( $revision_id > 0 && ! isset( $args['view'] ) ) {
            $args['view'] = 'detail';
            $args['revision_id'] = $revision_id;
        }

        wp_safe_redirect( add_query_arg( $args, $base ) );
        exit;
    }

    private static function sanitize_redirect_to(): string {
        $default = self::list_url();
        $redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
        if ( $redirect_to === '' ) {
            return $default;
        }

        $admin_base = admin_url();
        if ( strpos( $redirect_to, $admin_base ) !== 0 ) {
            return $default;
        }

        return $redirect_to;
    }

    private static function current_dashboard_url(): string {
        if ( isset( $_GET['view'] ) && sanitize_key( wp_unslash( $_GET['view'] ) ) === 'detail' ) {
            $revision_id = isset( $_GET['revision_id'] ) ? absint( wp_unslash( $_GET['revision_id'] ) ) : 0;
            if ( $revision_id > 0 ) {
                return self::detail_url( $revision_id );
            }
        }

        $allowed = [ 'status', 'date_range', 'date_from', 'date_to', 'per_page', 's', 'orderby', 'order', 'paged' ];
        $args = [];
        foreach ( $allowed as $key ) {
            if ( ! isset( $_GET[ $key ] ) ) {
                continue;
            }
            $value = wp_unslash( $_GET[ $key ] );
            if ( is_array( $value ) ) {
                continue;
            }
            $args[ $key ] = sanitize_text_field( $value );
        }

        return self::list_url( $args );
    }

    private static function is_dashboard_screen(): bool {
        if ( ! is_admin() ) {
            return false;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return $page === self::MENU_SLUG;
    }

    private static function assert_revision_exists( int $revision_id ): void {
        if ( $revision_id <= 0 ) {
            wp_die( 'Revision ID invalido.' );
        }

        $revision = get_post( $revision_id );
        if ( ! RevisionTypeCompat::is_compatible_revision_post( $revision ) ) {
            wp_die( 'Revisao invalida.' );
        }
    }

    private static function resolve_parent_id( int $revision_id ): int {
        $parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );
        if ( $parent_id > 0 ) {
            return $parent_id;
        }

        $revision = get_post( $revision_id );
        if ( ! RevisionTypeCompat::is_compatible_revision_post( $revision ) ) {
            return 0;
        }

        $fallback_parent_id = (int) $revision->post_parent;
        if ( $fallback_parent_id <= 0 ) {
            return 0;
        }

        $fallback_parent = get_post( $fallback_parent_id );
        if ( ! $fallback_parent || $fallback_parent->post_type !== 'product' ) {
            return 0;
        }

        update_post_meta( $revision_id, Plugin::META_PARENT_ID, $fallback_parent_id );
        return $fallback_parent_id;
    }
}
