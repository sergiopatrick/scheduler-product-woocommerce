<?php
/**
 * Legacy revision migration routines.
 *
 * @author Sérgio Patrick
 * @email sergio.patrick@outlook.com.br
 * @whatsapp +55 71 98391-1751
 */

namespace Sanar\WCProductScheduler\Revision;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Util\Logger;

class RevisionMigration {
    public const OPTION_STATE = 'sanar_wcps_migration_state';
    private const DEFAULT_BATCH_SIZE = 200;
    private static bool $ran_this_request = false;

    public static function run_on_demand_once_per_request(): array {
        if ( self::$ran_this_request ) {
            $state = get_option( self::OPTION_STATE, [] );
            return is_array( $state ) ? $state : [];
        }

        self::$ran_this_request = true;
        return self::run_batch( self::DEFAULT_BATCH_SIZE );
    }

    public static function run_batch( int $limit = self::DEFAULT_BATCH_SIZE ): array {
        global $wpdb;

        $limit = max( 1, $limit );
        $legacy_types = RevisionTypeCompat::legacy_types();
        $canonical_type = RevisionTypeCompat::canonical_type();

        $result = [
            'ran_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'limit' => $limit,
            'scanned' => 0,
            'migrated' => 0,
            'parent_fixed' => 0,
            'orphans' => 0,
            'errors' => 0,
        ];

        if ( empty( $legacy_types ) ) {
            update_option( self::OPTION_STATE, $result, false );
            return $result;
        }

        $placeholders = implode( ',', array_fill( 0, count( $legacy_types ), '%s' ) );
        $sql = "SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE post_type IN ($placeholders) ORDER BY ID ASC LIMIT %d";
        $query_args = array_merge( $legacy_types, [ $limit ] );
        $prepared = $wpdb->prepare( $sql, $query_args );

        if ( ! is_string( $prepared ) ) {
            $result['errors']++;
            update_option( self::OPTION_STATE, $result, false );
            return $result;
        }

        $rows = $wpdb->get_results( $prepared );
        if ( ! is_array( $rows ) ) {
            $result['errors']++;
            update_option( self::OPTION_STATE, $result, false );
            return $result;
        }

        foreach ( $rows as $row ) {
            if ( ! isset( $row->ID, $row->post_type, $row->post_parent ) ) {
                continue;
            }

            $revision_id = (int) $row->ID;
            $origin_post_type = (string) $row->post_type;
            $post_parent = (int) $row->post_parent;
            $result['scanned']++;

            $updated = $wpdb->update(
                $wpdb->posts,
                [ 'post_type' => $canonical_type ],
                [ 'ID' => $revision_id ],
                [ '%s' ],
                [ '%d' ]
            );

            if ( $updated === false ) {
                $result['errors']++;
                continue;
            }

            if ( $updated > 0 ) {
                $result['migrated']++;
                clean_post_cache( $revision_id );
                Logger::log_event( $revision_id, 'migration_post_type', [
                    'from' => $origin_post_type,
                    'to' => $canonical_type,
                ] );
            }

            $parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );
            if ( $parent_id <= 0 ) {
                if ( $post_parent > 0 ) {
                    $parent_post = get_post( $post_parent );
                    if ( $parent_post && $parent_post->post_type === 'product' ) {
                        update_post_meta( $revision_id, Plugin::META_PARENT_ID, $post_parent );
                        $result['parent_fixed']++;
                        continue;
                    }
                }

                $result['orphans']++;
                $message = 'Revisao orfa detectada na migracao: parent_product_id ausente.';
                update_post_meta( $revision_id, Plugin::META_ERROR, $message );
                Logger::log_event( $revision_id, 'orphan_detected', [
                    'source' => 'migration',
                    'origin_post_type' => $origin_post_type,
                    'post_parent' => $post_parent,
                ] );
            }
        }

        update_option( self::OPTION_STATE, $result, false );
        return $result;
    }
}
