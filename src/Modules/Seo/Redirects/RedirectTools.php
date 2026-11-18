<?php
/**
 * Tools: kuduy-P-redirects, kuduy-P-redirect-save, kuduy-P-redirect-delete for Yoast SEO Premium, SEOPress PRO and
 * All in One SEO Pro.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Redirects of the Pro SEO plugins (phase L2, D59), each through the plugin's own path (RedirectStore). Rank Math's
 * redirects have their own tools (kuduy-seo-redirect*). Every change needs the admin scope and the plugin's own
 * permission; a source already used by another redirect is refused; changes and deletions are snapshotted (a deleted
 * redirect comes back with a new id where the plugin numbers them); a new redirect is removed with the delete tool.
 */
final class RedirectTools implements Tool, Snapshottable {

	private const TYPES = array( 301, 302, 307, 410, 451 );

	/**
	 * Store and mode.
	 *
	 * @param RedirectStore $store Store.
	 * @param string        $mode  redirects | redirect-save | redirect-delete.
	 */
	public function __construct( private RedirectStore $store, private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->store->prefix() . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		$name = $this->store->name();
		return match ( $this->mode ) {
			'redirects'     => $name . ' redirects',
			'redirect-save' => 'Save a ' . $name . ' redirect',
			default         => 'Delete a ' . $name . ' redirect',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$name = $this->store->name();
		$p    = 'kuduy-' . $this->store->prefix();
		return match ( $this->mode ) {
			'redirects'     => 'Lists ' . $name . ' redirects: id, source, target, type (301, 302, 307, 410, 451), regex, enabled, hits. Filter with search (text in source or target); 50 per page.',
			'redirect-save' => 'Creates a ' . $name . ' redirect (no id) or changes one, through ' . $name . '\'s own save path: source (a path such as "/old-page/", or a pattern with regex: true), target (URL or path; not used for 410 and 451), type (default 301), enabled. A source used by another redirect is refused. Returns the redirect as read back plus any warnings from ' . $name . '. Changes are snapshotted (undo with kuduy-snapshot-restore); remove a new redirect with ' . $p . '-redirect-delete.',
			default         => 'Deletes a ' . $name . ' redirect. Snapshot first; undo with kuduy-snapshot-restore (the redirect is created again).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$text = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 2000,
		);
		$id   = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 2000,
		);
		return match ( $this->mode ) {
			'redirects'     => array(
				'type'                 => 'object',
				'properties'           => array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'redirect-save' => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => $id,
					'source'  => $text,
					'target'  => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'type'    => array(
						'type' => 'integer',
						'enum' => self::TYPES,
					),
					'regex'   => array( 'type' => 'boolean' ),
					'enabled' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			default         => array(
				'type'                 => 'object',
				'properties'           => array( 'id' => $id ),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin'       => array( 'type' => 'string' ),
				'redirects'    => array( 'type' => 'array' ),
				'total'        => array( 'type' => 'integer' ),
				'redirect'     => array( 'type' => 'object' ),
				'warnings'     => array( 'type' => 'array' ),
				'deleted'      => array( 'type' => 'string' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'plugin' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'redirects' === $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'redirects' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// The plugin's own permission is checked as well (RedirectStore::check_access and its routes).
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return $this->store->available();
	}

	/**
	 * Comparable form of a source.
	 *
	 * @param string $source Source.
	 */
	private static function key( string $source ): string {
		$path = (string) wp_parse_url( $source, PHP_URL_PATH );
		$path = '' === $path ? $source : $path;
		return strtolower( trim( $path, '/' ) );
	}

	/**
	 * The redirect or a not-found error.
	 *
	 * @param string $id Id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none.
	 */
	private function find( string $id ): array {
		$row = $this->store->get( $id );
		if ( null === $row ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $this->store->name() . ' has no redirect ' . $id . '.' );
		}
		return $row;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'redirects' === $this->mode || ! isset( $input['id'] ) ) {
			return null;
		}
		$this->store->check_access();
		$row = $this->find( (string) $input['id'] );
		return array(
			'object_type' => $this->store->prefix() . '_redirect',
			'object_id'   => (string) $row['id'],
			'payload'     => array( 'row' => $row ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$row    = (array) ( $payload['row'] ?? array() );
		$fields = array_intersect_key( $row, array_flip( array( 'source', 'target', 'type', 'regex', 'enabled' ) ) );
		$id     = null === $this->store->get( (string) $row['id'] ) ? null : (string) $row['id'];
		if ( null !== $id ) {
			$now = (array) $this->store->get( $id );
			foreach ( array( 'source', 'regex' ) as $same ) {
				if ( $now[ $same ] === $fields[ $same ] ) {
					unset( $fields[ $same ] );
				}
			}
		}
		$this->store->save( $id, $fields );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the input is incomplete or the plugin refuses.
	 */
	public function execute( array $input ): array {
		$this->store->check_access();
		$out = array( 'plugin' => $this->store->name() );
		if ( 'redirects' === $this->mode ) {
			return $out + $this->store->all( (string) ( $input['search'] ?? '' ), (int) ( $input['page'] ?? 1 ) );
		}
		$id = isset( $input['id'] ) ? (string) $input['id'] : null;
		if ( 'redirect-delete' === $this->mode ) {
			$row = $this->find( (string) $id );
			AuditLog::target( $this->store->prefix() . '_redirect', (string) $row['id'] );
			$this->store->delete( (string) $row['id'] );
			return $out + array( 'deleted' => (string) $row['id'] );
		}
		$old    = null === $id ? null : $this->find( $id );
		$fields = array_intersect_key( $input, array_flip( array( 'source', 'target', 'type', 'regex', 'enabled' ) ) );
		if ( isset( $fields['source'] ) ) {
			$fields['source'] = trim( (string) $fields['source'] );
		}
		if ( null === $old && empty( $fields['source'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new redirect needs a source. Nothing was saved.' );
		}
		if ( null !== $old && ! $fields ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give at least one field to change. Nothing was saved.' );
		}
		$type   = (int) ( $fields['type'] ?? ( $old['type'] ?? 301 ) );
		$target = trim( (string) ( $fields['target'] ?? ( $old['target'] ?? '' ) ) );
		if ( ! in_array( $type, array( 410, 451 ), true ) && '' === $target ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A ' . $type . ' redirect needs a target. Nothing was saved.' );
		}
		$source = (string) ( $fields['source'] ?? $old['source'] );
		$regex  = (bool) ( $fields['regex'] ?? ( $old['regex'] ?? false ) );
		if ( $regex && false === @preg_match( '~' . str_replace( '~', '\~', $source ) . '~', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only checks that the pattern compiles.
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The source is not a valid regular expression. Nothing was saved.' );
		}
		if ( ! $regex && ! in_array( $type, array( 410, 451 ), true ) && self::key( $source ) === self::key( $target ) && ! preg_match( '~^https?://~i', $target ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The target is the same as the source, which would loop. Nothing was saved.' );
		}
		if ( isset( $fields['source'] ) ) {
			foreach ( $this->store->all( self::key( $source ), 1 )['redirects'] as $other ) {
				if ( self::key( (string) $other['source'] ) === self::key( $source ) && (bool) $other['regex'] === $regex && ( null === $old || (string) $other['id'] !== (string) $old['id'] ) ) {
					throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'Redirect ' . $other['id'] . ' already uses this source. Change that one instead. Nothing was saved.' );
				}
			}
		}
		$result = $this->store->save( null === $old ? null : (string) $old['id'], $fields );
		AuditLog::target( $this->store->prefix() . '_redirect', (string) $result['redirect']['id'] );
		return $out + $result;
	}
}
