<?php
/**
 * Tool: kuduy-snapshot-diff (phase J, D81).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Snapshots;
use Kuduy\Mcp\Mcp\Tool;

/**
 * What changed since a snapshot: the saved state against the object as it is now.
 */
final class SnapshotDiff implements Tool {

	/**
	 * Any JSON type.
	 */
	public const ANY = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-snapshot-diff';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Compare a snapshot with now', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Compares an undo snapshot (id from kuduy-snapshot-list or a _snapshot_id) with the same object as it is now: each changed, added or removed field by path, with the saved and current values (secrets masked, long values shortened). Use it before kuduy-snapshot-restore to show the user what undo would put back, or to check what a change did. Changes nothing. Allowed for the person who made the change or a site manager, with the access the original tool needs.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'snapshot_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'snapshot_id' ),
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
				'snapshot_id'       => array( 'type' => 'integer' ),
				'ts'                => array( 'type' => 'string' ),
				'tool'              => array( 'type' => 'string' ),
				'object_type'       => array( 'type' => 'string' ),
				'object_id'         => array( 'type' => 'string' ),
				'restored'          => array(
					'type'        => 'boolean',
					'description' => 'Already undone; the comparison still works.',
				),
				'current_available' => array(
					'type'        => 'boolean',
					'description' => 'False when the object can no longer be read (e.g. deleted); see note.',
				),
				'identical'         => array( 'type' => 'boolean' ),
				'change_count'      => array( 'type' => 'integer' ),
				'changes'           => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'path'   => array( 'type' => 'string' ),
							'change' => array(
								'type' => 'string',
								'enum' => array( 'changed', 'added', 'removed' ),
							),
							'before' => array(
								'type'        => self::ANY,
								'description' => 'Value in the snapshot.',
							),
							'after'  => array(
								'type'        => self::ANY,
								'description' => 'Value now.',
							),
						),
						'required'   => array( 'path', 'change' ),
					),
				),
				'truncated'         => array( 'type' => 'boolean' ),
				'note'              => array( 'type' => 'string' ),
			),
			'required'   => array( 'snapshot_id', 'tool', 'object_type', 'object_id', 'current_available', 'changes' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'   => true,
			'idempotentHint' => true,
			'openWorldHint'  => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * Any logged-in user; ownership, scope and the object capability are checked per snapshot.
	 *
	 * @param array<string, mixed> $input Unused.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Compare.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		return Snapshots::compare( (int) $input['snapshot_id'] );
	}
}
