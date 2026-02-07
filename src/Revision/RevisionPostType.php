<?php

namespace Sanar\WCProductScheduler\Revision;

use Sanar\WCProductScheduler\Plugin;

class RevisionPostType {
    private static bool $registered = false;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register' ], 1 );
        add_action( 'init', [ __CLASS__, 'register_meta' ], 5 );
    }

    public static function register(): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SANAR_WCPS] register CPT called' );
        }

        if ( self::$registered ) {
            return;
        }

        if ( post_type_exists( Plugin::CPT ) ) {
            self::$registered = true;
            return;
        }

        $labels = [
            'name' => __( 'Revisoes de Produto', 'sanar-wc-product-scheduler' ),
            'singular_name' => __( 'Revisao de Produto', 'sanar-wc-product-scheduler' ),
            'menu_name' => __( 'Revisoes de Produto', 'sanar-wc-product-scheduler' ),
        ];

        register_post_type( Plugin::CPT, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'woocommerce',
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'show_in_rest' => false,
            'supports' => [ 'title', 'editor', 'excerpt' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_position' => 56,
        ] );

        self::$registered = true;
    }

    public static function register_meta(): void {
        $meta_args = [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => function () {
                return current_user_can( 'edit_products' );
            },
        ];

        register_post_meta( Plugin::CPT, Plugin::META_PARENT_ID, array_merge( $meta_args, [ 'type' => 'integer' ] ) );
        register_post_meta( Plugin::CPT, Plugin::META_SCHEDULED_DATETIME, $meta_args );
        register_post_meta( Plugin::CPT, Plugin::META_STATUS, $meta_args );
        register_post_meta( Plugin::CPT, Plugin::META_CREATED_BY, array_merge( $meta_args, [ 'type' => 'integer' ] ) );
        register_post_meta( Plugin::CPT, Plugin::META_LOG, array_merge( $meta_args, [ 'type' => 'array' ] ) );
        register_post_meta( Plugin::CPT, Plugin::META_ERROR, $meta_args );
        register_post_meta( Plugin::CPT, Plugin::META_TAXONOMIES, array_merge( $meta_args, [ 'type' => 'array' ] ) );
        register_post_meta( Plugin::CPT, Plugin::META_TIMEZONE, $meta_args );
    }
}
