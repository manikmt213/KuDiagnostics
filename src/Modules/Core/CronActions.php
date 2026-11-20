<?php
/**
 * Tools: kuduy-cron-run, kuduy-cron-unschedule.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * WP-Cron actions (phase O, D76). An event is named by its hook and key (kuduy-cron-list; the key is WordPress' md5
 * of the serialised arguments, so the arguments themselves are never shown or typed). Run: a one-off of the same hook
 * and arguments is scheduled for now and WP-Cron is triggered (spawn_cron()), as WP Crontrol does — nothing runs
 * inside the MCP request. Unschedule: wp_unschedule_event(), with undo that schedules it again at its time and
 * recurrence. Site administrators only.
 */
final class CronActions implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode run | unschedule.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-cron-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'run' === $this->mode ? __( 'Run a scheduled task now', 'kuduy-mcp' ) : __( 'Remove a scheduled task', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'run' === $this->mode
			? 'Runs a WP-Cron event (hook and key from kuduy-cron-list) as soon as possible: a one-off copy with the same arguments is scheduled for now and WP-Cron is started in the background, as WP Crontrol does. Its regular schedule stays as it is. The task does whatever its plugin made it do (e.g. send emails, sync, clean up). Site administrators only.'
			: 'Removes one scheduled WP-Cron event (hook and key from kuduy-cron-list). A recurring event stops recurring; its plugin may schedule it again later. Undo with kuduy-snapshot-restore schedules it again at its time and recurrence. Site administrators only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'hook' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'key'  => array(
					'type'    => 'string',
					'pattern' => '^[0-9a-f]{32}$',
				),
			),
			'required'             => array( 'hook', 'key' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'hook'         => array( 'type' => 'string' ),
				'queued'       => array( 'type' => 'boolean' ),
				'removed'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'unschedule' === $this->mode,
			'idempotentHint'  => 'unschedule' === $this->mode,
			'openWorldHint'   => 'run' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
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
	public function is_available(): bool {
		return true;
	}

	/**
	 * The event with this hook and key (the next one when it occurs more than once).
	 *
	 * @param string $hook Hook.
	 * @param string $key  Key.
	 * @return array{time: int, schedule: string|false, args: array<int, mixed>, interval: int|null}
	 * @throws ToolError When there is no such event.
	 */
	private static function event( string $hook, string $key ): array {
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				$event = (array) $hooks[ $hook ][ $key ];
				return array(
					'time'     => (int) $time,
					'schedule' => $event['schedule'],
					'args'     => (array) $event['args'],
					'interval' => isset( $event['interval'] ) ? (int) $event['interval'] : null,
				);
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No scheduled event "' . $hook . '" with this key (see kuduy-cron-list).' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'unschedule' !== $this->mode ) {
			return null;
		}
		try {
			$event = self::event( (string) $input['hook'], (string) $input['key'] );
		} catch ( ToolError $e ) {
			return null;
		}
		return array(
			'object_type' => 'cron',
			'object_id'   => substr( (string) $input['hook'], 0, 60 ),
			'payload'     => array( 'hook' => (string) $input['hook'] ) + $event,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When WordPress refuses.
	 */
	public function restore( array $payload ): void {
		$hook = (string) $payload['hook'];
		$args = (array) $payload['args'];
		$time = max( time(), (int) $payload['time'] );
		$done = false === $payload['schedule']
			? wp_schedule_single_event( $time, $hook, $args, true )
			: wp_schedule_event( $time, (string) $payload['schedule'], $hook, $args, true );
		if ( is_wp_error( $done ) ) {
			throw new ToolError( 'WordPress did not schedule the event again: ' . wp_strip_all_tags( $done->get_error_message() ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the event is unknown or WordPress refuses.
	 */
	public function execute( array $input ): array {
		$hook  = (string) $input['hook'];
		$event = self::event( $hook, (string) $input['key'] );
		AuditLog::target( 'cron', substr( $hook, 0, 60 ) );
		if ( 'unschedule' === $this->mode ) {
			$done = wp_unschedule_event( $event['time'], $hook, $event['args'], true );
			if ( is_wp_error( $done ) ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not remove the event: ' . wp_strip_all_tags( $done->get_error_message() ) );
			}
			return array(
				'hook'    => $hook,
				'removed' => true,
			);
		}
		// A one-off at "now minus one": WordPress refuses a second identical one-off within ten minutes, and an
		// event already due runs on the next WP-Cron pass.
		$done = wp_schedule_single_event( time() - 1, $hook, $event['args'], true );
		if ( is_wp_error( $done ) ) {
			throw ToolError::of( ToolError::CONFLICT, 'WordPress did not queue it: ' . wp_strip_all_tags( $done->get_error_message() ) . ' (a run of this event may already be waiting).' );
		}
		$spawned = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? false : spawn_cron();
		return array(
			'hook'    => $hook,
			'queued'  => true,
			'started' => (bool) $spawned,
			'note'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron is disabled on this site; the event runs when the server\'s own cron calls wp-cron.php.' : 'WP-Cron was started in the background; the event runs within moments.',
		);
	}
}
