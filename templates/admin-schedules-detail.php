<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$revision_id = (int) $revision['revision_id'];
$status = (string) $revision['status'];
$is_orphan = ! empty( $revision['is_orphan'] );
$integrity_message = isset( $revision['integrity_message'] ) ? trim( (string) $revision['integrity_message'] ) : '';
$integrity_label = $is_orphan ? 'ORPHAN' : 'INTEGRITY';
$can_cancel = \Sanar\WCProductScheduler\Admin\SchedulesPage::can_cancel( $status );
$can_run_now = \Sanar\WCProductScheduler\Admin\SchedulesPage::can_run_now( $status, $is_orphan );
$can_reschedule = \Sanar\WCProductScheduler\Admin\SchedulesPage::can_reschedule( $status, $is_orphan );
?>
<div class="wrap sanar-wcps-schedules-page sanar-wcps-schedules-detail">
    <h1>
        <?php echo esc_html__( 'Agendamento', 'sanar-wc-product-scheduler' ); ?>
        #<?php echo esc_html( (string) $revision_id ); ?>
    </h1>
    <p>
        <a href="<?php echo esc_url( \Sanar\WCProductScheduler\Admin\SchedulesPage::list_url() ); ?>">&larr; <?php echo esc_html__( 'Voltar para lista', 'sanar-wc-product-scheduler' ); ?></a>
    </p>

    <?php if ( $is_orphan || $integrity_message !== '' ) : ?>
        <div class="notice notice-warning sanar-wcps-integrity-notice">
            <p>
                <strong><?php echo esc_html( $integrity_label ); ?></strong>
                <?php if ( $integrity_message !== '' ) : ?>
                    - <?php echo esc_html( $integrity_message ); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <table class="widefat striped sanar-wcps-detail-table">
        <tbody>
            <tr>
                <th><?php echo esc_html__( 'Produto Pai', 'sanar-wc-product-scheduler' ); ?></th>
                <td>
                    <?php if ( ! empty( $revision['product_edit_url'] ) ) : ?>
                        <a href="<?php echo esc_url( (string) $revision['product_edit_url'] ); ?>"><?php echo esc_html( (string) $revision['product_title'] ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( (string) $revision['product_title'] ); ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $revision['product_view_url'] ) ) : ?>
                        | <a href="<?php echo esc_url( (string) $revision['product_view_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Ver', 'sanar-wc-product-scheduler' ); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Status', 'sanar-wc-product-scheduler' ); ?></th>
                <td><?php echo \Sanar\WCProductScheduler\Admin\SchedulesPage::status_badge( (string) $revision['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Post Type de origem', 'sanar-wc-product-scheduler' ); ?></th>
                <td><code><?php echo esc_html( (string) ( $revision['origin_post_type'] ?? '' ) ); ?></code></td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Agendado (local)', 'sanar-wc-product-scheduler' ); ?></th>
                <td>
                    <?php echo esc_html( (string) ( $revision['scheduled_local'] ?: '-' ) ); ?>
                    <?php if ( ! empty( $revision['timezone'] ) ) : ?>
                        (<?php echo esc_html( (string) $revision['timezone'] ); ?>)
                    <?php endif; ?>
                    <br>
                    <small>UTC: <?php echo esc_html( (string) ( $revision['scheduled_utc'] ?: '-' ) ); ?></small>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Criado por', 'sanar-wc-product-scheduler' ); ?></th>
                <td><?php echo esc_html( (string) $revision['created_by_name'] ); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Criado em', 'sanar-wc-product-scheduler' ); ?></th>
                <td>
                    <?php echo esc_html( (string) ( $revision['created_at_local'] ?: '-' ) ); ?>
                    <br>
                    <small>UTC: <?php echo esc_html( (string) ( $revision['created_at_utc'] ?: '-' ) ); ?></small>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Publicado em', 'sanar-wc-product-scheduler' ); ?></th>
                <td>
                    <?php echo esc_html( (string) ( $revision['published_at_local'] ?: '-' ) ); ?>
                    <?php if ( ! empty( $revision['published_at_utc'] ) ) : ?>
                        <br>
                        <small>UTC: <?php echo esc_html( (string) $revision['published_at_utc'] ); ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php echo esc_html__( 'Erro', 'sanar-wc-product-scheduler' ); ?></th>
                <td><?php echo esc_html( (string) ( $revision['error'] ?: '-' ) ); ?></td>
            </tr>
        </tbody>
    </table>

    <h2><?php echo esc_html__( 'Acoes', 'sanar-wc-product-scheduler' ); ?></h2>
    <div class="sanar-wcps-detail-actions">
        <?php if ( $can_cancel ) : ?>
            <a class="button" href="<?php echo esc_url( \Sanar\WCProductScheduler\Admin\SchedulesPage::action_url( 'cancel', $revision_id ) ); ?>"><?php echo esc_html__( 'Cancelar', 'sanar-wc-product-scheduler' ); ?></a>
        <?php endif; ?>

        <?php if ( $can_run_now ) : ?>
            <a class="button button-primary" href="<?php echo esc_url( \Sanar\WCProductScheduler\Admin\SchedulesPage::action_url( 'run_now', $revision_id ) ); ?>"><?php echo esc_html__( 'Executar agora', 'sanar-wc-product-scheduler' ); ?></a>
        <?php elseif ( $is_orphan ) : ?>
            <span class="description">ORPHAN: execucao bloqueada ate corrigir vinculo do produto pai.</span>
        <?php endif; ?>
    </div>

    <?php if ( $can_reschedule ) : ?>
        <h2 id="sanar-wcps-reschedule"><?php echo esc_html__( 'Reagendar', 'sanar-wc-product-scheduler' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sanar-wcps-reschedule-form">
            <input type="hidden" name="action" value="sanar_wcps_dashboard_reschedule">
            <input type="hidden" name="revision_id" value="<?php echo esc_attr( (string) $revision_id ); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( \Sanar\WCProductScheduler\Admin\SchedulesPage::detail_url( $revision_id ) ); ?>">
            <?php wp_nonce_field( 'sanar_wcps_dashboard_reschedule_' . $revision_id ); ?>

            <label for="sanar_wcps_datetime"><strong><?php echo esc_html__( 'Data/hora local', 'sanar-wc-product-scheduler' ); ?></strong></label>
            <input
                type="datetime-local"
                id="sanar_wcps_datetime"
                name="sanar_wcps_datetime"
                value="<?php echo esc_attr( ! empty( $revision['scheduled_local'] ) ? str_replace( ' ', 'T', (string) $revision['scheduled_local'] ) : '' ); ?>"
                required
            >
            <button type="submit" class="button button-primary"><?php echo esc_html__( 'Salvar reagendamento', 'sanar-wc-product-scheduler' ); ?></button>
        </form>
    <?php endif; ?>

    <h2><?php echo esc_html__( 'Log completo', 'sanar-wc-product-scheduler' ); ?></h2>
    <div class="sanar-wcps-log-view">
        <?php if ( empty( $revision['log'] ) ) : ?>
            <p><?php echo esc_html__( 'Sem eventos registrados.', 'sanar-wc-product-scheduler' ); ?></p>
        <?php else : ?>
            <pre><?php echo esc_html( wp_json_encode( $revision['log'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
        <?php endif; ?>
    </div>
</div>
