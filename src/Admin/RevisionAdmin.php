<?php

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Util\Logger;

class RevisionAdmin {
    public static function init(): void {
        add_filter( 'manage_edit-' . Plugin::CPT . '_columns', [ __CLASS__, 'columns' ] );
        add_action( 'manage_' . Plugin::CPT . '_posts_custom_column', [ __CLASS__, 'render_columns' ], 10, 2 );
        add_action( 'restrict_manage_posts', [ __CLASS__, 'status_filter' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'apply_status_filter' ] );
        add_action( 'all_admin_notices', [ __CLASS__, 'render_list_template' ] );
        add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
        add_action( 'add_meta_boxes_' . Plugin::CPT, [ __CLASS__, 'register_meta_box' ] );
        add_action( 'admin_post_sanar_wcps_schedule_revision', [ __CLASS__, 'handle_schedule_revision' ] );
        add_action( 'admin_post_sanar_wcps_publish_now', [ __CLASS__, 'handle_publish_now' ] );
        add_action( 'admin_post_sanar_wcps_cancel_revision', [ __CLASS__, 'handle_cancel_revision' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function columns( array $columns ): array {
        $new = [];
        $new['cb'] = $columns['cb'] ?? '';
        $new['title'] = __( 'Revisao', 'sanar-wc-product-scheduler' );
        $new['parent_product'] = __( 'Produto Pai', 'sanar-wc-product-scheduler' );
        $new['status'] = __( 'Status', 'sanar-wc-product-scheduler' );
        $new['scheduled'] = __( 'Agendado', 'sanar-wc-product-scheduler' );
        $new['created_by'] = __( 'Criado por', 'sanar-wc-product-scheduler' );
        $new['date'] = $columns['date'] ?? __( 'Data', 'sanar-wc-product-scheduler' );
        return $new;
    }

    public static function render_columns( string $column, int $post_id ): void {
        if ( $column === 'parent_product' ) {
            $parent_id = (int) get_post_meta( $post_id, Plugin::META_PARENT_ID, true );
            if ( $parent_id ) {
                $link = get_edit_post_link( $parent_id );
                $title = get_the_title( $parent_id );
                echo '<a href="' . esc_url( $link ) . '">' . esc_html( $title ?: '#' . $parent_id ) . '</a>';
            } else {
                echo '-';
            }
        }

        if ( $column === 'status' ) {
            $status = get_post_meta( $post_id, Plugin::META_STATUS, true );
            echo esc_html( $status ?: '-' );
        }

        if ( $column === 'scheduled' ) {
            $scheduled_raw = get_post_meta( $post_id, Plugin::META_SCHEDULED_DATETIME, true );
            $scheduled_ts = Plugin::scheduled_timestamp_from_meta( $scheduled_raw );
            if ( $scheduled_ts > 0 ) {
                $local = Plugin::format_scheduled_local( $scheduled_ts, 'Y-m-d H:i' );
                $utc = Plugin::scheduled_timestamp_to_utc( $scheduled_ts );
                echo esc_html( $local . ' (UTC ' . $utc . ')' );
            } else {
                echo '-';
            }
        }

        if ( $column === 'created_by' ) {
            $user_id = (int) get_post_meta( $post_id, Plugin::META_CREATED_BY, true );
            if ( $user_id ) {
                $user = get_user_by( 'id', $user_id );
                echo esc_html( $user ? $user->display_name : $user_id );
            } else {
                echo '-';
            }
        }
    }

    public static function status_filter(): void {
        global $typenow;
        if ( $typenow !== Plugin::CPT ) {
            return;
        }

        $value = isset( $_GET['sanar_wcps_status'] ) ? sanitize_text_field( wp_unslash( $_GET['sanar_wcps_status'] ) ) : '';
        $options = [
            '' => __( 'Todos os status', 'sanar-wc-product-scheduler' ),
            Plugin::STATUS_DRAFT => 'draft',
            Plugin::STATUS_SCHEDULED => 'scheduled',
            Plugin::STATUS_PUBLISHED => 'published',
            Plugin::STATUS_FAILED => 'failed',
            Plugin::STATUS_CANCELLED => 'cancelled',
        ];

        echo '<select name="sanar_wcps_status">';
        foreach ( $options as $key => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
        }
        echo '</select>';
    }

    public static function apply_status_filter( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( $post_type !== Plugin::CPT ) {
            return;
        }

        $status = isset( $_GET['sanar_wcps_status'] ) ? sanitize_text_field( wp_unslash( $_GET['sanar_wcps_status'] ) ) : '';
        if ( $status ) {
            $meta_query = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query ) ) {
                $meta_query = [];
            }
            $meta_query[] = [
                'key' => Plugin::META_STATUS,
                'value' => $status,
                'compare' => '=',
            ];
            $query->set( 'meta_query', $meta_query );
        }
    }

    public static function row_actions( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== Plugin::CPT ) {
            return $actions;
        }

        $nonce = wp_create_nonce( 'sanar_wcps_revision_action_' . $post->ID );
        $base = admin_url( 'admin-post.php' );
        $parent_id = (int) get_post_meta( $post->ID, Plugin::META_PARENT_ID, true );
        if ( $parent_id ) {
            $actions['sanar_wcps_view_parent'] = '<a href="' . esc_url( get_edit_post_link( $parent_id ) ) . '">' . esc_html__( 'Ver produto pai', 'sanar-wc-product-scheduler' ) . '</a>';
        }

        $actions['sanar_wcps_publish_now'] = '<a href="' . esc_url( add_query_arg( [
            'action' => 'sanar_wcps_publish_now',
            'revision_id' => $post->ID,
            '_wpnonce' => $nonce,
        ], $base ) ) . '">' . esc_html__( 'Publicar agora', 'sanar-wc-product-scheduler' ) . '</a>';

        $actions['sanar_wcps_cancel'] = '<a href="' . esc_url( add_query_arg( [
            'action' => 'sanar_wcps_cancel_revision',
            'revision_id' => $post->ID,
            '_wpnonce' => $nonce,
        ], $base ) ) . '">' . esc_html__( 'Cancelar', 'sanar-wc-product-scheduler' ) . '</a>';

        return $actions;
    }

    public static function register_meta_box(): void {
        add_meta_box(
            'sanar-wcps-revision-schedule',
            __( 'Agendamento', 'sanar-wc-product-scheduler' ),
            [ __CLASS__, 'render_meta_box' ],
            Plugin::CPT,
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ): void {
        $timezone = wp_timezone_string();
        $scheduled_raw = get_post_meta( $post->ID, Plugin::META_SCHEDULED_DATETIME, true );
        $scheduled_ts = Plugin::scheduled_timestamp_from_meta( $scheduled_raw );
        $local_value = $scheduled_ts > 0 ? Plugin::format_scheduled_local( $scheduled_ts, 'Y-m-d H:i' ) : '';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sanar_wcps_schedule_revision', 'sanar_wcps_nonce' );
        echo '<input type="hidden" name="action" value="sanar_wcps_schedule_revision">';
        echo '<input type="hidden" name="revision_id" value="' . esc_attr( $post->ID ) . '">';

        echo '<label for="sanar_wcps_datetime">' . esc_html__( 'Data/hora (local)', 'sanar-wc-product-scheduler' ) . '</label>';
        echo '<input type="datetime-local" id="sanar_wcps_datetime" name="sanar_wcps_datetime" style="width:100%" value="' . esc_attr( $local_value ? str_replace( ' ', 'T', $local_value ) : '' ) . '">';
        echo '<p style="margin:6px 0 0; color:#666;">' . esc_html__( 'Timezone:', 'sanar-wc-product-scheduler' ) . ' ' . esc_html( $timezone ) . '</p>';

        echo '<p style="margin-top:8px;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Agendar', 'sanar-wc-product-scheduler' ) . '</button>';
        echo '</p>';
        echo '</form>';

        $nonce = wp_create_nonce( 'sanar_wcps_revision_action_' . $post->ID );
        $base = admin_url( 'admin-post.php' );

        echo '<p>'; 
        echo '<a class="button" href="' . esc_url( add_query_arg( [
            'action' => 'sanar_wcps_publish_now',
            'revision_id' => $post->ID,
            '_wpnonce' => $nonce,
        ], $base ) ) . '">' . esc_html__( 'Publicar agora', 'sanar-wc-product-scheduler' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( add_query_arg( [
            'action' => 'sanar_wcps_cancel_revision',
            'revision_id' => $post->ID,
            '_wpnonce' => $nonce,
        ], $base ) ) . '">' . esc_html__( 'Cancelar', 'sanar-wc-product-scheduler' ) . '</a>';
        echo '</p>';
    }

    public static function handle_schedule_revision(): void {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Permissao insuficiente.' );
        }

        check_admin_referer( 'sanar_wcps_schedule_revision', 'sanar_wcps_nonce' );

        $revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;
        $datetime = isset( $_POST['sanar_wcps_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['sanar_wcps_datetime'] ) ) : '';

        if ( ! $revision_id || ! $datetime ) {
            wp_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_failed', wp_get_referer() ) );
            exit;
        }

        $parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );

        try {
            $utc = Plugin::local_to_utc( $datetime );

            if ( RevisionManager::has_schedule_conflict( $parent_id, (int) $utc['timestamp'], $revision_id ) ) {
                wp_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_conflict', wp_get_referer() ) );
                exit;
            }

            update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, (int) $utc['timestamp'] );
            update_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, $utc['utc'] );
            update_post_meta( $revision_id, Plugin::META_TIMEZONE, $utc['timezone'] );
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
            Logger::log_event( $revision_id, 'scheduled', [ 'scheduled_utc' => $utc['utc'] ] );

            wp_redirect( add_query_arg( 'sanar_wcps_notice', 'scheduled', wp_get_referer() ) );
            exit;
        } catch ( \Exception $e ) {
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
            Logger::set_error( $revision_id, $e->getMessage() );
            Logger::log_event( $revision_id, 'failed', [ 'error' => $e->getMessage() ] );

            wp_redirect( add_query_arg( 'sanar_wcps_notice', 'schedule_failed', wp_get_referer() ) );
            exit;
        }
    }

    public static function handle_publish_now(): void {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Permissao insuficiente.' );
        }

        $revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $revision_id || ! wp_verify_nonce( $nonce, 'sanar_wcps_revision_action_' . $revision_id ) ) {
            wp_die( 'Nonce invalida.' );
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
        $timestamp = time();
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, $timestamp );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, Plugin::scheduled_timestamp_to_utc( $timestamp ) );
        update_post_meta( $revision_id, Plugin::META_TIMEZONE, wp_timezone_string() );
        Logger::log_event( $revision_id, 'scheduled_immediate', [] );

        wp_redirect( add_query_arg( 'sanar_wcps_notice', 'scheduled', wp_get_referer() ) );
        exit;
    }

    public static function handle_cancel_revision(): void {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Permissao insuficiente.' );
        }

        $revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $revision_id || ! wp_verify_nonce( $nonce, 'sanar_wcps_revision_action_' . $revision_id ) ) {
            wp_die( 'Nonce invalida.' );
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_CANCELLED );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME );
        delete_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC );
        delete_post_meta( $revision_id, Plugin::META_TIMEZONE );
        Logger::log_event( $revision_id, 'cancelled', [] );

        wp_redirect( add_query_arg( 'sanar_wcps_notice', 'cancelled', wp_get_referer() ) );
        exit;
    }

    public static function admin_notices(): void {
        if ( empty( $_GET['sanar_wcps_notice'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== Plugin::CPT ) {
            return;
        }

        $notice = sanitize_text_field( wp_unslash( $_GET['sanar_wcps_notice'] ) );
        $messages = [
            'scheduled' => [ 'success', 'Revisao agendada.' ],
            'cancelled' => [ 'success', 'Revisao cancelada.' ],
            'schedule_failed' => [ 'error', 'Falha ao agendar revisao.' ],
            'schedule_conflict' => [ 'error', 'Ja existe uma revisao agendada para este horario.' ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        $class = $messages[ $notice ][0] === 'success' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
    }

    public static function render_list_template(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== Plugin::CPT ) {
            return;
        }

        $template = SANAR_WCPS_PATH . 'templates/admin-revisions-list.php';
        if ( file_exists( $template ) ) {
            require $template;
        }
    }
}
