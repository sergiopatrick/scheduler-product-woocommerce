<?php
/**
 * Product list custom columns.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;

class ProductListColumn {
    private const COLUMN_KEY = 'sanar_wcps_next_schedule';
    private static ?array $scheduled_map = null;

    public static function init(): void {
        add_filter( 'manage_edit-product_columns', [ __CLASS__, 'add_column' ] );
        add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_action( 'load-edit.php', [ __CLASS__, 'setup_product_screen_bootstrap' ] );
    }

    public static function add_column( array $columns ): array {
        $insert_after = isset( $columns['price'] ) ? 'price' : 'date';
        $inserted = false;
        $updated = [];

        foreach ( $columns as $key => $label ) {
            $updated[ $key ] = $label;

            if ( ! $inserted && $key === $insert_after ) {
                $updated[ self::COLUMN_KEY ] = __( 'Agendamento', 'sanar-wc-product-scheduler' );
                $inserted = true;
            }
        }

        if ( ! $inserted ) {
            $updated[ self::COLUMN_KEY ] = __( 'Agendamento', 'sanar-wc-product-scheduler' );
        }

        return $updated;
    }

    public static function render_column( string $column, int $post_id ): void {
        if ( $column !== self::COLUMN_KEY ) {
            return;
        }

        if ( self::$scheduled_map === null ) {
            self::prime_request_cache();
        }

        $next = self::$scheduled_map[ $post_id ] ?? null;
        if ( ! is_array( $next ) ) {
            echo '&mdash;';
            return;
        }

        $scheduled_ts = (int) ( $next['scheduled_timestamp'] ?? 0 );
        $revision_id = (int) ( $next['revision_id'] ?? 0 );
        if ( $scheduled_ts <= 0 ) {
            echo '&mdash;';
            return;
        }

        $scheduled_utc = Plugin::scheduled_timestamp_to_utc( $scheduled_ts );
        $local_label = Plugin::format_scheduled_local( $scheduled_ts, 'd/m/Y H:i' );
        if ( $local_label === '' ) {
            $local_label = $scheduled_utc;
        }

        echo '<span title="' . esc_attr( 'UTC: ' . $scheduled_utc ) . '">';
        echo esc_html__( 'Agendado:', 'sanar-wc-product-scheduler' ) . ' ' . esc_html( $local_label );
        echo '</span><br>';

        echo SchedulesPage::status_badge( Plugin::STATUS_SCHEDULED ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( $scheduled_ts <= time() ) {
            echo ' ' . self::overdue_badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        $view_url = self::revision_view_url( $revision_id );
        if ( $view_url !== '' ) {
            echo '<div class="row-actions"><span><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'Ver', 'sanar-wc-product-scheduler' ) . '</a></span></div>';
        }
    }

    public static function setup_product_screen_bootstrap(): void {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
        if ( $post_type !== 'product' ) {
            return;
        }

        add_action( 'admin_head-edit.php', [ __CLASS__, 'prime_request_cache' ], 1 );
    }

    public static function prime_request_cache(): void {
        if ( self::$scheduled_map !== null ) {
            return;
        }

        if ( ! self::is_product_list_screen() ) {
            self::$scheduled_map = [];
            return;
        }

        $product_ids = self::collect_page_product_ids();
        self::$scheduled_map = self::query_next_scheduled_map( $product_ids );
    }

    private static function is_product_list_screen(): bool {
        $screen = get_current_screen();
        if ( ! ( $screen instanceof \WP_Screen ) ) {
            return false;
        }

        return $screen->base === 'edit' && $screen->post_type === 'product';
    }

    private static function collect_page_product_ids(): array {
        global $wp_query;

        if ( ! ( $wp_query instanceof \WP_Query ) || empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
            return [];
        }

        $ids = [];
        foreach ( $wp_query->posts as $post ) {
            if ( $post instanceof \WP_Post ) {
                if ( $post->post_type !== 'product' ) {
                    continue;
                }
                $ids[] = (int) $post->ID;
                continue;
            }

            if ( is_numeric( $post ) ) {
                $id = (int) $post;
                if ( $id > 0 ) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        return $ids;
    }

    private static function query_next_scheduled_map( array $product_ids ): array {
        $product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
        if ( empty( $product_ids ) ) {
            return [];
        }

        $post_types = RevisionTypeCompat::compatible_types();
        if ( empty( $post_types ) ) {
            return [];
        }

        global $wpdb;

        $post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $product_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        $sql_next_by_parent = "
            SELECT
                CAST(pm_parent.meta_value AS UNSIGNED) AS parent_id,
                MIN(CAST(pm_scheduled.meta_value AS UNSIGNED)) AS scheduled_timestamp
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_parent
                ON pm_parent.post_id = p.ID
               AND pm_parent.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_status
                ON pm_status.post_id = p.ID
               AND pm_status.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_scheduled
                ON pm_scheduled.post_id = p.ID
               AND pm_scheduled.meta_key = %s
            WHERE p.post_type IN ($post_type_placeholders)
              AND p.post_status <> 'trash'
              AND pm_status.meta_value = %s
              AND pm_scheduled.meta_value REGEXP '^[0-9]+$'
              AND CAST(pm_parent.meta_value AS UNSIGNED) IN ($product_placeholders)
            GROUP BY CAST(pm_parent.meta_value AS UNSIGNED)
        ";

        $base_args = array_merge(
            [
                Plugin::META_PARENT_ID,
                Plugin::META_STATUS,
                Plugin::META_SCHEDULED_DATETIME,
            ],
            $post_types,
            [ Plugin::STATUS_SCHEDULED ],
            $product_ids
        );

        $prepared_next = $wpdb->prepare( $sql_next_by_parent, $base_args );
        if ( ! is_string( $prepared_next ) ) {
            return [];
        }

        $next_rows = $wpdb->get_results( $prepared_next, ARRAY_A );
        if ( ! is_array( $next_rows ) || empty( $next_rows ) ) {
            return [];
        }

        $next_by_parent = [];
        foreach ( $next_rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $parent_id = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;
            if ( $parent_id <= 0 ) {
                continue;
            }

            $scheduled = isset( $row['scheduled_timestamp'] ) ? (int) $row['scheduled_timestamp'] : 0;
            if ( $scheduled <= 0 ) {
                continue;
            }

            $next_by_parent[ $parent_id ] = $scheduled;
        }

        if ( empty( $next_by_parent ) ) {
            return [];
        }

        $next_parent_ids = array_keys( $next_by_parent );
        $next_timestamps = array_values( array_unique( array_map( 'intval', array_values( $next_by_parent ) ) ) );

        $next_parent_placeholders = implode( ',', array_fill( 0, count( $next_parent_ids ), '%d' ) );
        $next_timestamp_placeholders = implode( ',', array_fill( 0, count( $next_timestamps ), '%d' ) );

        $sql_revision_ids = "
            SELECT
                p.ID AS revision_id,
                CAST(pm_parent.meta_value AS UNSIGNED) AS parent_id,
                CAST(pm_scheduled.meta_value AS UNSIGNED) AS scheduled_timestamp
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_parent
                ON pm_parent.post_id = p.ID
               AND pm_parent.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_status
                ON pm_status.post_id = p.ID
               AND pm_status.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm_scheduled
                ON pm_scheduled.post_id = p.ID
               AND pm_scheduled.meta_key = %s
            WHERE p.post_type IN ($post_type_placeholders)
              AND p.post_status <> 'trash'
              AND pm_status.meta_value = %s
              AND pm_scheduled.meta_value REGEXP '^[0-9]+$'
              AND CAST(pm_parent.meta_value AS UNSIGNED) IN ($next_parent_placeholders)
              AND CAST(pm_scheduled.meta_value AS UNSIGNED) IN ($next_timestamp_placeholders)
            ORDER BY CAST(pm_parent.meta_value AS UNSIGNED) ASC, CAST(pm_scheduled.meta_value AS UNSIGNED) ASC, p.ID ASC
        ";

        $revision_args = array_merge(
            [
                Plugin::META_PARENT_ID,
                Plugin::META_STATUS,
                Plugin::META_SCHEDULED_DATETIME,
            ],
            $post_types,
            [ Plugin::STATUS_SCHEDULED ],
            $next_parent_ids,
            $next_timestamps
        );

        $prepared_revision = $wpdb->prepare( $sql_revision_ids, $revision_args );
        if ( ! is_string( $prepared_revision ) ) {
            return [];
        }

        $revision_rows = $wpdb->get_results( $prepared_revision, ARRAY_A );
        if ( ! is_array( $revision_rows ) || empty( $revision_rows ) ) {
            return [];
        }

        $map = [];
        foreach ( $revision_rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $parent_id = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;
            if ( $parent_id <= 0 || isset( $map[ $parent_id ] ) ) {
                continue;
            }

            $scheduled = isset( $row['scheduled_timestamp'] ) ? (int) $row['scheduled_timestamp'] : 0;
            if ( $scheduled <= 0 || ! isset( $next_by_parent[ $parent_id ] ) ) {
                continue;
            }

            if ( $scheduled !== $next_by_parent[ $parent_id ] ) {
                continue;
            }

            $map[ $parent_id ] = [
                'scheduled_timestamp' => $scheduled,
                'revision_id' => isset( $row['revision_id'] ) ? (int) $row['revision_id'] : 0,
                'status' => Plugin::STATUS_SCHEDULED,
            ];
        }

        return $map;
    }

    private static function revision_view_url( int $revision_id ): string {
        if ( $revision_id <= 0 ) {
            return '';
        }

        if ( SchedulesPage::can_manage() ) {
            return SchedulesPage::detail_url( $revision_id );
        }

        $edit_url = get_edit_post_link( $revision_id, '' );
        return is_string( $edit_url ) ? $edit_url : '';
    }

    private static function overdue_badge(): string {
        return '<span class="sanar-wcps-status sanar-wcps-status-overdue">' . esc_html__( 'Vencido', 'sanar-wc-product-scheduler' ) . '</span>';
    }
}
