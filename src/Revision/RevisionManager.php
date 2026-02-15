<?php

namespace Sanar\WCProductScheduler\Revision;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Util\Logger;

class RevisionManager {
    private static bool $is_processing = false;

    public static function is_processing(): bool {
        return self::$is_processing;
    }

    public static function create_revision_from_product( int $product_id, array $payload = [], int $user_id = 0 ) {
        self::persist_last_error( 'create_revision:start product_id=' . $product_id . ' time=' . gmdate( 'c' ) );

        if ( self::$is_processing ) {
            $error = new \WP_Error( 'reentrancy_blocked', 'Execucao em andamento. Tente novamente.' );
            self::persist_last_error( 'create_revision:error code=reentrancy_blocked message=' . $error->get_error_message() );
            return $error;
        }

        $product = get_post( $product_id );
        if ( ! $product || $product->post_type !== 'product' ) {
            $error = new \WP_Error( 'invalid_product', 'Produto invalido.' );
            self::persist_last_error( 'create_revision:error code=invalid_product message=' . $error->get_error_message() );
            return $error;
        }

        $user_id = $user_id ? $user_id : get_current_user_id();

        $fallback_title = 'Revision - ' . $product_id . ' - ' . gmdate( 'YmdHis' );
        $post_title = self::payload_text( $payload, 'post_title', '' );
        if ( $post_title === '' ) {
            $post_title = $fallback_title;
        }

        $post_content = self::payload_string( $payload, 'content', $product->post_content );
        if ( array_key_exists( 'post_content', $payload ) ) {
            $post_content = self::payload_string( $payload, 'post_content', $product->post_content );
        }
        $post_content = is_string( $post_content ) ? $post_content : '';

        $post_excerpt = self::payload_string( $payload, 'excerpt', $product->post_excerpt );
        if ( array_key_exists( 'post_excerpt', $payload ) ) {
            $post_excerpt = self::payload_string( $payload, 'post_excerpt', $product->post_excerpt );
        }
        $post_excerpt = is_string( $post_excerpt ) ? $post_excerpt : '';

        $args = [
            'post_type' => Plugin::CPT,
            'post_status' => 'draft',
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_excerpt' => $post_excerpt,
            'post_author' => $user_id,
        ];

        if ( ! post_type_exists( Plugin::CPT ) ) {
            RevisionPostType::register();
        }

        $cpt_registered = post_type_exists( Plugin::CPT );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[SANAR_WCPS] create revision: cpt=' . Plugin::CPT .
                ' post_type_exists=' . ( $cpt_registered ? 'true' : 'false' ) .
                ' did_init=' . (int) did_action( 'init' ) .
                ' did_plugins_loaded=' . (int) did_action( 'plugins_loaded' )
            );
        }

        if ( ! $cpt_registered ) {
            $message = 'CPT missing after register: ' . Plugin::CPT;
            self::persist_last_error( 'create_revision:error code=cpt_not_registered message=' . $message );
            return new \WP_Error( 'cpt_not_registered', $message );
        }

        self::$is_processing = true;
        try {
            $revision_id = wp_insert_post( $args, true );
        } finally {
            self::$is_processing = false;
        }

        if ( is_wp_error( $revision_id ) ) {
            $error_data = $revision_id->get_error_data();
            $encoded_data = wp_json_encode( $error_data );
            if ( ! is_string( $encoded_data ) ) {
                $encoded_data = '';
            }
            self::persist_last_error(
                'create_revision:wp_insert_post_error code=' . $revision_id->get_error_code() .
                ' message=' . $revision_id->get_error_message() .
                ( $encoded_data !== '' ? ' data=' . $encoded_data : '' )
            );
            Logger::log_system_event( 'wp_insert_post_failed', [
                'error_code' => $revision_id->get_error_code(),
                'error_message' => $revision_id->get_error_message(),
                'args' => self::sanitize_insert_args( $args ),
                'current_user_id' => $user_id,
                'post_type_exists' => post_type_exists( Plugin::CPT ),
            ] );
            return $revision_id;
        }

        if ( ! $revision_id ) {
            $message = 'wp_insert_post retornou 0 para product_id=' . $product_id . '.';
            self::persist_last_error(
                'create_revision:wp_insert_post_zero message=' . $message .
                ' args=' . wp_json_encode( self::sanitize_insert_args( $args ) )
            );
            Logger::log_system_event( 'wp_insert_post_zero', [
                'args' => self::sanitize_insert_args( $args ),
                'current_user_id' => $user_id,
                'post_type_exists' => post_type_exists( Plugin::CPT ),
            ] );
            return new \WP_Error( 'insert_failed', $message );
        }

        update_post_meta( $revision_id, Plugin::META_PARENT_ID, $product_id );
        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_DRAFT );
        update_post_meta( $revision_id, Plugin::META_CREATED_BY, $user_id );

        self::clone_meta( $product_id, $revision_id );
        self::clone_taxonomies( $product_id, $revision_id );
        self::apply_payload_meta( $revision_id, $payload );
        self::apply_payload_taxonomies( $revision_id, $payload );

        Logger::log_event( $revision_id, 'created', [ 'product_id' => $product_id ] );
        self::persist_last_error( 'create_revision:ok revision_id=' . (int) $revision_id . ' product_id=' . $product_id );

        return $revision_id;
    }

    public static function clone_meta( int $from_post_id, int $to_post_id ): void {
        $meta = get_post_meta( $from_post_id );
        foreach ( $meta as $key => $values ) {
            if ( self::is_reserved_meta_key( $key ) ) {
                continue;
            }
            delete_post_meta( $to_post_id, $key );
            foreach ( $values as $value ) {
                add_post_meta( $to_post_id, $key, maybe_unserialize( $value ) );
            }
        }
    }

    public static function clone_taxonomies( int $product_id, int $revision_id ): void {
        $taxonomies = get_object_taxonomies( 'product' );
        $snapshot = [];

        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $terms ) ) {
                $terms = [];
            }
            $snapshot[ $taxonomy ] = $terms;
        }

        update_post_meta( $revision_id, Plugin::META_TAXONOMIES, $snapshot );
    }

    public static function apply_revision( int $revision_id ): bool {
        if ( self::$is_processing ) {
            Logger::log_system_event( 'reentrancy_blocked', [ 'revision_id' => $revision_id ] );
            return false;
        }

        $revision = get_post( $revision_id );
        if ( ! $revision || $revision->post_type !== Plugin::CPT ) {
            Logger::log_event( $revision_id, 'failed', [ 'reason' => 'revision_not_found' ] );
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
            Logger::set_error( $revision_id, 'Revisao nao encontrada.' );
            return false;
        }

        $product_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );
        if ( $product_id <= 0 ) {
            Logger::log_event( $revision_id, 'failed', [ 'reason' => 'parent_meta_missing' ] );
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
            Logger::set_error( $revision_id, 'parent_product_id invalido.' );
            return false;
        }

        $product = get_post( $product_id );

        if ( ! $product || $product->post_type !== 'product' ) {
            Logger::log_event( $revision_id, 'failed', [ 'reason' => 'parent_not_found' ] );
            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
            Logger::set_error( $revision_id, 'Produto pai nao encontrado.' );
            return false;
        }

        $backup = null;
        self::$is_processing = true;
        try {
            $backup = self::snapshot_product( $product_id );

            $update = [
                'ID' => $product_id,
                'post_title' => $revision->post_title,
                'post_content' => $revision->post_content,
                'post_excerpt' => $revision->post_excerpt,
            ];

            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) {
                throw new \RuntimeException( $result->get_error_message() );
            }

            self::replace_meta( $product_id, $revision_id );
            self::replace_taxonomies( $product_id, $revision_id );

            do_action( 'sanar_wcps_after_publish', $product_id, $revision_id );
            do_action( 'sanar_wcps_cache_purge', $product_id, $revision_id );

            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_PUBLISHED );
            update_post_meta( $revision_id, Plugin::META_PUBLISHED_AT, gmdate( 'Y-m-d H:i:s' ) );
            delete_post_meta( $revision_id, Plugin::META_ERROR );

            Logger::log_event( $revision_id, 'published', [
                'product_id' => $product_id,
                'published_utc' => gmdate( 'Y-m-d H:i:s' ),
            ] );
            return true;
        } catch ( \Throwable $e ) {
            if ( is_array( $backup ) ) {
                self::restore_snapshot( $product_id, $backup );
            }

            update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
            $error_message = self::format_exception_message( $e );
            Logger::set_error( $revision_id, $error_message );
            Logger::log_event( $revision_id, 'failed', [
                'error' => $error_message,
                'stack' => self::summarize_stack_trace( $e ),
            ] );
            return false;
        } finally {
            self::$is_processing = false;
        }
    }

    public static function apply_revision_to_product( int $revision_id ): bool {
        return self::apply_revision( $revision_id );
    }

    public static function replace_meta( int $product_id, int $revision_id ): void {
        $revision_meta = get_post_meta( $revision_id );
        $product_meta = get_post_meta( $product_id );

        $revision_keys = array_keys( $revision_meta );

        foreach ( $revision_keys as $key ) {
            if ( self::is_reserved_meta_key( $key ) ) {
                continue;
            }
            delete_post_meta( $product_id, $key );
            foreach ( $revision_meta[ $key ] as $value ) {
                add_post_meta( $product_id, $key, maybe_unserialize( $value ) );
            }
        }

        $protected = apply_filters( 'sanar_wcps_protected_meta_keys', self::default_protected_meta_keys() );
        foreach ( array_keys( $product_meta ) as $key ) {
            if ( self::is_reserved_meta_key( $key ) ) {
                continue;
            }
            if ( in_array( $key, $revision_keys, true ) ) {
                continue;
            }
            if ( in_array( $key, $protected, true ) ) {
                continue;
            }
            delete_post_meta( $product_id, $key );
        }
    }

    public static function replace_taxonomies( int $product_id, int $revision_id ): void {
        $snapshot = get_post_meta( $revision_id, Plugin::META_TAXONOMIES, true );
        if ( ! is_array( $snapshot ) ) {
            $snapshot = [];
        }

        foreach ( $snapshot as $taxonomy => $term_ids ) {
            $result = wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
            if ( is_wp_error( $result ) ) {
                throw new \RuntimeException( $result->get_error_message() );
            }
        }
    }

    public static function snapshot_product( int $product_id ): array {
        $post = get_post( $product_id );
        $meta = get_post_meta( $product_id );
        $taxonomies = get_object_taxonomies( 'product' );
        $terms = [];

        foreach ( $taxonomies as $taxonomy ) {
            $term_ids = wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $term_ids ) ) {
                $term_ids = [];
            }
            $terms[ $taxonomy ] = $term_ids;
        }

        return [
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'meta' => $meta,
            'terms' => $terms,
        ];
    }

    public static function restore_snapshot( int $product_id, array $backup ): void {
        wp_update_post( [
            'ID' => $product_id,
            'post_title' => $backup['post_title'],
            'post_content' => $backup['post_content'],
            'post_excerpt' => $backup['post_excerpt'],
        ] );

        $current_meta = get_post_meta( $product_id );
        foreach ( array_keys( $current_meta ) as $key ) {
            if ( self::is_reserved_meta_key( $key ) ) {
                continue;
            }
            delete_post_meta( $product_id, $key );
        }

        foreach ( $backup['meta'] as $key => $values ) {
            if ( self::is_reserved_meta_key( $key ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $product_id, $key, maybe_unserialize( $value ) );
            }
        }

        foreach ( $backup['terms'] as $taxonomy => $term_ids ) {
            wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
        }
    }

    public static function is_reserved_meta_key( string $key ): bool {
        return strpos( $key, '_sanar_wcps_' ) === 0;
    }

    public static function default_protected_meta_keys(): array {
        return [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_trash_meta_time',
            '_wp_trash_meta_status',
        ];
    }

    public static function has_schedule_conflict( int $product_id, string $scheduled_utc, int $exclude_revision_id = 0 ): bool {
        $args = [
            'post_type' => Plugin::CPT,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
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
                    'value' => $scheduled_utc,
                    'compare' => '=',
                ],
            ],
        ];

        $query = new \WP_Query( $args );
        $conflict = ! empty( $query->posts );

        if ( $conflict && $exclude_revision_id ) {
            $conflict = (int) $query->posts[0] !== $exclude_revision_id;
        }

        return $conflict;
    }

    private static function payload_string( array $payload, string $key, string $fallback ): string {
        if ( array_key_exists( $key, $payload ) ) {
            $value = $payload[ $key ];
            if ( is_array( $value ) ) {
                $value = implode( ',', $value );
            }
            return wp_kses_post( wp_unslash( (string) $value ) );
        }

        return $fallback;
    }

    private static function payload_text( array $payload, string $key, string $fallback ): string {
        if ( array_key_exists( $key, $payload ) ) {
            $value = $payload[ $key ];
            if ( is_array( $value ) ) {
                $value = implode( ',', $value );
            }
            return sanitize_text_field( wp_unslash( (string) $value ) );
        }

        return $fallback;
    }

    private static function sanitize_insert_args( array $args ): array {
        $content = isset( $args['post_content'] ) && is_string( $args['post_content'] ) ? $args['post_content'] : '';
        $excerpt = isset( $args['post_excerpt'] ) && is_string( $args['post_excerpt'] ) ? $args['post_excerpt'] : '';

        return [
            'post_type' => $args['post_type'] ?? '',
            'post_status' => $args['post_status'] ?? '',
            'post_title' => $args['post_title'] ?? '',
            'post_author' => $args['post_author'] ?? 0,
            'post_content_len' => strlen( $content ),
            'post_excerpt_len' => strlen( $excerpt ),
        ];
    }

    private static function apply_payload_meta( int $revision_id, array $payload ): void {
        $ignored_keys = [
            '_wpnonce',
            '_wp_http_referer',
            '_wp_original_http_referer',
            '_wp_old_slug',
        ];

        foreach ( $payload as $meta_key => $value ) {
            if ( ! is_string( $meta_key ) ) {
                continue;
            }

            if ( strpos( $meta_key, '_' ) !== 0 ) {
                continue;
            }

            if ( strpos( $meta_key, '_wp' ) === 0 ) {
                continue;
            }

            if ( strpos( $meta_key, '_sanar_wcps_' ) === 0 ) {
                continue;
            }

            if ( in_array( $meta_key, $ignored_keys, true ) ) {
                continue;
            }

            update_post_meta( $revision_id, $meta_key, wp_unslash( $value ) );
        }

        if ( isset( $payload['product_image_gallery'] ) && ! array_key_exists( '_product_image_gallery', $payload ) ) {
            update_post_meta( $revision_id, '_product_image_gallery', wp_unslash( $payload['product_image_gallery'] ) );
        }

        if ( isset( $payload['acf'] ) && is_array( $payload['acf'] ) && function_exists( 'update_field' ) ) {
            foreach ( $payload['acf'] as $field_key => $value ) {
                if ( ! is_string( $field_key ) ) {
                    continue;
                }
                $clean_value = is_array( $value ) ? wp_unslash( $value ) : wp_unslash( (string) $value );
                update_field( $field_key, $clean_value, $revision_id );
            }
        }
    }

    private static function apply_payload_taxonomies( int $revision_id, array $payload ): void {
        if ( empty( $payload['tax_input'] ) || ! is_array( $payload['tax_input'] ) ) {
            return;
        }

        $snapshot = get_post_meta( $revision_id, Plugin::META_TAXONOMIES, true );
        if ( ! is_array( $snapshot ) ) {
            $snapshot = [];
        }

        foreach ( $payload['tax_input'] as $taxonomy => $terms ) {
            if ( $terms === '' || $terms === null ) {
                $snapshot[ $taxonomy ] = [];
                continue;
            }

            if ( is_string( $terms ) ) {
                $terms = array_filter( array_map( 'trim', explode( ',', $terms ) ) );
            }

            if ( is_array( $terms ) ) {
                $snapshot[ $taxonomy ] = array_values( $terms );
            }
        }

        update_post_meta( $revision_id, Plugin::META_TAXONOMIES, $snapshot );
    }

    private static function summarize_stack_trace( \Throwable $throwable ): array {
        $trace_lines = explode( "\n", $throwable->getTraceAsString() );
        $trace_lines = array_slice( $trace_lines, 0, 5 );
        return array_values( array_filter( $trace_lines, 'is_string' ) );
    }

    private static function format_exception_message( \Throwable $throwable ): string {
        $message = trim( $throwable->getMessage() );
        if ( $message === '' ) {
            $message = 'Erro desconhecido ao aplicar revisao.';
        }

        return $throwable->getFile() . ':' . $throwable->getLine() . ' - ' . $message;
    }

    public static function persist_last_error( string $message ): void {
        $message = trim( $message );
        if ( $message === '' ) {
            return;
        }

        update_option( 'sanar_wcps_last_error', $message, false );
        set_transient( 'sanar_wcps_last_error', $message, DAY_IN_SECONDS );
    }
}
