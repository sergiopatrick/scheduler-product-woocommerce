<?php
/**
 * Plugin Name: Sanar WC Product Scheduler
 * Description: Agenda atualizacoes completas de produtos WooCommerce via revisoes versionadas e WP-Cron.
 * Version: 0.1.0
 * Author: Sanar
 * Text Domain: sanar-wc-product-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[SANAR_WCPS] main file loaded' );
}

define( 'SANAR_WCPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SANAR_WCPS_URL', plugin_dir_url( __FILE__ ) );
define( 'SANAR_WCPS_VERSION', '0.1.0' );

add_action( 'plugins_loaded', 'sanar_wcps_bootstrap', 0 );

function sanar_wcps_bootstrap(): void {
    require_once SANAR_WCPS_PATH . 'src/Plugin.php';

    \Sanar\WCProductScheduler\Plugin::init();
}
