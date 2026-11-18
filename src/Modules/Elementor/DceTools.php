<?php
/**
 * Tools: kuduy-dce-features, kuduy-dce-feature-toggle, kuduy-dce-html-templates.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Dev\DevText;

/**
 * Dynamic Content for Elementor (phase L7; read on DCE 3.4.20, class/features.php and class/pdf-html-templates.php):
 * its features (widgets, extensions, dynamic tags …) with their on/off state from
 * DynamicContentForElementor\Plugin::instance()->features (filter(), get_features_status()), switched with its own
 * db_update_features_status( [ id => active|inactive ] ) and undo; and its PDF HTML templates (post type
 * dce_html_template, code in meta dce-html-code, format dce-html-format, orientation dce-html-orientation) read with
 * credentials masked. Its widgets themselves are edited with the Elementor tools like any registered widget.
 * Templates are changed in DCE's own screen (its save checks the administrator role and a nonce).
 */
final class DceTools implements Tool, Snapshottable {

	private const CPT = 'dce_html_template';

	/**
	 * Mode.
	 *
	 * @param string $mode features | feature-toggle | html-templates.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-dce-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'features'       => 'Dynamic Content for Elementor features',
			'feature-toggle' => 'Switch a Dynamic Content for Elementor feature',
			default          => 'Dynamic Content for Elementor PDF templates',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'features'       => 'Lists the features of Dynamic Content for Elementor (widgets, extensions, dynamic tags, page settings …): id, title, type, active. Filter by type or active. Its widgets are edited with the kuduy-elementor-* tools.',
			'feature-toggle' => 'Switches Dynamic Content for Elementor features on or off through its own feature store (features: {"id": true|false}). Switching a widget off removes it from pages that use it. Snapshot first; undo with kuduy-snapshot-restore.',
			default          => 'Lists the PDF HTML templates of Dynamic Content for Elementor (id, title, format, orientation); with id, returns one template\'s code (credentials masked). Read only.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = match ( $this->mode ) {
			'features'       => array(
				'type'   => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
				'active' => array( 'type' => 'boolean' ),
			),
			'feature-toggle' => array(
				'features' => array(
					'type'                 => 'object',
					'minProperties'        => 1,
					'maxProperties'        => 50,
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
			),
			default          => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'feature-toggle' === $this->mode ? array( 'features' ) : array(),
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
				'features'     => array( 'type' => 'array' ),
				'templates'    => array( 'type' => 'array' ),
				'template'     => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'feature-toggle' !== $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'feature-toggle' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// DCE's settings screen and its template saves are for administrators.
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( 'html-templates' === $this->mode ) {
			return post_type_exists( self::CPT ) ? true : 'Dynamic Content for Elementor is not active.';
		}
		return null === self::store() ? 'Dynamic Content for Elementor is not active.' : true;
	}

	/**
	 * DCE's feature store, or null.
	 */
	private static function store(): ?object {
		if ( ! class_exists( '\DynamicContentForElementor\Plugin' ) ) {
			return null;
		}
		$features = \DynamicContentForElementor\Plugin::instance()->features ?? null;
		return is_object( $features ) && method_exists( $features, 'db_update_features_status' ) ? $features : null;
	}

	/**
	 * Features: id => row.
	 *
	 * @return array<string, array{id: string, title: string, type: string, active: bool}>
	 */
	private static function features(): array {
		$out = array();
		foreach ( (array) self::store()->filter( array() ) as $id => $row ) {
			// DCE keys its features (and their saved status) by the array key, e.g. ext_animations; "name" differs.
			$row        = (array) $row;
			$id         = (string) $id;
			$out[ $id ] = array(
				'id'     => $id,
				'title'  => (string) ( $row['title'] ?? $id ),
				'type'   => (string) ( $row['type'] ?? '' ),
				'active' => 'active' === ( $row['status'] ?? '' ),
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a feature is unknown.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'feature-toggle' !== $this->mode ) {
			return null;
		}
		$all  = self::features();
		$prev = array();
		foreach ( array_keys( (array) $input['features'] ) as $id ) {
			if ( ! isset( $all[ (string) $id ] ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Dynamic Content for Elementor has no feature "' . $id . '" (see kuduy-dce-features). Nothing was changed.' );
			}
			$prev[ (string) $id ] = $all[ (string) $id ]['active'];
		}
		return array(
			'object_type' => 'dce_features',
			'object_id'   => implode( ',', array_keys( $prev ) ),
			'payload'     => array( 'features' => $prev ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		self::store()->db_update_features_status( array_map( static fn( $on ) => $on ? 'active' : 'inactive', (array) $payload['features'] ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a template is unknown.
	 */
	public function execute( array $input ): array {
		if ( 'html-templates' === $this->mode ) {
			if ( isset( $input['id'] ) ) {
				$post = get_post( (int) $input['id'] );
				if ( ! $post || self::CPT !== $post->post_type ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No Dynamic Content for Elementor PDF template ' . (int) $input['id'] . '.' );
				}
				return array( 'template' => self::template( $post, true ) );
			}
			$posts = get_posts(
				array(
					'post_type'      => self::CPT,
					'post_status'    => 'any',
					'posts_per_page' => 100,
				)
			);
			return array( 'templates' => array_map( static fn( $p ) => self::template( $p, false ), $posts ) );
		}
		if ( 'features' === $this->mode ) {
			$rows = array_values( self::features() );
			if ( isset( $input['type'] ) ) {
				$rows = array_values( array_filter( $rows, static fn( $r ) => $r['type'] === (string) $input['type'] ) );
			}
			if ( isset( $input['active'] ) ) {
				$rows = array_values( array_filter( $rows, static fn( $r ) => $r['active'] === (bool) $input['active'] ) );
			}
			return array( 'features' => $rows );
		}
		$all    = self::features();
		$update = array();
		foreach ( (array) $input['features'] as $id => $on ) {
			if ( ! isset( $all[ (string) $id ] ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Dynamic Content for Elementor has no feature "' . $id . '". Nothing was changed.' );
			}
			$update[ (string) $id ] = $on ? 'active' : 'inactive';
		}
		AuditLog::target( 'dce_features', implode( ',', array_keys( $update ) ) );
		self::store()->db_update_features_status( $update );
		$now = self::features();
		return array( 'features' => array_values( array_intersect_key( $now, $update ) ) );
	}

	/**
	 * A template row.
	 *
	 * @param \WP_Post $post Post.
	 * @param bool     $code Include the code.
	 * @return array<string, mixed>
	 */
	private static function template( \WP_Post $post, bool $code ): array {
		$out = array(
			'id'          => $post->ID,
			'title'       => get_the_title( $post ),
			'status'      => $post->post_status,
			'format'      => (string) get_post_meta( $post->ID, 'dce-html-format', true ),
			'orientation' => (string) get_post_meta( $post->ID, 'dce-html-orientation', true ),
		);
		if ( $code ) {
			$out['code'] = DevText::mask( (string) get_post_meta( $post->ID, 'dce-html-code', true ) );
		}
		return $out;
	}
}
