<?php
/**
 * About page for plugin metadata and attribution.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;

class AboutPage {
    public const MENU_SLUG = 'sanar-wcps-about';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    public static function register_menu(): void {
        $capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

        add_submenu_page(
            'woocommerce',
            __( 'Sobre', 'sanar-wc-product-scheduler' ),
            __( 'Sobre', 'sanar-wc-product-scheduler' ),
            $capability,
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! SchedulesPage::can_manage() ) {
            wp_die( 'Permissao insuficiente.' );
        }

        $name = Plugin::author_name();
        $email = Plugin::author_email();
        $whatsapp = Plugin::author_whatsapp();
        $version = defined( 'SANAR_WCPS_VERSION' ) ? (string) SANAR_WCPS_VERSION : '0.0.0';

        echo '<div class="wrap sanar-wcps-about-page">';
        echo '<h1>' . esc_html__( 'Sobre o Sanar WC Product Scheduler', 'sanar-wc-product-scheduler' ) . '</h1>';
        echo '<p>' . esc_html__( 'Plugin interno para agendamento e publicacao confiavel de revisoes de produto no WooCommerce, com foco em rastreabilidade e operacao segura.', 'sanar-wc-product-scheduler' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:760px;">';
        echo '<tbody>';
        echo '<tr><th style="width:220px;">' . esc_html__( 'Autor', 'sanar-wc-product-scheduler' ) . '</th><td>' . esc_html( $name ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Email', 'sanar-wc-product-scheduler' ) . '</th><td><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a></td></tr>';
        echo '<tr><th>WhatsApp</th><td>' . esc_html( $whatsapp ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Versao do plugin', 'sanar-wc-product-scheduler' ) . '</th><td>' . esc_html( $version ) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}
