<?php
/**
 * Product edit metabox scheduling UI.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Revision\RevisionPostType;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;
use Sanar\WCProductScheduler\Util\Logger;

class ProductMetaBox {
    private static ?array $schedule_request = null;
    private static ?array $snapshot = null;
    private static int $snapshot_post_id = 0;
    private static ?array $redirect_args = null;
    private static bool $is_handling = false;

    public static function init(): void {
        add_action( 'post_submitbox_misc_actions', [ __CLASS__, 'render_publish_box' ] );
        add_filter( 'wp_insert_post_data', [ __CLASS__, 'intercept_post_data' ], 10, 2 );
        add_action( 'save_post_product', [ __CLASS__, 'handle_product_save' ], 10000, 3 );
        add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_post_location' ], 10, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function render_publish_box( \WP_Post $post ): void {
        if ( $post->post_type !== 'product' ) {
            return;
        }

        $timezone = wp_timezone_string();
        $next = self::get_next_scheduled_revision( $post->ID );

        echo '<div class="misc-pub-section sanar-wcps-publish-section">';
        echo '<label for="sanar_wcps_schedule_datetime"><strong>' . esc_html__( 'Agendar atualizacao', 'sanar-wc-product-scheduler' ) . '</strong></label>';
        echo '<input type="datetime-local" id="sanar_wcps_schedule_datetime" name="sanar_wcps_schedule_datetime" class="sanar-wcps-field" value="">';
        echo '<p class="description">' . esc_html__( 'Timezone:', 'sanar-wc-product-scheduler' ) . ' ' . esc_html( $timezone ) . '</p>';

        if ( $next ) {
            echo '<p class="description sanar-wcps-next">' . esc_html__( 'Atualizacao agendada para:', 'sanar-wc-product-scheduler' ) . ' ' . esc_html( $next['local'] ) . '</p>';

            $nonce = wp_create_nonce( 'sanar_wcps_revision_action_' . $next['id'] );
            $cancel_url = add_query_arg( [
                'action' => 'sanar_wcps_cancel_revision',
                'revision_id' => $next['id'],
                '_wpnonce' => $nonce,
            ], admin_url( 'admin-post.php' ) );

            echo '<p><a class="button sanar-wcps-cancel" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancelar agendamento', 'sanar-wc-product-scheduler' ) . '</a></p>';
        }

        echo '</div>';
    }

    public static function intercept_post_data( array $data, array $postarr ): array {
        if ( self::$is_handling ) {
            return $data;
        }

        if ( ! self::is_schedule_request( $postarr ) ) {
            return $data;
        }

        $post_id = (int) $postarr['ID'];
        $post = get_post( $post_id );
        if ( ! $post ) {
            return $data;
        }

        self::$schedule_request = [
            'post_id' => $post_id,
            'datetime' => self::get_schedule_datetime(),
            'payload' => self::extract_payload(),
        ];

        if ( self::$snapshot_post_id !== $post_id ) {
            self::$snapshot = RevisionManager::snapshot_product( $post_id );
            self::$snapshot_post_id = $post_id;
        }

        $data['post_title'] = $post->post_title;
        $data['post_content'] = $post->post_content;
        $data['post_excerpt'] = $post->post_excerpt;

        if ( isset( $data['post_name'] ) ) {
            $data['post_name'] = $post->post_name;
        }

        if ( isset( $data['post_status'] ) ) {
            $data['post_status'] = $post->post_status;
        }

        if ( isset( $data['post_modified'] ) ) {
            $data['post_modified'] = $post->post_modified;
        }

        if ( isset( $data['post_modified_gmt'] ) ) {
            $data['post_modified_gmt'] = $post->post_modified_gmt;
        }

        return $data;
    }

    public static function handle_product_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( self::$is_handling ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( $post->post_type !== 'product' ) {
            return;
        }

        if ( self::get_schedule_datetime() === '' ) {
            return;
        }

        if ( ! $update ) {
            return;
        }

        if ( RevisionManager::is_processing() ) {
            return;
        }

        if ( ! self::$schedule_request || (int) self::$schedule_request['post_id'] !== $post_id ) {
            self::$schedule_request = [
                'post_id' => $post_id,
                'datetime' => self::get_schedule_datetime(),
                'payload' => self::extract_payload(),
            ];

            if ( self::$snapshot_post_id !== $post_id ) {
                self::$snapshot = RevisionManager::snapshot_product( $post_id );
                self::$snapshot_post_id = $post_id;
            }
        }

        if ( ! self::$schedule_request || (int) self::$schedule_request['post_id'] !== $post_id ) {
            return;
        }

        self::$is_handling = true;

        try {
            self::process_schedule( $post_id );
        } finally {
            self::$schedule_request = null;
            self::$snapshot = null;
            self::$snapshot_post_id = 0;
            self::$is_handling = false;
        }
    }

    public static function redirect_post_location( string $location, int $post_id ): string {
        if ( empty( self::$redirect_args ) ) {
            return $location;
        }

        if ( (int) ( self::$redirect_args['post_id'] ?? 0 ) !== $post_id ) {
            return $location;
        }

        $args = self::$redirect_args;
        unset( $args['post_id'] );

        return add_query_arg( $args, $location );
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
        $error_detail = isset( $_GET['sanar_wcps_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sanar_wcps_error'] ) ) : '';
        $scheduled = isset( $_GET['sanar_wcps_scheduled'] ) ? sanitize_text_field( wp_unslash( $_GET['sanar_wcps_scheduled'] ) ) : '';

        if ( $notice === 'create_failed' && $error_detail ) {
            $class = 'notice notice-error';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( 'Falha ao criar revisao: ' . $error_detail ) . '</p></div>';
            return;
        }

        if ( $notice === 'schedule_failed' && $error_detail ) {
            $class = 'notice notice-error';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( 'Falha ao agendar revisao: ' . $error_detail ) . '</p></div>';
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
            'cancelled' => [ 'success', 'Revisao cancelada.' ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        $class = $messages[ $notice ][0] === 'success' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
    }

    private static function is_schedule_request( array $postarr ): bool {
        if ( empty( $postarr['ID'] ) ) {
            return false;
        }

        $post_id = (int) $postarr['ID'];
        if ( $post_id <= 0 ) {
            return false;
        }

        $post_type = $postarr['post_type'] ?? get_post_type( $post_id );
        if ( $post_type !== 'product' ) {
            return false;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return false;
        }

        $datetime = self::get_schedule_datetime();
        if ( $datetime === '' ) {
            return false;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return false;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }

        return true;
    }

    private static function get_schedule_datetime(): string {
        return isset( $_POST['sanar_wcps_schedule_datetime'] )
            ? sanitize_text_field( wp_unslash( $_POST['sanar_wcps_schedule_datetime'] ) )
            : '';
    }

    private static function extract_payload(): array {
        $payload_raw = isset( $_POST['sanar_wcps_payload'] ) ? wp_unslash( $_POST['sanar_wcps_payload'] ) : '';
        $payload = self::parse_payload( $payload_raw );

        if ( empty( $payload ) ) {
            $payload = $_POST;
        }

        return is_array( $payload ) ? $payload : [];
    }

    private static function restore_parent_state( int $post_id ): void {
        if ( empty( self::$snapshot ) || self::$snapshot_post_id !== $post_id ) {
            return;
        }

        $post = get_post( $post_id );
        if ( $post ) {
            $needs_restore = $post->post_title !== ( self::$snapshot['post_title'] ?? '' )
                || $post->post_content !== ( self::$snapshot['post_content'] ?? '' )
                || $post->post_excerpt !== ( self::$snapshot['post_excerpt'] ?? '' );

            if ( $needs_restore ) {
                wp_update_post( [
                    'ID' => $post_id,
                    'post_title' => self::$snapshot['post_title'] ?? $post->post_title,
                    'post_content' => self::$snapshot['post_content'] ?? $post->post_content,
                    'post_excerpt' => self::$snapshot['post_excerpt'] ?? $post->post_excerpt,
                ] );
            }
        }

        $snapshot_meta = $snapshot_terms = [];
        if ( isset( self::$snapshot['meta'] ) && is_array( self::$snapshot['meta'] ) ) {
            $snapshot_meta = self::$snapshot['meta'];
        }
        if ( isset( self::$snapshot['terms'] ) && is_array( self::$snapshot['terms'] ) ) {
            $snapshot_terms = self::$snapshot['terms'];
        }

        $current_meta = get_post_meta( $post_id );
        foreach ( array_keys( $current_meta ) as $key ) {
            if ( RevisionManager::is_reserved_meta_key( $key ) ) {
                continue;
            }
            delete_post_meta( $post_id, $key );
        }

        foreach ( $snapshot_meta as $key => $values ) {
            if ( RevisionManager::is_reserved_meta_key( $key ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $post_id, $key, maybe_unserialize( $value ) );
            }
        }

        foreach ( $snapshot_terms as $taxonomy => $term_ids ) {
            wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
        }
    }

    private static function process_schedule( int $post_id ): void {
        $datetime = self::$schedule_request['datetime'] ?? '';
        if ( $datetime === '' ) {
            return;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            self::restore_parent_state( $post_id );
            self::set_notice( $post_id, 'invalid_product' );
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            self::restore_parent_state( $post_id );
            self::set_notice( $post_id, 'invalid_product' );
            return;
        }

        if ( $product->is_type( 'variable' ) ) {
            self::restore_parent_state( $post_id );
            self::set_notice( $post_id, 'variable_blocked' );
            return;
        }

        try {
            $utc = Plugin::local_to_utc( $datetime );
        } catch ( \Exception $e ) {
            self::restore_parent_state( $post_id );
            self::set_notice( $post_id, 'schedule_failed', $e->getMessage() );
            return;
        }

        if ( $utc['timestamp'] <= time() ) {
            self::restore_parent_state( $post_id );
            self::set_notice( $post_id, 'schedule_failed', 'Data/hora precisa ser futura.' );
            return;
        }

        if ( ! post_type_exists( Plugin::CPT ) ) {
            RevisionPostType::register();
            RevisionPostType::register_meta();
        }

        $payload = self::$schedule_request['payload'] ?? [];
        if ( ! is_array( $payload ) ) {
            $payload = [];
        }

        $revision_id = RevisionManager::create_revision_from_product( $post_id, $payload );

        self::restore_parent_state( $post_id );

        if ( is_wp_error( $revision_id ) ) {
            self::fail_schedule( $post_id, 'process_schedule:create_failed', $revision_id->get_error_message(), [
                'code' => $revision_id->get_error_code(),
            ] );
            return;
        }

        $revision_id = (int) $revision_id;
        if ( $revision_id <= 0 ) {
            self::fail_schedule( $post_id, 'process_schedule:create_failed', 'ID de revisao invalido retornado na criacao.' );
            return;
        }

        $revision = get_post( $revision_id );
        if ( ! RevisionTypeCompat::is_compatible_revision_post( $revision ) ) {
            self::fail_schedule( $post_id, 'process_schedule:create_failed', 'Revisao criada com post_type inesperado.' );
            return;
        }

        $stored_parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );
        if ( $stored_parent_id !== $post_id ) {
            self::fail_schedule( $post_id, 'process_schedule:create_failed', 'Revisao criada sem parent_product_id valido.' );
            return;
        }

        if ( RevisionManager::has_schedule_conflict( $post_id, (int) $utc['timestamp'], $revision_id ) ) {
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_DRAFT );
            Logger::log_event( $revision_id, 'schedule_conflict', [ 'scheduled_utc' => $utc['utc'] ] );
            self::set_notice( $post_id, 'schedule_conflict' );
            return;
        }

        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, (int) $utc['timestamp'] );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, $utc['utc'] );
        update_post_meta( $revision_id, Plugin::META_TIMEZONE, $utc['timezone'] );
        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );

        $stored_status = (string) get_post_meta( $revision_id, Plugin::META_STATUS, true );
        $stored_scheduled = (int) get_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, true );
        $stored_parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );

        if ( $stored_parent_id !== $post_id || $stored_status !== Plugin::STATUS_SCHEDULED || $stored_scheduled !== (int) $utc['timestamp'] ) {
            self::fail_schedule(
                $post_id,
                'process_schedule:integrity_failed',
                'Revisao criada, mas metadados obrigatorios nao persistiram corretamente.',
                [
                    'revision_id' => $revision_id,
                    'parent' => $stored_parent_id,
                    'status' => $stored_status,
                    'scheduled' => $stored_scheduled,
                    'expected_scheduled' => (int) $utc['timestamp'],
                ]
            );
            return;
        }

        Logger::log_event( $revision_id, 'scheduled', [ 'scheduled_utc' => $utc['utc'] ] );

        $scheduled_local = Plugin::format_scheduled_local( (int) $utc['timestamp'] );
        self::set_notice( $post_id, 'scheduled', '', $scheduled_local );
    }

    private static function fail_schedule( int $post_id, string $context, string $error_message, array $extra_context = [] ): void {
        $error_message = trim( $error_message );
        if ( $error_message === '' ) {
            $error_message = 'Falha desconhecida ao criar agendamento.';
        }

        $parts = [ $context, 'product_id=' . $post_id, 'message=' . $error_message ];
        foreach ( $extra_context as $key => $value ) {
            $parts[] = $key . '=' . wp_json_encode( $value );
        }

        $log_line = implode( ' ', $parts );
        RevisionManager::persist_last_error( $log_line );
        Logger::log_system_event( 'revision_create_failed', [
            'product_id' => $post_id,
            'error' => $error_message,
            'context' => $context,
            'extra' => $extra_context,
        ] );

        self::set_notice( $post_id, 'create_failed', $error_message );
    }

    private static function set_notice( int $post_id, string $notice, string $error_detail = '', string $scheduled = '' ): void {
        $args = [
            'post_id' => $post_id,
            'sanar_wcps_notice' => $notice,
        ];

        if ( $error_detail !== '' ) {
            $args['sanar_wcps_error'] = $error_detail;
        }

        if ( $scheduled !== '' ) {
            $args['sanar_wcps_scheduled'] = $scheduled;
        }

        self::$redirect_args = $args;
    }

    private static function get_next_scheduled_revision( int $product_id ): ?array {
        $query = new \WP_Query( [
            'post_type' => RevisionTypeCompat::compatible_types(),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'orderby' => 'meta_value_num',
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
                [
                    'key' => Plugin::META_SCHEDULED_DATETIME,
                    'value' => '^[0-9]+$',
                    'compare' => 'REGEXP',
                ],
            ],
        ] );

        if ( empty( $query->posts ) ) {
            return null;
        }

        $revision_id = (int) $query->posts[0];
        $scheduled_raw = get_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, true );
        $scheduled_ts = Plugin::scheduled_timestamp_from_meta( $scheduled_raw );
        $scheduled_utc = (string) get_post_meta( $revision_id, Plugin::META_SCHEDULED_UTC, true );

        if ( $scheduled_ts <= 0 ) {
            return null;
        }

        if ( $scheduled_utc === '' ) {
            $scheduled_utc = Plugin::scheduled_timestamp_to_utc( $scheduled_ts );
        }

        return [
            'id' => $revision_id,
            'timestamp' => $scheduled_ts,
            'utc' => $scheduled_utc,
            'local' => Plugin::format_scheduled_local( $scheduled_ts, 'Y-m-d H:i' ),
        ];
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
