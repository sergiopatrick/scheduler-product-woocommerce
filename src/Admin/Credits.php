<?php
/**
 * Admin credit surfaces for plugin attribution.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Admin;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionTypeCompat;

class Credits {
    public static function init(): void {
        add_filter( 'plugin_row_meta', [ __CLASS__, 'plugin_row_meta' ], 10, 2 );
        add_action( 'admin_footer', [ __CLASS__, 'render_admin_footer' ], 99 );
    }

    public static function plugin_row_meta( array $links, string $file ): array {
        $plugin_file = plugin_basename( SANAR_WCPS_PATH . 'sanar-wc-product-scheduler.php' );
        if ( $file !== $plugin_file ) {
            return $links;
        }

        $links[] = '<a href="' . esc_url( 'mailto:' . Plugin::author_email() ) . '">' . esc_html__( 'Autor', 'sanar-wc-product-scheduler' ) . '</a>';

        $whatsapp_digits = self::whatsapp_digits();
        if ( $whatsapp_digits !== '' ) {
            $links[] = '<a href="' . esc_url( 'https://wa.me/' . $whatsapp_digits ) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>';
        } else {
            $links[] = esc_html( Plugin::author_whatsapp() );
        }

        return $links;
    }

    public static function render_admin_footer(): void {
        if ( ! self::is_plugin_screen() ) {
            return;
        }

        $footer_text = 'Desenvolvido por ' . Plugin::author_credits();
        echo '<div class="sanar-wcps-author-credits" style="margin-top:20px;font-size:12px;line-height:1.5;opacity:.72;">';
        echo esc_html( $footer_text );
        echo '</div>';

        echo "\n<!-- Sanar WC Product Scheduler — Engineered by " . esc_html( Plugin::author_credits() ) . " -->\n";
    }

    private static function is_plugin_screen(): bool {
        if ( ! is_admin() ) {
            return false;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( strpos( $page, 'sanar-wcps' ) === 0 ) {
            return true;
        }

        $screen = get_current_screen();
        if ( ! ( $screen instanceof \WP_Screen ) ) {
            return false;
        }

        return RevisionTypeCompat::is_compatible_post_type( (string) $screen->post_type );
    }

    private static function whatsapp_digits(): string {
        $digits = preg_replace( '/\D+/', '', Plugin::author_whatsapp() );
        return is_string( $digits ) ? $digits : '';
    }
}
