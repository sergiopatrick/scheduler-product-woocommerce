<?php

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SchedulesTable extends \WP_List_Table {
    private array $active_filters = [];

    public function __construct() {
        parent::__construct( [
            'singular' => 'sanar_wcps_schedule',
            'plural' => 'sanar_wcps_schedules',
            'ajax' => false,
        ] );
    }

    public function get_active_filters(): array {
        return $this->active_filters;
    }

    public function get_columns(): array {
        return [
            'product' => __( 'Produto', 'sanar-wc-product-scheduler' ),
            'status' => __( 'Status', 'sanar-wc-product-scheduler' ),
            'scheduled' => __( 'Agendado Para', 'sanar-wc-product-scheduler' ),
            'created' => __( 'Criado Por / Em', 'sanar-wc-product-scheduler' ),
            'executed' => __( 'Ultima Execucao', 'sanar-wc-product-scheduler' ),
            'error' => __( 'Erro', 'sanar-wc-product-scheduler' ),
            'actions' => __( 'Acoes', 'sanar-wc-product-scheduler' ),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'scheduled' => [ 'scheduled', false ],
            'created' => [ 'created', false ],
            'status' => [ 'status', false ],
        ];
    }

    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $this->active_filters = [
            'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
            'date_range' => isset( $_GET['date_range'] ) ? sanitize_key( wp_unslash( $_GET['date_range'] ) ) : '',
            'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
            'date_to' => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
            'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'per_page' => isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 50,
            'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'scheduled',
            'order' => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',
        ];

        if ( ! in_array( $this->active_filters['per_page'], [ 50, 100 ], true ) ) {
            $this->active_filters['per_page'] = 50;
        }
        if ( ! in_array( $this->active_filters['order'], [ 'asc', 'desc' ], true ) ) {
            $this->active_filters['order'] = 'asc';
        }
        if ( ! in_array( $this->active_filters['orderby'], [ 'scheduled', 'created', 'status' ], true ) ) {
            $this->active_filters['orderby'] = 'scheduled';
        }

        $query_args = $this->build_query_args();
        $query = new \WP_Query( $query_args );

        $items = [];
        foreach ( $query->posts as $revision_id ) {
            $data = SchedulesPage::build_revision_data( (int) $revision_id );
            if ( $data ) {
                $items[] = $data;
            }
        }

        $this->items = $items;

        $this->set_pagination_args( [
            'total_items' => (int) $query->found_posts,
            'per_page' => $this->active_filters['per_page'],
            'total_pages' => max( 1, (int) ceil( $query->found_posts / $this->active_filters['per_page'] ) ),
        ] );
    }

    protected function column_default( $item, $column_name ): string {
        switch ( $column_name ) {
            case 'product':
                return $this->column_product( $item );
            case 'status':
                return SchedulesPage::status_badge( (string) $item['status'] );
            case 'scheduled':
                return $this->column_scheduled( $item );
            case 'created':
                return $this->column_created( $item );
            case 'executed':
                return $this->column_executed( $item );
            case 'error':
                return $this->column_error( $item );
            case 'actions':
                return $this->column_actions( $item );
            default:
                return '';
        }
    }

    public function no_items(): void {
        esc_html_e( 'Nenhum agendamento encontrado.', 'sanar-wc-product-scheduler' );
    }

    private function build_query_args(): array {
        $meta_query = [];

        if ( $this->active_filters['status'] !== '' ) {
            $meta_query[] = [
                'key' => Plugin::META_STATUS,
                'value' => $this->active_filters['status'],
                'compare' => '=',
            ];
        }

        $date_filter = $this->build_date_meta_filter();
        if ( $date_filter ) {
            $meta_query[] = $date_filter;
        }

        $search_parent_ids = $this->find_parent_ids_by_search( $this->active_filters['search'] );
        if ( $search_parent_ids === [] && $this->active_filters['search'] !== '' ) {
            return [
                'post_type' => RevisionTypeCompat::compatible_types(),
                'post_status' => 'any',
                'posts_per_page' => $this->active_filters['per_page'],
                'paged' => max( 1, $this->get_pagenum() ),
                'fields' => 'ids',
                'post__in' => [ 0 ],
            ];
        }

        if ( $search_parent_ids !== [] ) {
            $meta_query[] = [
                'key' => Plugin::META_PARENT_ID,
                'value' => $search_parent_ids,
                'compare' => 'IN',
                'type' => 'NUMERIC',
            ];
        }

        $args = [
            'post_type' => RevisionTypeCompat::compatible_types(),
            'post_status' => 'any',
            'posts_per_page' => $this->active_filters['per_page'],
            'paged' => max( 1, $this->get_pagenum() ),
            'fields' => 'ids',
            'meta_query' => $meta_query,
        ];

        $order = strtoupper( $this->active_filters['order'] );
        if ( $this->active_filters['orderby'] === 'created' ) {
            $args['orderby'] = 'date';
            $args['order'] = $order;
        } elseif ( $this->active_filters['orderby'] === 'status' ) {
            $args['meta_key'] = Plugin::META_STATUS;
            $args['orderby'] = 'meta_value';
            $args['order'] = $order;
        } else {
            $args['meta_key'] = Plugin::META_SCHEDULED_DATETIME;
            $args['orderby'] = 'meta_value_num';
            $args['order'] = $order;
        }

        return $args;
    }

    private function build_date_meta_filter(): array {
        $range = $this->active_filters['date_range'];
        if ( $range === '' ) {
            return [];
        }

        $from = 0;
        $to = 0;
        $now = time();

        if ( $range === 'today' ) {
            $timezone = wp_timezone();
            $local_now = new \DateTimeImmutable( 'now', $timezone );
            $start_local = $local_now->setTime( 0, 0, 0 );
            $end_local = $local_now->setTime( 23, 59, 59 );
            $from = $start_local->setTimezone( new \DateTimeZone( 'UTC' ) )->getTimestamp();
            $to = $end_local->setTimezone( new \DateTimeZone( 'UTC' ) )->getTimestamp();
        } elseif ( $range === '7d' ) {
            $from = $now;
            $to = strtotime( '+7 days', $now );
        } elseif ( $range === '30d' ) {
            $from = $now;
            $to = strtotime( '+30 days', $now );
        } elseif ( $range === 'custom' ) {
            $from = $this->local_date_to_timestamp_utc( $this->active_filters['date_from'], false );
            $to = $this->local_date_to_timestamp_utc( $this->active_filters['date_to'], true );
        }

        if ( $from <= 0 || $to <= 0 ) {
            return [];
        }

        return [
            'key' => Plugin::META_SCHEDULED_DATETIME,
            'value' => [ $from, $to ],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ];
    }

    private function local_date_to_timestamp_utc( string $date, bool $end_of_day ): int {
        $date = trim( $date );
        if ( $date === '' ) {
            return 0;
        }

        $time = $end_of_day ? '23:59:59' : '00:00:00';
        $timezone = wp_timezone();
        $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $time, $timezone );
        if ( ! $dt ) {
            return 0;
        }

        $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
        return $dt->getTimestamp();
    }

    private function find_parent_ids_by_search( string $search ): array {
        $search = trim( $search );
        if ( $search === '' ) {
            return [];
        }

        if ( ctype_digit( $search ) ) {
            return [ (int) $search ];
        }

        $products = get_posts( [
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 200,
            'fields' => 'ids',
            's' => $search,
        ] );

        if ( empty( $products ) ) {
            return [];
        }

        return array_map( 'intval', $products );
    }

    private function column_product( array $item ): string {
        $title = esc_html( (string) $item['product_title'] );
        $parent_id = (int) $item['parent_id'];
        $is_orphan = ! empty( $item['is_orphan'] );
        $links = [];

        if ( ! empty( $item['product_edit_url'] ) ) {
            $links[] = '<a href="' . esc_url( (string) $item['product_edit_url'] ) . '">' . esc_html__( 'Editar', 'sanar-wc-product-scheduler' ) . '</a>';
        }
        if ( ! empty( $item['product_view_url'] ) ) {
            $links[] = '<a href="' . esc_url( (string) $item['product_view_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ver', 'sanar-wc-product-scheduler' ) . '</a>';
        }

        $links_html = '';
        if ( ! empty( $links ) ) {
            $links_html = '<div class="row-actions"><span>' . implode( ' | ', $links ) . '</span></div>';
        }

        $orphan_html = '';
        if ( $is_orphan ) {
            $orphan_html = '<br><span class="sanar-wcps-integrity-badge">ORPHAN</span>';
        }

        $parent_label = $parent_id > 0 ? '#' . $parent_id : '-';
        return '<strong>' . $title . '</strong><br><small>' . esc_html( (string) $parent_label ) . '</small>' . $orphan_html . $links_html;
    }

    private function column_scheduled( array $item ): string {
        $local = trim( (string) $item['scheduled_local'] );
        $utc = trim( (string) $item['scheduled_utc'] );
        $timezone = trim( (string) $item['timezone'] );

        if ( $local === '' && $utc === '' ) {
            return '-';
        }

        $label = $local !== '' ? $local : $utc;
        $out = '<span title="UTC: ' . esc_attr( $utc ) . '">' . esc_html( $label ) . '</span>';

        if ( $timezone !== '' ) {
            $out .= '<br><small>' . esc_html( $timezone ) . '</small>';
        }
        if ( $utc !== '' ) {
            $out .= '<br><small>UTC: ' . esc_html( $utc ) . '</small>';
        }

        return $out;
    }

    private function column_created( array $item ): string {
        $name = esc_html( (string) $item['created_by_name'] );
        $created = trim( (string) $item['created_at_local'] );
        if ( $created === '' ) {
            $created = '-';
        }

        return $name . '<br><small>' . esc_html( $created ) . '</small>';
    }

    private function column_executed( array $item ): string {
        if ( (string) $item['status'] !== Plugin::STATUS_PUBLISHED ) {
            return '-';
        }

        $local = trim( (string) $item['published_at_local'] );
        $utc = trim( (string) $item['published_at_utc'] );
        if ( $local === '' && $utc === '' ) {
            return '-';
        }

        $label = $local !== '' ? $local : $utc;
        $out = esc_html( $label );
        if ( $utc !== '' ) {
            $out .= '<br><small>UTC: ' . esc_html( $utc ) . '</small>';
        }

        return $out;
    }

    private function column_error( array $item ): string {
        $integrity = trim( (string) ( $item['integrity_message'] ?? '' ) );
        if ( $integrity !== '' ) {
            return '<span title="' . esc_attr( $integrity ) . '">' . esc_html( SchedulesPage::short_error( $integrity ) ) . '</span>';
        }

        if ( (string) $item['status'] !== Plugin::STATUS_FAILED ) {
            return '-';
        }

        $error = trim( (string) $item['error'] );
        if ( $error === '' ) {
            return '-';
        }

        $short = SchedulesPage::short_error( $error );
        return '<span title="' . esc_attr( $error ) . '">' . esc_html( $short ) . '</span>';
    }

    private function column_actions( array $item ): string {
        $revision_id = (int) $item['revision_id'];
        $status = (string) $item['status'];
        $is_orphan = ! empty( $item['is_orphan'] );
        $actions = [];

        $actions[] = '<a class="button button-small" href="' . esc_url( SchedulesPage::detail_url( $revision_id ) ) . '">' . esc_html__( 'Ver', 'sanar-wc-product-scheduler' ) . '</a>';

        if ( SchedulesPage::can_cancel( $status ) ) {
            $actions[] = '<a class="button button-small" href="' . esc_url( SchedulesPage::action_url( 'cancel', $revision_id ) ) . '">' . esc_html__( 'Cancelar', 'sanar-wc-product-scheduler' ) . '</a>';
        }

        if ( SchedulesPage::can_reschedule( $status, $is_orphan ) ) {
            $actions[] = '<a class="button button-small" href="' . esc_url( SchedulesPage::detail_url( $revision_id ) . '#sanar-wcps-reschedule' ) . '">' . esc_html__( 'Reagendar', 'sanar-wc-product-scheduler' ) . '</a>';
        }

        if ( SchedulesPage::can_run_now( $status, $is_orphan ) ) {
            $actions[] = '<a class="button button-small button-primary" href="' . esc_url( SchedulesPage::action_url( 'run_now', $revision_id ) ) . '">' . esc_html__( 'Executar agora', 'sanar-wc-product-scheduler' ) . '</a>';
        } elseif ( $is_orphan ) {
            $actions[] = '<span class="description">ORPHAN</span>';
        }

        return implode( ' ', $actions );
    }
}
