<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap sanar-wcps-schedules-page">
    <h1><?php echo esc_html__( 'Agendamentos', 'sanar-wc-product-scheduler' ); ?></h1>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr( \Sanar\WCProductScheduler\Admin\SchedulesPage::MENU_SLUG ); ?>">

        <div class="sanar-wcps-filters">
            <label>
                <?php echo esc_html__( 'Status', 'sanar-wc-product-scheduler' ); ?>
                <select name="status">
                    <option value=""><?php echo esc_html__( 'Todos', 'sanar-wc-product-scheduler' ); ?></option>
                    <option value="scheduled" <?php selected( $filters['status'], 'scheduled' ); ?>>scheduled</option>
                    <option value="published" <?php selected( $filters['status'], 'published' ); ?>>published</option>
                    <option value="failed" <?php selected( $filters['status'], 'failed' ); ?>>failed</option>
                    <option value="cancelled" <?php selected( $filters['status'], 'cancelled' ); ?>>cancelled</option>
                    <option value="draft" <?php selected( $filters['status'], 'draft' ); ?>>draft</option>
                </select>
            </label>

            <label>
                <?php echo esc_html__( 'Intervalo', 'sanar-wc-product-scheduler' ); ?>
                <select name="date_range">
                    <option value=""><?php echo esc_html__( 'Todos', 'sanar-wc-product-scheduler' ); ?></option>
                    <option value="today" <?php selected( $filters['date_range'], 'today' ); ?>><?php echo esc_html__( 'Hoje', 'sanar-wc-product-scheduler' ); ?></option>
                    <option value="7d" <?php selected( $filters['date_range'], '7d' ); ?>><?php echo esc_html__( 'Proximos 7 dias', 'sanar-wc-product-scheduler' ); ?></option>
                    <option value="30d" <?php selected( $filters['date_range'], '30d' ); ?>><?php echo esc_html__( 'Proximos 30 dias', 'sanar-wc-product-scheduler' ); ?></option>
                    <option value="custom" <?php selected( $filters['date_range'], 'custom' ); ?>><?php echo esc_html__( 'Customizado', 'sanar-wc-product-scheduler' ); ?></option>
                </select>
            </label>

            <label>
                <?php echo esc_html__( 'De', 'sanar-wc-product-scheduler' ); ?>
                <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
            </label>

            <label>
                <?php echo esc_html__( 'Ate', 'sanar-wc-product-scheduler' ); ?>
                <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
            </label>

            <label>
                <?php echo esc_html__( 'Por pagina', 'sanar-wc-product-scheduler' ); ?>
                <select name="per_page">
                    <option value="50" <?php selected( (int) $filters['per_page'], 50 ); ?>>50</option>
                    <option value="100" <?php selected( (int) $filters['per_page'], 100 ); ?>>100</option>
                </select>
            </label>

            <label>
                <?php echo esc_html__( 'Busca (produto ou ID)', 'sanar-wc-product-scheduler' ); ?>
                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
            </label>

            <button type="submit" class="button button-primary"><?php echo esc_html__( 'Filtrar', 'sanar-wc-product-scheduler' ); ?></button>
            <a class="button" href="<?php echo esc_url( \Sanar\WCProductScheduler\Admin\SchedulesPage::list_url() ); ?>"><?php echo esc_html__( 'Limpar', 'sanar-wc-product-scheduler' ); ?></a>
        </div>

        <?php $table->display(); ?>
    </form>
</div>
