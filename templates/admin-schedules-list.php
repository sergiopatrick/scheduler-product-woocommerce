<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap sanar-wcps-schedules-page">
    <h1><?php echo esc_html__( 'Agendamentos', 'sanar-wc-product-scheduler' ); ?></h1>

    <?php if ( ! empty( $telemetry['cron_missing'] ) ) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__( 'WP-Cron tick ausente: o evento sanar_wcps_tick nao esta agendado.', 'sanar-wc-product-scheduler' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $telemetry['cron_key_defined'] ) ) : ?>
        <div class="notice notice-warning">
            <p><?php echo esc_html__( 'Endpoint fallback bloqueado: defina SANAR_WCPS_CRON_KEY no wp-config.php.', 'sanar-wc-product-scheduler' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="notice notice-info sanar-wcps-runner-telemetry">
        <p>
            <strong><?php echo esc_html__( 'Ultima execucao do runner:', 'sanar-wc-product-scheduler' ); ?></strong>
            <?php echo esc_html( $telemetry['last_run_local'] !== '' ? $telemetry['last_run_local'] : '-' ); ?>
            <?php if ( $telemetry['last_run_utc'] !== '' ) : ?>
                <small><?php echo esc_html( ' (UTC: ' . $telemetry['last_run_utc'] . ')' ); ?></small>
            <?php endif; ?>
        </p>
        <p>
            <strong><?php echo esc_html__( 'Processados na ultima execucao:', 'sanar-wc-product-scheduler' ); ?></strong>
            <?php echo esc_html( (string) (int) $telemetry['last_run_count'] ); ?>
        </p>
        <p>
            <strong><?php echo esc_html__( 'Proxima execucao do WP-Cron (tick):', 'sanar-wc-product-scheduler' ); ?></strong>
            <?php echo esc_html( $telemetry['next_tick_local'] !== '' ? $telemetry['next_tick_local'] : '-' ); ?>
            <?php if ( $telemetry['next_tick_utc'] !== '' ) : ?>
                <small><?php echo esc_html( ' (UTC: ' . $telemetry['next_tick_utc'] . ')' ); ?></small>
            <?php endif; ?>
        </p>
        <p>
            <strong><?php echo esc_html__( 'URL fallback (mascarada):', 'sanar-wc-product-scheduler' ); ?></strong><br>
            <code><?php echo esc_html( (string) $telemetry['fallback_url_masked'] ); ?></code>
        </p>
    </div>

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
