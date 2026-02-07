<?php

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Revision\RevisionPostType;
use Sanar\WCProductScheduler\Scheduler\Scheduler;
use Sanar\WCProductScheduler\Util\Logger;

class ProductMetaBox {
    public static function init(): void {
        add_action( 'add_meta_boxes_product', [ __CLASS__, 'register_meta_box' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
        add_action( 'admin_footer', [ __CLASS__, 'render_schedule_form' ] );
    }

    public static function handle_schedule(): void {
        self::handle_schedule_update();
    }

    public static function register_meta_box(): void {
        add_meta_box(
            'sanar-wcps-schedule',
            __( 'Agendar atualizacao', 'sanar-wc-product-scheduler' ),
            [ __CLASS__, 'render_meta_box' ],
            'product',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ): void {
        $timezone = wp_timezone_string();
        $next = self::get_next_scheduled_revision( $post->ID );
        $template = SANAR_WCPS_PATH . 'templates/metabox-schedule.php';
        if ( file_exists( $template ) ) {
            require $template;
            return;
        }

        echo '<p>' . esc_html__( 'Template nao encontrado.', 'sanar-wc-product-scheduler' ) . '</p>';
    }

    public static function handle_schedule_update(): void {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sanar_wcps_schedule_update' ) ) {
            wp_die( 'Nonce invalida.' );
        }

        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_die( 'Permissao insuficiente.' );
        }

        $product_post = get_post( $product_id );
        if ( ! $product_post || $product_post->post_type !== 'product' ) {
            wp_die( 'Produto invalido.' );
        }

        $redirect = get_edit_post_link( $product_id, 'url' );
        if ( ! $redirect ) {
            $redirect = admin_url( 'edit.php?post_type=product' );
        }

        $datetime = isset( $_POST['sanar_wcps_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['sanar_wcps_datetime'] ) ) : '';
        $timezone = isset( $_POST['sanar_wcps_tz'] ) ? sanitize_text_field( wp_unslash( $_POST['sanar_wcps_tz'] ) ) : '';
        $payload_raw = isset( $_POST['sanar_wcps_payload'] ) ? wp_unslash( $_POST['sanar_wcps_payload'] ) : '';
        $payload = self::parse_payload( $payload_raw );
        if ( $timezone === '' ) {
            $timezone = wp_timezone_string();
        }

        if ( $datetime === '' ) {
            wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_failed', $redirect ) );
            exit;
        }

        if ( ! post_type_exists( Plugin::CPT ) ) {
            RevisionPostType::register();
            RevisionPostType::register_meta();
        }

        if ( ! post_type_exists( Plugin::CPT ) ) {
            Logger::log_system_event( 'cpt_missing', [
                'product_id' => $product_id,
                'post_type_exists' => false,
                'class_exists' => class_exists( RevisionPostType::class ),
                'did_plugins_loaded' => did_action( 'plugins_loaded' ),
                'did_init' => did_action( 'init' ),
            ] );

            $redirect = add_query_arg( [
                'sanar_wcps_notice' => 'create_failed',
                'sanar_wcps_error' => rawurlencode( 'CPT nao registrado. Plugin carregou? Veja debug log.' ),
            ], $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        Logger::info( 'Handler called', [
            'post_type_exists' => post_type_exists( Plugin::CPT ),
        ] );

        if ( ! function_exists( 'wc_get_product' ) ) {
            wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'invalid_product', $redirect ) );
            exit;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'invalid_product', $redirect ) );
            exit;
        }

        if ( $product->is_type( 'variable' ) ) {
            wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'variable_blocked', $redirect ) );
            exit;
        }

        $revision_id = 0;
        try {
            $utc = Plugin::local_to_utc( $datetime );

            if ( $utc['timestamp'] <= time() ) {
                wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_failed', $redirect ) );
                exit;
            }

            $revision_id = RevisionManager::create_revision_from_product( $product_id, $payload );
            if ( is_wp_error( $revision_id ) ) {
                $error_message = $revision_id->get_error_message();
                Logger::log_system_event( 'revision_create_failed', [
                    'product_id' => $product_id,
                    'error' => $error_message,
                ] );

                $redirect = add_query_arg( [
                    'sanar_wcps_notice' => 'create_failed',
                    'sanar_wcps_error' => rawurlencode( $error_message ),
                ], $redirect );
                wp_safe_redirect( $redirect );
                exit;
            }

            if ( RevisionManager::has_schedule_conflict( $product_id, $utc['utc'], $revision_id ) ) {
                update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_DRAFT );
                Logger::log_event( $revision_id, 'schedule_conflict', [ 'scheduled_utc' => $utc['utc'] ] );
                wp_safe_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_conflict', $redirect ) );
                exit;
            }

            $scheduled = Scheduler::schedule_revision( $revision_id, $utc['timestamp'] );
            if ( ! $scheduled ) {
                throw new \Exception( 'Falha ao agendar evento WP-Cron.' );
            }
            update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, $utc['utc'] );
            update_post_meta( $revision_id, Plugin::META_TIMEZONE, $timezone );
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );

            Logger::log_event( $revision_id, 'scheduled', [ 'scheduled_utc' => $utc['utc'] ] );
        } catch ( \Exception $e ) {
            if ( isset( $revision_id ) && $revision_id ) {
                update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
                Logger::set_error( $revision_id, $e->getMessage() );
                Logger::log_event( $revision_id, 'failed', [ 'error' => $e->getMessage() ] );
            }
            $redirect = add_query_arg( [
                'sanar_wcps_notice' => 'schedule_failed',
                'sanar_wcps_error' => rawurlencode( $e->getMessage() ),
            ], $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        $scheduled_local = Plugin::format_site_datetime( $utc['utc'] );
        $redirect = add_query_arg( [
            'sanar_wcps_notice' => 'scheduled',
            'sanar_wcps_scheduled' => rawurlencode( $scheduled_local ),
        ], $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function admin_notices(): void {
        if ( empty( $_GET['sanar_wcps_notice'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }

        $notice = sanitize_text_field( wp_unslash( $_GET['sanar_wcps_notice'] ) );
        $error_detail = isset( $_GET['sanar_wcps_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['sanar_wcps_error'] ) ) ) : '';
        $scheduled = isset( $_GET['sanar_wcps_scheduled'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['sanar_wcps_scheduled'] ) ) ) : '';

        if ( $notice === 'create_failed' && $error_detail ) {
            $class = 'notice notice-error';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( 'Falha ao criar revisao: ' . $error_detail ) . '</p></div>';
            return;
        }

        if ( $notice === 'scheduled' && $scheduled ) {
            $class = 'notice notice-success';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( 'Atualizacao agendada para ' . $scheduled . '.' ) . '</p></div>';
            return;
        }

        $messages = [
            'create_failed' => [ 'error', 'Falha ao criar revisao.' ],
            'invalid_product' => [ 'error', 'Produto invalido.' ],
            'variable_blocked' => [ 'error', 'Produtos variaveis nao sao suportados nesta versao.' ],
            'schedule_failed' => [ 'error', 'Falha ao agendar revisao.' ],
            'schedule_conflict' => [ 'error', 'Ja existe uma revisao agendada para este horario.' ],
            'scheduled' => [ 'success', 'Revisao agendada.' ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        $class = $messages[ $notice ][0] === 'success' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
    }

    private static function get_next_scheduled_revision( int $product_id ): ?string {
        $query = new \WP_Query( [
            'post_type' => Plugin::CPT,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_key' => Plugin::META_SCHEDULED_DATETIME,
            'meta_query' => [
                [
                    'key' => Plugin::META_PARENT_ID,
                    'value' => $product_id,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => Plugin::META_STATUS,
                    'value' => Plugin::STATUS_SCHEDULED,
                    'compare' => '=',
                ],
            ],
        ] );

        if ( empty( $query->posts ) ) {
            return null;
        }

        $revision_id = (int) $query->posts[0];
        $utc = get_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, true );

        if ( ! $utc ) {
            return null;
        }

        return Plugin::utc_to_site( $utc );
    }

    public static function render_schedule_form(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }

        echo '<form id="sanar_wcps_schedule_form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"></form>';
    }

    private static function parse_payload( string $payload_raw ): array {
        if ( $payload_raw === '' ) {
            return [];
        }

        $data = [];
        parse_str( $payload_raw, $data );
        return is_array( $data ) ? $data : [];
    }
}
