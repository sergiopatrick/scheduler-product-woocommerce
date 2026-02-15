<?php

namespace Sanar\WCProductScheduler\Cli;

use Sanar\WCProductScheduler\Runner\Runner;

class Command extends \WP_CLI_Command {
    /**
     * Run due revisions.
     *
     * ## OPTIONS
     *
     * [--due-now]
     * : Process only revisions scheduled up to now (UTC).
     *
     * [--limit=<limit>]
     * : Batch size. Default 50.
     *
     * @subcommand run
     */
    public function run( array $args, array $assoc_args ): void {
        if ( ! class_exists( '\WP_CLI' ) ) {
            return;
        }

        if ( ! isset( $assoc_args['due-now'] ) ) {
            \WP_CLI::error( 'Use --due-now.' );
            return;
        }

        $limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : Runner::DEFAULT_BATCH_SIZE;
        $result = Runner::run_due_now( $limit );

        \WP_CLI::log( 'due=' . $result['due'] );
        \WP_CLI::log( 'processed=' . $result['processed'] );
        \WP_CLI::log( 'published=' . $result['published'] );
        \WP_CLI::log( 'failed=' . $result['failed'] );
        \WP_CLI::log( 'locked=' . $result['locked'] );
        \WP_CLI::success( 'Runner finalizado.' );
    }

    /**
     * List scheduled revisions.
     *
     * ## OPTIONS
     *
     * [--scheduled]
     * : List revisions with scheduled status.
     *
     * [--limit=<limit>]
     * : Max rows. Default 50.
     *
     * @subcommand list
     */
    public function list_scheduled( array $args, array $assoc_args ): void {
        if ( ! class_exists( '\WP_CLI' ) ) {
            return;
        }

        if ( ! isset( $assoc_args['scheduled'] ) ) {
            \WP_CLI::error( 'Use --scheduled.' );
            return;
        }

        $limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : Runner::DEFAULT_BATCH_SIZE;
        $rows = Runner::list_scheduled( $limit );

        if ( empty( $rows ) ) {
            \WP_CLI::log( 'Nenhuma revisao scheduled.' );
            return;
        }

        \WP_CLI\Utils\format_items(
            'table',
            $rows,
            [ 'revision_id', 'product_id', 'scheduled_utc', 'status' ]
        );
    }

    /**
     * Retry a failed revision by marking it as scheduled now.
     *
     * ## OPTIONS
     *
     * <revision_id>
     * : Revision post ID.
     *
     * @subcommand retry
     */
    public function retry( array $args, array $assoc_args ): void {
        if ( ! class_exists( '\WP_CLI' ) ) {
            return;
        }

        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Informe <revision_id>.' );
            return;
        }

        $revision_id = (int) $args[0];
        $scheduled = Runner::retry_revision( $revision_id );
        if ( ! $scheduled ) {
            \WP_CLI::error( 'Nao foi possivel reagendar a revisao.' );
            return;
        }

        \WP_CLI::success( 'Revisao reagendada para processamento imediato.' );
    }
}
