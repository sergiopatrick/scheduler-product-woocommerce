<?php
/**
 * Admin diagnostics panel.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Revision\RevisionMigration;
use Sanar\WCProductScheduler\Revision\RevisionPostType;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;

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

        $canonical_types = [ RevisionTypeCompat::canonical_type() ];
        $legacy_types = RevisionTypeCompat::legacy_types();
        $scheduled_count_canonical = self::count_scheduled_by_types( $canonical_types );
        $scheduled_count_legacy = self::count_scheduled_by_types( $legacy_types );

        $migration_state = get_option( RevisionMigration::OPTION_STATE, [] );
        if ( ! is_array( $migration_state ) ) {
            $migration_state = [];
        }
        $migration_last_run = isset( $migration_state['ran_at_utc'] ) ? (string) $migration_state['ran_at_utc'] : '-';
        $migration_last_result = self::format_migration_result( $migration_state );

        $checks = [
            'plugin_loaded' => defined( 'SANAR_WCPS_PLUGIN_LOADED' ) && SANAR_WCPS_PLUGIN_LOADED,
            "did_action('plugins_loaded')" => (int) did_action( 'plugins_loaded' ),
            "did_action('init')" => (int) did_action( 'init' ),
            'class_exists RevisionPostType' => class_exists( RevisionPostType::class ),
            'class_exists RevisionManager' => class_exists( RevisionManager::class ),
            'class_exists RevisionTypeCompat' => class_exists( RevisionTypeCompat::class ),
            'class_exists RevisionMigration' => class_exists( RevisionMigration::class ),
            "post_type_exists('sanar_product_revision')" => post_type_exists( 'sanar_product_revision' ),
            "post_type_exists('" . Plugin::CPT . "')" => post_type_exists( Plugin::CPT ),
            "current_user_can('edit_post', $product_id)" => current_user_can( 'edit_post', $product_id ),
            'scheduled_count_canonical' => $scheduled_count_canonical,
            'scheduled_count_legacy' => $scheduled_count_legacy,
            'migration_last_run' => $migration_last_run,
            'migration_last_result' => $migration_last_result,
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

    private static function count_scheduled_by_types( array $post_types ): int {
        $post_types = array_values( array_filter( array_map( 'strval', $post_types ) ) );
        if ( empty( $post_types ) ) {
            return 0;
        }

        $query = new \WP_Query( [
            'post_type' => $post_types,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => [
                [
                    'key' => Plugin::META_STATUS,
                    'value' => Plugin::STATUS_SCHEDULED,
                    'compare' => '=',
                ],
            ],
        ] );

        return (int) $query->found_posts;
    }

    private static function format_migration_result( array $state ): string {
        if ( empty( $state ) ) {
            return '-';
        }

        $parts = [
            'scanned=' . (int) ( $state['scanned'] ?? 0 ),
            'migrated=' . (int) ( $state['migrated'] ?? 0 ),
            'parent_fixed=' . (int) ( $state['parent_fixed'] ?? 0 ),
            'orphans=' . (int) ( $state['orphans'] ?? 0 ),
            'errors=' . (int) ( $state['errors'] ?? 0 ),
        ];

        return implode( ' ', $parts );
    }
}
