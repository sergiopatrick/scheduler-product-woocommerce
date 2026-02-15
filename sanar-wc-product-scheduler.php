<?php
/**
 * Plugin Name: Sanar WC Product Scheduler
 * Description: Agenda atualizacoes completas de produtos WooCommerce via revisoes versionadas e runner WP-CLI.
 * Version: 0.1.0
 * Author: Sanar
 * Text Domain: sanar-wc-product-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SANAR_WCPS_DIAG' ) ) {
    define( 'SANAR_WCPS_DIAG', false );
}

if ( ! defined( 'SANAR_WCPS_PLUGIN_LOADED' ) ) {
    define( 'SANAR_WCPS_PLUGIN_LOADED', true );
}

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[SANAR_WCPS] main loaded' );
}

define( 'SANAR_WCPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SANAR_WCPS_URL', plugin_dir_url( __FILE__ ) );
define( 'SANAR_WCPS_VERSION', '0.1.0' );

require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Revision/RevisionPostType.php';
require_once __DIR__ . '/src/Revision/RevisionManager.php';
require_once __DIR__ . '/src/Scheduler/Scheduler.php';
require_once __DIR__ . '/src/Runner/Runner.php';
require_once __DIR__ . '/src/Admin/ProductMetaBox.php';
require_once __DIR__ . '/src/Admin/AdminStatusBox.php';
require_once __DIR__ . '/src/Util/Logger.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/src/Cli/Command.php';
    \WP_CLI::add_command( 'sanar-wcps', \Sanar\WCProductScheduler\Cli\Command::class );
}

add_action( 'init', [ '\\Sanar\\WCProductScheduler\\Revision\\RevisionPostType', 'register' ], 0 );

add_action( 'plugins_loaded', 'sanar_wcps_bootstrap', 0 );

function sanar_wcps_bootstrap(): void {
    \Sanar\WCProductScheduler\Plugin::init();
}
