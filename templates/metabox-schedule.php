<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<p><?php echo esc_html__( 'Crie uma revisao completa e agende a publicacao.', 'sanar-wc-product-scheduler' ); ?></p>

<?php if ( ! empty( $next ) ) : ?>
    <p><strong><?php echo esc_html__( 'Atualizacao agendada para:', 'sanar-wc-product-scheduler' ); ?></strong><br>
        <?php echo esc_html( $next ); ?>
    </p>
<?php endif; ?>

<?php $nonce = wp_create_nonce( 'sanar_wcps_schedule_update' ); ?>
<input type="hidden" form="sanar_wcps_schedule_form" name="action" value="sanar_wcps_schedule_update">
<input type="hidden" form="sanar_wcps_schedule_form" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
<input type="hidden" form="sanar_wcps_schedule_form" name="product_id" value="<?php echo esc_attr( $post->ID ); ?>">
<input type="hidden" form="sanar_wcps_schedule_form" name="sanar_wcps_tz" value="<?php echo esc_attr( $timezone ); ?>">

<label for="sanar_wcps_datetime"><?php echo esc_html__( 'Data/hora (local)', 'sanar-wc-product-scheduler' ); ?></label>
<input type="datetime-local" id="sanar_wcps_datetime" name="sanar_wcps_datetime" form="sanar_wcps_schedule_form" class="sanar-wcps-field">
<p class="description">
    <?php echo esc_html__( 'Timezone:', 'sanar-wc-product-scheduler' ); ?> <?php echo esc_html( $timezone ); ?>
</p>

<p class="sanar-wcps-actions">
    <button type="submit" form="sanar_wcps_schedule_form" class="button button-primary"><?php echo esc_html__( 'Agendar atualizacao', 'sanar-wc-product-scheduler' ); ?></button>
</p>
