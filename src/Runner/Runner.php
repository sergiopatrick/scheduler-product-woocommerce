<?php

namespace Sanar\WCProductScheduler\Runner;

use Sanar\WCProductScheduler\Plugin;
use Sanar\WCProductScheduler\Revision\RevisionManager;
use Sanar\WCProductScheduler\Util\Logger;

class Runner {
    public const DEFAULT_BATCH_SIZE = 50;
    public const LOCK_TTL_SECONDS = 120;

    public static function run_due_now( int $limit = self::DEFAULT_BATCH_SIZE ): array {
        $limit = max( 1, $limit );
        $revision_ids = self::find_due_revision_ids( $limit );

        $result = [
            'due' => count( $revision_ids ),
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'locked' => 0,
            'ids' => [],
        ];

        foreach ( $revision_ids as $revision_id ) {
            $result['ids'][] = $revision_id;
            $run = self::run_revision( (int) $revision_id );
            if ( $run['status'] === 'locked' ) {
                $result['locked']++;
                continue;
            }
            if ( $run['status'] === 'published' ) {
                $result['published']++;
                $result['processed']++;
                continue;
            }
            if ( $run['status'] === 'failed' ) {
                $result['failed']++;
                $result['processed']++;
                continue;
            }
        }

        return $result;
    }

    public static function run_revision( int $revision_id ): array {
        $result = [
            'revision_id' => $revision_id,
            'status' => 'failed',
            'message' => '',
        ];

        if ( $revision_id <= 0 ) {
            $result['message'] = 'revision_id invalido.';
            return $result;
        }

        $revision = get_post( $revision_id );
        if ( ! $revision || $revision->post_type !== Plugin::CPT ) {
            $result['message'] = 'Revisao nao encontrada.';
            self::mark_failed( $revision_id, $result['message'], [ 'reason' => 'revision_not_found' ] );
            return $result;
        }

        $status = (string) get_post_meta( $revision_id, Plugin::META_STATUS, true );
        if ( in_array( $status, [ Plugin::STATUS_CANCELLED, Plugin::STATUS_PUBLISHED ], true ) ) {
            $result['status'] = 'skipped';
            $result['message'] = 'Status nao executavel: ' . $status;
            Logger::log_event( $revision_id, 'skipped_status', [ 'status' => $status ] );
            return $result;
        }

        $parent_id = (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true );
        if ( $parent_id <= 0 ) {
            $result['message'] = 'Revisao sem parent_product_id valido.';
            self::mark_failed( $revision_id, $result['message'], [ 'reason' => 'missing_parent_product_id' ] );
            return $result;
        }

        if ( ! self::acquire_product_lock( $parent_id ) ) {
            $result['status'] = 'locked';
            $result['message'] = 'Lock ativo para o produto.';
            Logger::log_event( $revision_id, 'skipped_lock', [ 'product_id' => $parent_id ] );
            return $result;
        }

        try {
            $applied = RevisionManager::apply_revision( $revision_id );
            if ( $applied ) {
                $result['status'] = 'published';
                $result['message'] = 'Revisao publicada com sucesso.';
                return $result;
            }

            $result['status'] = 'failed';
            $error = (string) get_post_meta( $revision_id, Plugin::META_ERROR, true );
            $result['message'] = $error !== '' ? $error : 'Falha ao aplicar revisao.';
            return $result;
        } catch ( \Throwable $throwable ) {
            $result['status'] = 'failed';
            $result['message'] = self::throwable_message( $throwable );

            self::mark_failed(
                $revision_id,
                $result['message'],
                [ 'stack' => self::summarize_stack_trace( $throwable ) ]
            );
            return $result;
        } finally {
            self::release_product_lock( $parent_id );
        }
    }

    public static function list_scheduled( int $limit = self::DEFAULT_BATCH_SIZE ): array {
        $limit = max( 1, $limit );

        $query = new \WP_Query( [
            'post_type' => Plugin::CPT,
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_key' => Plugin::META_SCHEDULED_DATETIME,
            'meta_query' => [
                [
                    'key' => Plugin::META_STATUS,
                    'value' => Plugin::STATUS_SCHEDULED,
                    'compare' => '=',
                ],
            ],
        ] );

        if ( empty( $query->posts ) ) {
            return [];
        }

        $rows = [];
        foreach ( $query->posts as $revision_id ) {
            $revision_id = (int) $revision_id;
            $rows[] = [
                'revision_id' => $revision_id,
                'product_id' => (int) get_post_meta( $revision_id, Plugin::META_PARENT_ID, true ),
                'scheduled_utc' => (string) get_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, true ),
                'status' => (string) get_post_meta( $revision_id, Plugin::META_STATUS, true ),
            ];
        }

        return $rows;
    }

    public static function retry_revision( int $revision_id ): bool {
        if ( $revision_id <= 0 ) {
            return false;
        }

        $revision = get_post( $revision_id );
        if ( ! $revision || $revision->post_type !== Plugin::CPT ) {
            return false;
        }

        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_SCHEDULED );
        update_post_meta( $revision_id, Plugin::META_SCHEDULED_DATETIME, gmdate( 'Y-m-d H:i:s' ) );
        delete_post_meta( $revision_id, Plugin::META_ERROR );
        Logger::log_event( $revision_id, 'retry_scheduled', [ 'scheduled_utc' => gmdate( 'Y-m-d H:i:s' ) ] );

        return true;
    }

    private static function find_due_revision_ids( int $limit ): array {
        $now_utc = gmdate( 'Y-m-d H:i:s' );

        $query = new \WP_Query( [
            'post_type' => Plugin::CPT,
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_key' => Plugin::META_SCHEDULED_DATETIME,
            'meta_query' => [
                [
                    'key' => Plugin::META_STATUS,
                    'value' => Plugin::STATUS_SCHEDULED,
                    'compare' => '=',
                ],
                [
                    'key' => Plugin::META_SCHEDULED_DATETIME,
                    'value' => $now_utc,
                    'compare' => '<=',
                    'type' => 'DATETIME',
                ],
            ],
        ] );

        if ( empty( $query->posts ) ) {
            return [];
        }

        return array_map( 'intval', $query->posts );
    }

    private static function acquire_product_lock( int $product_id ): bool {
        $key = self::lock_key( $product_id );
        $now = time();

        if ( add_option( $key, (string) $now, '', 'no' ) ) {
            return true;
        }

        $current = (int) get_option( $key );
        if ( $current > 0 && ( $now - $current ) < self::LOCK_TTL_SECONDS ) {
            return false;
        }

        delete_option( $key );
        return add_option( $key, (string) $now, '', 'no' );
    }

    private static function release_product_lock( int $product_id ): void {
        delete_option( self::lock_key( $product_id ) );
    }

    private static function lock_key( int $product_id ): string {
        return 'sanar_wcps_lock_' . $product_id;
    }

    private static function mark_failed( int $revision_id, string $message, array $context = [] ): void {
        update_post_meta( $revision_id, Plugin::META_STATUS, Plugin::STATUS_FAILED );
        Logger::set_error( $revision_id, $message );
        Logger::log_event( $revision_id, 'failed', array_merge( [ 'error' => $message ], $context ) );
    }

    private static function throwable_message( \Throwable $throwable ): string {
        $message = trim( $throwable->getMessage() );
        if ( $message === '' ) {
            $message = 'Erro desconhecido ao executar runner.';
        }

        return $throwable->getFile() . ':' . $throwable->getLine() . ' - ' . $message;
    }

    private static function summarize_stack_trace( \Throwable $throwable ): array {
        $trace_lines = explode( "\n", $throwable->getTraceAsString() );
        $trace_lines = array_slice( $trace_lines, 0, 5 );
        return array_values( array_filter( $trace_lines, 'is_string' ) );
    }
}
