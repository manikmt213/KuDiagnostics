<?php
/**
 * Tools: kuduy-backup-status, kuduy-backup-start.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Backup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Full-site backups through UpdraftPlus (phase O, D77; D48; source of UpdraftPlus 1.26.16). KuDiagnostics has no
 * backup engine of its own and never calls an operation snapshot a backup.
 *
 * Status: UpdraftPlus' backup history (UpdraftPlus_Backup_History::get_history(): when, what is in each set, size,
 * where it was sent, kept forever or not) and its running jobs (job data options updraft_jobdata_{nonce}).
 * Start: UpdraftPlus starts a backup by firing updraft_backupnow_backup_all (or _backup for files only,
 * _backup_database for the database only) with its options (nocloud, use_nonce, always_keep); its own command layer
 * ends the request (die), so the same event is scheduled for WP-Cron and WP-Cron started, as its scheduled backups
 * run. Restore is not offered: UpdraftPlus restores in several steps in its own screen and has no command for it.
 */
final class UpdraftBackups implements Tool {

	/**
	 * UpdraftPlus event per kind.
	 */
	private const EVENTS = array(
		'all'      => 'updraft_backupnow_backup_all',
		'files'    => 'updraft_backupnow_backup',
		'database' => 'updraft_backupnow_backup_database',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode status | start.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-backup-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'status' === $this->mode ? __( 'Backups (UpdraftPlus)', 'kuduy-mcp' ) : __( 'Start a backup (UpdraftPlus)', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'status' === $this->mode
			? 'Lists the site\'s UpdraftPlus backups (newest first): when, what each contains (database, plugins, themes, uploads, others), total size, where it was sent and whether it is kept forever; plus backups running now. Restoring is done in UpdraftPlus itself (Settings > UpdraftPlus Backups > Restore).'
			: 'Starts an UpdraftPlus backup: kind all (default), files or database; nocloud true keeps it on the server only (otherwise it goes to the remote storage set up in UpdraftPlus); always_keep true protects it from UpdraftPlus\' retention clean-up. The backup runs in the background through WP-Cron and can take minutes; follow it with kuduy-backup-status (job id = the returned nonce). The first call only describes it; call again with confirm: true to start. Site administrators only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'status' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'        => array(
					'type' => 'string',
					'enum' => array_keys( self::EVENTS ),
				),
				'nocloud'     => array( 'type' => 'boolean' ),
				'always_keep' => array( 'type' => 'boolean' ),
				'confirm'     => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'backups' => $list,
				'running' => $list,
				'started' => array( 'type' => 'boolean' ),
				'nonce'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'status' === $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'status' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return class_exists( 'UpdraftPlus_Backup_History' ) && isset( $GLOBALS['updraftplus'] ) && is_object( $GLOBALS['updraftplus'] ) ? true : 'UpdraftPlus is not active on this site (KuDiagnostics does backups through it and has no backup engine of its own).';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When UpdraftPlus does not allow it or WordPress does not schedule it.
	 */
	public function execute( array $input ): array {
		if ( ! \UpdraftPlus_Options::user_can_manage() ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'UpdraftPlus does not let this account manage backups.' );
		}
		if ( 'status' === $this->mode ) {
			return self::status( min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) ) );
		}
		$kind  = (string) ( $input['kind'] ?? 'all' );
		$cloud = empty( $input['nocloud'] );
		if ( empty( $input['confirm'] ) ) {
			return array(
				'started' => false,
				'plan'    => 'An UpdraftPlus backup of ' . ( 'all' === $kind ? 'the database and files' : 'the ' . $kind ) . ( $cloud ? ', sent to the remote storage set up in UpdraftPlus (if any)' : ', kept on this server only' ) . ( ! empty( $input['always_keep'] ) ? ', kept forever' : '' ) . '. It runs in the background and uses server resources for a few minutes. Call again with confirm: true to start.',
			);
		}
		$updraftplus = $GLOBALS['updraftplus'];
		$nonce       = (string) $updraftplus->backup_time_nonce();
		$options     = array(
			'nocloud'     => ! $cloud,
			'use_nonce'   => $nonce,
			'always_keep' => ! empty( $input['always_keep'] ),
		);
		AuditLog::target( 'backup', $nonce );
		$done = wp_schedule_single_event( time(), self::EVENTS[ $kind ], array( $options ), true );
		if ( is_wp_error( $done ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not schedule the backup: ' . wp_strip_all_tags( $done->get_error_message() ) );
		}
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( ! $disabled ) {
			spawn_cron();
		}
		return array(
			'started' => true,
			'nonce'   => $nonce,
			'note'    => $disabled ? 'Queued. WP-Cron is disabled on this site, so the backup starts when the server\'s own cron calls wp-cron.php.' : 'Queued and WP-Cron started; the backup runs in the background. Check kuduy-backup-status.',
		);
	}

	/**
	 * History and running jobs.
	 *
	 * @param int $limit Backups to return.
	 * @return array<string, mixed>
	 */
	private static function status( int $limit ): array {
		$updraftplus = $GLOBALS['updraftplus'];
		$backups     = array();
		foreach ( array_slice( (array) \UpdraftPlus_Backup_History::get_history(), 0, $limit, true ) as $time => $set ) {
			$set       = (array) $set;
			$parts     = array_values( array_intersect( array( 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins', 'wpcore' ), array_keys( $set ) ) );
			$backups[] = array(
				'time'        => gmdate( 'c', (int) $time ),
				'nonce'       => (string) ( $set['nonce'] ?? '' ),
				'contains'    => $parts,
				'size_bytes'  => method_exists( $updraftplus, 'get_total_backup_size' ) ? (int) $updraftplus->get_total_backup_size( $set ) : null,
				'sent_to'     => array_values( array_map( 'strval', (array) ( $set['service'] ?? array() ) ) ),
				'always_keep' => ! empty( $set['always_keep'] ),
				'label'       => wp_strip_all_tags( (string) ( $set['label'] ?? '' ) ),
			);
		}
		// Running = what UpdraftPlus' own screen shows (UpdraftPlus_Admin::print_active_jobs()): a job with its next
		// updraft_backup_resume event scheduled (arguments: resumption, job id). Job data left behind by a finished
		// or abandoned job is not a running backup (found live 2026-11-20).
		$running = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) ( $hooks['updraft_backup_resume'] ?? array() ) as $scheduled ) {
				$nonce = (string) ( $scheduled['args'][1] ?? '' );
				$data  = '' === $nonce ? array() : (array) $updraftplus->jobdata_getarray( $nonce );
				if ( empty( $data['backup_time'] ) ) {
					continue;
				}
				$running[] = array(
					'nonce'       => $nonce,
					'status'      => (string) ( $data['jobstatus'] ?? 'unknown' ),
					'since'       => gmdate( 'c', (int) $data['backup_time'] ),
					'next_resume' => gmdate( 'c', (int) $time ),
				);
			}
		}
		foreach ( self::EVENTS as $event ) {
			foreach ( (array) _get_cron_array() as $time => $hooks ) {
				foreach ( (array) ( $hooks[ $event ] ?? array() ) as $scheduled ) {
					$running[] = array(
						'nonce'  => (string) ( $scheduled['args'][0]['use_nonce'] ?? '' ),
						'status' => 'queued',
						'since'  => gmdate( 'c', (int) $time ),
					);
				}
			}
		}
		return array(
			'backups' => $backups,
			'running' => $running,
		);
	}
}
