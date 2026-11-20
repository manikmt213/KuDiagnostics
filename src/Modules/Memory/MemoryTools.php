<?php
/**
 * Tools: kuduy-memory-save, -get, -search, -list, -delete, -export, -import, -context.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Memory;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Memory\MemoryStore;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\IntegrationCatalog;
use Kuduy\Mcp\Modules\Core\Integrations;
use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * The site's memory for assistants (phase I, D70; D31 + D53): notes, facts, decisions, preferences and design notes
 * that persist across connections and sessions, shared with every connected user who can edit content or private to
 * their author, grouped by project, with a status, a version and an optional expiry. Memories never hold secrets:
 * text the redactor or the credential masker would change, or with a well-known key format, is refused. A memory may
 * name the integrations it is about (catalogue ids); the context tool flags those that are not active on the site now.
 */
final class MemoryTools implements Tool, Snapshottable, MasksAuditArgs {

	/**
	 * Suggested kinds (any short lowercase word is accepted).
	 */
	public const KINDS = array( 'note', 'fact', 'decision', 'preference', 'design', 'task' );

	/**
	 * Mode.
	 *
	 * @param string $mode save | get | search | list | delete | export | import | context.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-memory-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'save'    => __( 'Save a memory', 'kuduy-mcp' ),
			'get'     => __( 'Read a memory', 'kuduy-mcp' ),
			'search'  => __( 'Search memory', 'kuduy-mcp' ),
			'list'    => __( 'List memories', 'kuduy-mcp' ),
			'delete'  => __( 'Delete memories', 'kuduy-mcp' ),
			'export'  => __( 'Export memory', 'kuduy-mcp' ),
			'import'  => __( 'Import memory', 'kuduy-mcp' ),
			default   => __( 'Memory for this session', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$never = ' Never store passwords, keys or tokens: text that looks like one is refused.';
		return match ( $this->mode ) {
			'save'    => 'Saves something worth remembering about this site for later sessions: kind (note, fact, decision, preference, design, task), title, body, tags, project, shared (true: every connected user who can edit content sees it; false: only you), refs (integration ids from kuduy-integrations it is about, e.g. "elementor", so a later session is warned when one is no longer active), status (current or outdated), expires_at (date). With id it changes that memory (fields not given stay; give version to be sure nobody changed it since you read it); undo with kuduy-snapshot-restore.' . $never,
			'get'     => 'Returns one memory (yours or shared) with all fields.',
			'search'  => 'Searches memory by words (relevance: title, then tags, then body), optionally within a kind, project or status. Returns the best matches first with a score. Expired memories are left out.',
			'list'    => 'Lists memories (yours and shared), newest first, filtered by kind, project or status; include_expired to see expired ones. Paged.',
			'delete'  => 'Deletes one memory (id; yours, or a shared one you wrote — others\' need a site administrator), or as an administrator every memory of a project (project + all) or all of them (all), which needs confirm: true and the admin access level. Undo with kuduy-snapshot-restore puts them back.',
			'export'  => 'Exports memories (yours and shared, optionally one project) as JSON, to keep or to import on another site.',
			'import'  => 'Imports memories exported with kuduy-memory-export (items), as new memories; each is checked like a save (secrets refused; one bad item stops the import before anything is written). Site administrators with the admin access level only. Not undone by kuduy-snapshot-restore: delete the imported ids.' . $never,
			default   => 'What to know at the start of a session: the current, unexpired memories (yours and shared) for a project or all, newest first, each with conflicts — integrations it refers to that are not active on the site now (then check whether the memory is outdated) — and a count by kind.',
		};
	}

	/**
	 * Fields of a memory in save and import.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields(): array {
		return array(
			'kind'       => array(
				'type'    => 'string',
				'pattern' => '^[a-z][a-z_-]{0,29}$',
			),
			'title'      => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'body'       => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
			'tags'       => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 50,
				),
			),
			'project'    => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'refs'       => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9-]{1,60}$',
				),
			),
			'status'     => array(
				'type' => 'string',
				'enum' => array( 'current', 'outdated' ),
			),
			'expires_at' => array(
				'type'    => array( 'string', 'null' ),
				'pattern' => '^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?)?$',
			),
			'shared'     => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id                   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$filter               = array(
			'kind'    => array(
				'type'    => 'string',
				'pattern' => '^[a-z][a-z_-]{0,29}$',
			),
			'project' => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'status'  => array(
				'type' => 'string',
				'enum' => array( 'current', 'outdated' ),
			),
		);
		$limit                = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 100,
		);
		[ $props, $required ] = match ( $this->mode ) {
			'save'    => array(
				array(
					'id'      => $id,
					'version' => $id,
				) + self::fields(),
				array(),
			),
			'get'     => array( array( 'id' => $id ), array( 'id' ) ),
			'search'  => array(
				array(
					'query' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'limit' => $limit,
				) + $filter,
				array( 'query' ),
			),
			'list'    => array(
				$filter + array(
					'include_expired' => array( 'type' => 'boolean' ),
					'page'            => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page'        => $limit,
				),
				array(),
			),
			'delete'  => array(
				array(
					'id'      => $id,
					'project' => $filter['project'],
					'all'     => array( 'type' => 'boolean' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				array(),
			),
			'export'  => array(
				array(
					'project'         => $filter['project'],
					'include_expired' => array( 'type' => 'boolean' ),
				),
				array(),
			),
			'import'  => array(
				array(
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 500,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => self::fields() + array(
								'id'         => array( 'type' => 'integer' ),
								'version'    => array( 'type' => 'integer' ),
								'mine'       => array( 'type' => 'boolean' ),
								'created_at' => array( 'type' => 'string' ),
								'updated_at' => array( 'type' => 'string' ),
								'created_by' => array( 'type' => 'integer' ),
							),
							'required'             => array( 'title' ),
							'additionalProperties' => false,
						),
					),
				),
				array( 'items' ),
			),
			default   => array(
				array(
					'project' => $filter['project'],
					'limit'   => $limit,
				),
				array(),
			),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $props,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
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
				'memory'       => array( 'type' => 'object' ),
				'memories'     => $list,
				'total'        => array( 'type' => 'integer' ),
				'deleted'      => array( 'type' => 'integer' ),
				'imported'     => $list,
				'kinds'        => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'get', 'search', 'list', 'export', 'context' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => in_array( $this->mode, array( 'save', 'delete' ), true ),
			'idempotentHint'  => 'delete' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'save', 'delete' => 'kuduy:write',
			'import'         => 'kuduy:admin',
			default          => 'kuduy:read',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		if ( 'import' === $this->mode || ( 'delete' === $this->mode && ! isset( $input['id'] ) ) ) {
			return 'manage_options';
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A refused save still reaches the audit log with its arguments: any text holding a secret is masked there.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array {
		array_walk_recursive(
			$args,
			static function ( mixed &$value ): void {
				if ( is_string( $value ) && '' !== MemoryStore::secret_reason( $value ) ) {
					$value = Redactor::MASK;
				}
			}
		);
		return $args;
	}

	/**
	 * A memory the user may see.
	 *
	 * @param int $id Memory id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none or it is someone else's private memory.
	 */
	private static function visible( int $id ): array {
		$row = MemoryStore::get( $id );
		if ( null === $row || ( 0 !== $row['user_id'] && get_current_user_id() !== $row['user_id'] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No memory with id ' . $id . ' (see kuduy-memory-list).' );
		}
		return $row;
	}

	/**
	 * Whether the user may change or delete a memory: their own (private or shared), or any as a site administrator.
	 *
	 * @param array<string, mixed> $row Memory.
	 */
	private static function may_change( array $row ): bool {
		return get_current_user_id() === (int) $row['created_by'] || current_user_can( 'manage_options' );
	}

	/**
	 * Output shape of a memory.
	 *
	 * @param array<string, mixed> $row Memory.
	 * @return array<string, mixed>
	 */
	private static function out( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'kind'       => (string) $row['kind'],
			'title'      => (string) $row['title'],
			'body'       => (string) $row['body'],
			'tags'       => $row['tags'],
			'project'    => (string) $row['project'],
			'shared'     => 0 === (int) $row['user_id'],
			'mine'       => get_current_user_id() === (int) $row['created_by'],
			'refs'       => $row['refs'],
			'status'     => (string) $row['status'],
			'version'    => (int) $row['version'],
			'expires_at' => null === $row['expires_at'] ? null : mysql_to_rfc3339( (string) $row['expires_at'] ),
			'created_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at' => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Validate and normalise the fields of a save or an import item into a row.
	 *
	 * @param array<string, mixed>      $input Fields.
	 * @param array<string, mixed>|null $old   Current row when changing.
	 * @return array<string, mixed>
	 * @throws ToolError When a required field is missing, the expiry is not a date, or a text holds a secret.
	 */
	private static function row( array $input, ?array $old ): array {
		if ( null === $old && ( ! isset( $input['title'] ) || '' === trim( (string) $input['title'] ) ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new memory needs a title.' );
		}
		$now = current_time( 'mysql', true );
		$row = $old ?? array(
			'kind'       => 'note',
			'body'       => '',
			'tags'       => array(),
			'project'    => '',
			'user_id'    => 0,
			'refs'       => array(),
			'status'     => 'current',
			'version'    => 0,
			'expires_at' => null,
			'created_by' => get_current_user_id(),
			'created_at' => $now,
		);
		foreach ( array( 'kind', 'title', 'body', 'project', 'status' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$row[ $key ] = 'body' === $key ? (string) $input[ $key ] : sanitize_text_field( (string) $input[ $key ] );
			}
		}
		foreach ( array( 'tags', 'refs' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$row[ $key ] = array_values( array_unique( array_map( static fn( $v ): string => sanitize_text_field( (string) $v ), (array) $input[ $key ] ) ) );
			}
		}
		if ( array_key_exists( 'shared', $input ) ) {
			$row['user_id'] = true === $input['shared'] ? 0 : get_current_user_id();
		}
		if ( array_key_exists( 'expires_at', $input ) ) {
			if ( null === $input['expires_at'] ) {
				$row['expires_at'] = null;
			} else {
				$time = strtotime( (string) $input['expires_at'] );
				if ( false === $time ) {
					throw ToolError::of( ToolError::INVALID_ARGUMENT, 'expires_at is not a date.' );
				}
				$row['expires_at'] = gmdate( 'Y-m-d H:i:s', $time );
			}
		}
		foreach ( array( 'title', 'body' ) as $key ) {
			$why = MemoryStore::secret_reason( (string) $row[ $key ] );
			if ( '' !== $why ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The ' . $key . ' was not saved because ' . $why . '. Memory never stores secrets: describe where the value lives (e.g. "the Stripe key is in WooCommerce > Payments") instead. Nothing was saved.' );
			}
		}
		$why = MemoryStore::secret_reason( implode( ' ', $row['tags'] ) . ' ' . (string) $row['project'] );
		if ( '' !== $why ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The tags or project were not saved because ' . $why . '. Nothing was saved.' );
		}
		$row['version']    = (int) $row['version'] + 1;
		$row['updated_at'] = $now;
		return $row;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'save' === $this->mode && isset( $input['id'] ) ) {
			$row = MemoryStore::get( (int) $input['id'] );
			return null === $row ? null : array(
				'object_type' => 'memory',
				'object_id'   => (string) $row['id'],
				'payload'     => array( 'rows' => array( $row ) ),
			);
		}
		if ( 'delete' !== $this->mode ) {
			return null;
		}
		$rows = isset( $input['id'] ) ? array_filter( array( MemoryStore::get( (int) $input['id'] ) ) ) : self::bulk( $input );
		return $rows ? array(
			'object_type' => 'memory',
			'object_id'   => isset( $input['id'] ) ? (string) $input['id'] : 'bulk',
			'payload'     => array( 'rows' => $rows ),
		) : null;
	}

	/**
	 * Rows a bulk delete covers (every row of the project, or every row), at most 2,000.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return list<array<string, mixed>>
	 */
	private static function bulk( array $input ): array {
		$filter = array( 'include_expired' => true );
		if ( isset( $input['project'] ) ) {
			$filter['project'] = (string) $input['project'];
		}
		return MemoryStore::find( $filter, get_current_user_id(), 2000, 0, true )[0];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When a row cannot be written back.
	 */
	public function restore( array $payload ): void {
		foreach ( (array) ( $payload['rows'] ?? array() ) as $row ) {
			$row = (array) $row;
			$id  = (int) $row['id'];
			$ok  = null === MemoryStore::get( $id ) ? MemoryStore::insert( $row ) > 0 : MemoryStore::update( $id, $row );
			if ( ! $ok ) {
				throw new ToolError( 'Memory ' . $id . ' could not be written back.' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a memory is unknown, not the user's, stale, holds a secret, or the request is incomplete.
	 */
	public function execute( array $input ): array {
		$user = get_current_user_id();
		switch ( $this->mode ) {
			case 'get':
				return array( 'memory' => self::out( self::visible( (int) $input['id'] ) ) );
			case 'save':
				return self::save( $input );
			case 'search':
				return self::search( $input );
			case 'list':
				$per          = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
				$page         = max( 1, (int) ( $input['page'] ?? 1 ) );
				[ $rows, $n ] = MemoryStore::find( $input, $user, $per, ( $page - 1 ) * $per );
				return array(
					'memories' => array_map( array( self::class, 'out' ), $rows ),
					'total'    => $n,
				);
			case 'delete':
				return self::remove( $input );
			case 'export':
				[ $rows ] = MemoryStore::find( $input, $user, 5000 );
				return array(
					'memories' => array_map( array( self::class, 'out' ), $rows ),
					'total'    => count( $rows ),
				);
			case 'import':
				return self::import( (array) $input['items'] );
			default:
				return self::context( $input );
		}//end switch
	}

	/**
	 * Create or change a memory.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the memory is unknown, not the user's, stale or holds a secret.
	 */
	private static function save( array $input ): array {
		$old = isset( $input['id'] ) ? self::visible( (int) $input['id'] ) : null;
		if ( null !== $old ) {
			if ( ! self::may_change( $old ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'Only its author or a site administrator can change memory ' . (int) $old['id'] . '. Save a new memory instead.' );
			}
			if ( isset( $input['version'] ) && (int) $input['version'] !== (int) $old['version'] ) {
				throw ToolError::of( ToolError::CONFLICT, 'Memory ' . (int) $old['id'] . ' changed since version ' . (int) $input['version'] . ' (now ' . (int) $old['version'] . '). Read it again before changing it. Nothing was saved.' );
			}
		}
		$row = self::row( $input, $old );
		if ( null === $old ) {
			$id = MemoryStore::insert( $row );
			if ( $id < 1 ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'The memory could not be saved.' );
			}
		} else {
			$id = (int) $old['id'];
			MemoryStore::update( $id, $row );
		}
		AuditLog::target( 'memory', (string) $id );
		return array( 'memory' => self::out( (array) MemoryStore::get( $id ) ) );
	}

	/**
	 * Relevance search.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function search( array $input ): array {
		$words  = array_values( array_unique( array_filter( array_map( 'strtolower', (array) preg_split( '/[^\p{L}\p{N}_-]+/u', (string) $input['query'] ) ), static fn( string $w ): bool => mb_strlen( $w ) > 1 ) ) );
		$filter = $input;
		unset( $filter['query'] );
		$filter['words'] = $words;
		[ $rows ]        = MemoryStore::find( $filter, get_current_user_id(), 500 );
		$scored          = array();
		foreach ( $rows as $row ) {
			$score = 0;
			$title = strtolower( (string) $row['title'] );
			$body  = strtolower( (string) $row['body'] );
			$tags  = strtolower( implode( ' ', $row['tags'] ) );
			foreach ( $words as $word ) {
				$score += 3 * substr_count( $title, $word ) + 2 * substr_count( $tags, $word ) + min( 5, substr_count( $body, $word ) );
			}
			if ( $score > 0 ) {
				$scored[] = array( 'score' => $score ) + self::out( $row );
			}
		}
		usort( $scored, static fn( array $a, array $b ): int => 0 !== ( $b['score'] <=> $a['score'] ) ? $b['score'] <=> $a['score'] : strcmp( $b['updated_at'], $a['updated_at'] ) );
		return array(
			'memories' => array_slice( $scored, 0, min( 100, max( 1, (int) ( $input['limit'] ?? 10 ) ) ) ),
			'total'    => count( $scored ),
		);
	}

	/**
	 * Delete one memory or, for administrators, a project's or all.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the memory is unknown or not the user's, or a bulk delete is not confirmed or allowed.
	 */
	private static function remove( array $input ): array {
		if ( isset( $input['id'] ) ) {
			$row = self::visible( (int) $input['id'] );
			if ( ! self::may_change( $row ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'Only its author or a site administrator can delete memory ' . (int) $row['id'] . '.' );
			}
			AuditLog::target( 'memory', (string) $row['id'] );
			return array( 'deleted' => MemoryStore::delete( array( (int) $row['id'] ) ) );
		}
		if ( empty( $input['all'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give id, or all: true (with project to delete one project\'s memories).' );
		}
		$granted = TokenAuth::scopes();
		if ( null !== $granted && ! Scopes::allows( $granted, Scopes::ADMIN ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Deleting many memories needs the admin access level.' );
		}
		$rows = self::bulk( $input );
		if ( empty( $input['confirm'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'This deletes ' . count( $rows ) . ' memories' . ( isset( $input['project'] ) ? ' of project "' . (string) $input['project'] . '"' : '' ) . ', including other users\' private ones. Call again with confirm: true. Nothing was deleted.' );
		}
		AuditLog::target( 'memory', isset( $input['project'] ) ? 'project:' . (string) $input['project'] : 'all' );
		return array( 'deleted' => MemoryStore::delete( array_map( static fn( array $r ): int => (int) $r['id'], $rows ) ) );
	}

	/**
	 * Import memories as new rows.
	 *
	 * @param array<int, mixed> $items Exported memories.
	 * @return array<string, mixed>
	 * @throws ToolError When an item is invalid or holds a secret (nothing is written then).
	 */
	private static function import( array $items ): array {
		$rows = array();
		foreach ( $items as $i => $item ) {
			$item = (array) $item;
			unset( $item['id'], $item['version'], $item['mine'], $item['created_at'], $item['updated_at'], $item['created_by'] );
			try {
				$rows[] = self::row( $item, null );
			} catch ( ToolError $e ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Item ' . ( (int) $i + 1 ) . ': ' . $e->getMessage() );
			}
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = MemoryStore::insert( $row );
		}
		AuditLog::target( 'memory', 'import' );
		return array(
			'imported' => array_map( static fn( int $id ): array => array( 'id' => $id ), $ids ),
			'total'    => count( $ids ),
		);
	}

	/**
	 * Session context with conflict flags.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function context( array $input ): array {
		$filter = array( 'status' => 'current' );
		if ( isset( $input['project'] ) ) {
			$filter['project'] = (string) $input['project'];
		}
		[ $rows, $total ] = MemoryStore::find( $filter, get_current_user_id(), min( 100, max( 1, (int) ( $input['limit'] ?? 30 ) ) ) );
		$plugins          = Integrations::plugin_folders();
		$live             = array();
		$out              = array();
		$kinds            = array();
		foreach ( $rows as $row ) {
			$conflicts = array();
			foreach ( $row['refs'] as $ref ) {
				if ( ! array_key_exists( $ref, $live ) ) {
					$cat          = IntegrationCatalog::find( $ref );
					$live[ $ref ] = null === $cat ? null : Integrations::detect( $cat, $plugins );
				}
				if ( null === $live[ $ref ] ) {
					$conflicts[] = $ref . ' is not an integration KuDiagnostics knows';
				} elseif ( ! $live[ $ref ]['active'] ) {
					$conflicts[] = $ref . ( $live[ $ref ]['installed'] ? ' is installed but not active now' : ' is not installed now' );
				}
			}
			$kinds[ (string) $row['kind'] ] = ( $kinds[ (string) $row['kind'] ] ?? 0 ) + 1;
			$out[]                          = self::out( $row ) + array( 'conflicts' => $conflicts );
		}
		return array(
			'memories' => $out,
			'total'    => $total,
			'kinds'    => $kinds ? $kinds : new \stdClass(),
		);
	}
}
