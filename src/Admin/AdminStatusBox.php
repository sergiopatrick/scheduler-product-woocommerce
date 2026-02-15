<?php

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Revision\RevisionPostType;

class AdminStatusBox {
    public static function init(): void {
        if ( ! defined( 'SANAR_WCPS_DIAG' ) || ! SANAR_WCPS_DIAG ) {
            return;
        }

        add_action( 'add_meta_boxes_product', [ __CLASS__, 'register_meta_box' ] );
    }

    public static function register_meta_box(): void {
        add_meta_box(
            'sanar-wcps-status-box',
            'Sanar WCPS Status',
            [ __CLASS__, 'render_meta_box' ],
            'product',
            'side',
            'high'
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        $product_id = (int) $post->ID;
        $last_error = get_option( 'sanar_wcps_last_error', '' );
        if ( ! is_string( $last_error ) || $last_error === '' ) {
            $last_error = get_transient( 'sanar_wcps_last_error' );
            if ( ! is_string( $last_error ) ) {
                $last_error = '';
            }
        }

        $checks = [
            'plugin_loaded' => defined( 'SANAR_WCPS_PLUGIN_LOADED' ) && SANAR_WCPS_PLUGIN_LOADED,
            "did_action('plugins_loaded')" => (int) did_action( 'plugins_loaded' ),
            "did_action('init')" => (int) did_action( 'init' ),
            'class_exists RevisionPostType' => class_exists( RevisionPostType::class ),
            'class_exists RevisionManager' => class_exists( RevisionManager::class ),
            "post_type_exists('sanar_product_revision')" => post_type_exists( 'sanar_product_revision' ),
            "post_type_exists('" . Plugin::CPT . "')" => post_type_exists( Plugin::CPT ),
            "current_user_can('edit_post', $product_id)" => current_user_can( 'edit_post', $product_id ),
        ];

        echo '<table class="widefat striped" style="margin-bottom:8px;">';
        echo '<tbody>';
        foreach ( $checks as $label => $value ) {
            echo '<tr>';
            echo '<td style="width:65%;"><code>' . esc_html( $label ) . '</code></td>';
            echo '<td>';
            if ( is_bool( $value ) ) {
                $color = $value ? '#1f7a1f' : '#b32d2e';
                echo '<strong style="color:' . esc_attr( $color ) . ';">' . ( $value ? 'true' : 'false' ) . '</strong>';
            } else {
                echo esc_html( (string) $value );
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';

        echo '<p style="margin:0 0 4px;"><strong>sanar_wcps_last_error</strong></p>';
        if ( $last_error === '' ) {
            echo '<p style="margin:0;">(vazio)</p>';
            return;
        }

        echo '<pre style="white-space:pre-wrap;word-break:break-word;max-height:180px;overflow:auto;margin:0;">' . esc_html( $last_error ) . '</pre>';
    }
}
