<?php

namespace Sanar\WCProductScheduler\Util;

class Lock {
    private const TTL = 600;

    public static function acquire( int $product_id ): bool {
        $key = self::key( $product_id );

        if ( add_option( $key, time(), '', 'no' ) ) {
            return true;
        }

        $current = (int) get_option( $key );
        if ( $current && ( time() - $current ) > self::TTL ) {
            update_option( $key, time(), 'no' );
            return true;
        }

        return false;
    }

    public static function release( int $product_id ): void {
        delete_option( self::key( $product_id ) );
    }

    public static function key( int $product_id ): string {
        return 'sanar_wcps_lock_' . $product_id;
    }
}
