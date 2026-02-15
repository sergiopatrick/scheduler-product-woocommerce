<?php
/**
 * Revision post type compatibility helper.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Revision;

use Sanar\WCProductScheduler\Plugin;

class RevisionTypeCompat {
    private const LEGACY_TRUNCATED = 'sanar_product_revisio';

    public static function canonical_type(): string {
        return Plugin::CPT;
    }

    public static function compatible_types(): array {
        $types = [
            self::canonical_type(),
            Plugin::CPT_LEGACY,
            self::LEGACY_TRUNCATED,
        ];

        $types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
        return $types;
    }

    public static function legacy_types(): array {
        return array_values(
            array_filter(
                self::compatible_types(),
                static fn( string $type ): bool => $type !== self::canonical_type()
            )
        );
    }

    public static function is_compatible_post_type( string $post_type ): bool {
        return in_array( $post_type, self::compatible_types(), true );
    }

    public static function is_compatible_revision_post( ?\WP_Post $post ): bool {
        if ( ! ( $post instanceof \WP_Post ) ) {
            return false;
        }

        return self::is_compatible_post_type( $post->post_type );
    }

    public static function is_canonical_post_type( string $post_type ): bool {
        return $post_type === self::canonical_type();
    }
}
