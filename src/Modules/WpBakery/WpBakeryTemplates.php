<?php
/**
 * Tools: kuduy-wpbakery-templates, kuduy-wpbakery-template-get, kuduy-wpbakery-template-save,
 * kuduy-wpbakery-template-delete (one class, four modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * WPBakery "My Templates" (phase Q6). Read on WPBakery 9.1 on a live site (2026-11-18): Vc_Templates_Panel_Editor keeps
 * them in the option wpb_js_templates as id => { name, template } (id = sanitize_title( name ) . '_' . wp_rand(), the
 * template is the layout's shortcodes); save() and delete() write that option and need edit_posts or edit_pages plus
 * the "Templates" part of WPBakery's Role Manager. These tools do the same, with the layout checked by the WPBakery
 * parser first. The templates WPBakery ships (default templates) are listed by name only.
 */
final class WpBakeryTemplates implements Tool, Snapshottable {

	private const OPTION = 'wpb_js_templates';

	/**
	 * Mode.
	 *
	 * @param string $mode templates | template-get | template-save | template-delete.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-wpbakery-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'templates'    => __( 'WPBakery templates', 'kuduy-mcp' ),
			'template-get' => __( 'Read a WPBakery template', 'kuduy-mcp' ),
			'template-save' => __( 'Save a WPBakery template', 'kuduy-mcp' ),
			default        => __( 'Delete a WPBakery template', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'templates'     => 'Lists WPBakery\'s saved templates ("My Templates": id, name, element count) and the names of the templates WPBakery ships. Filter with search.',
			'template-get'  => 'Returns one saved WPBakery template: name, its layout as shortcodes (content) and an outline of rows, columns and elements. Put it on a page with kuduy-wpbakery-replace (content) or kuduy-wpbakery-create.',
			'template-save' => 'Saves a WPBakery template as its Templates panel does: a new one from content (the layout\'s shortcodes) or from_post (the layout of a WPBakery page, by id), with name; or changes a saved one (id: name and/or content). The layout is checked with WPBakery\'s element list first; a broken layout is refused. Undo with kuduy-snapshot-restore.',
			default         => 'Deletes a saved WPBakery template (My Templates), as its Templates panel does. Pages that used it keep their copy. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id    = array(
			'type'      => 'string',
			'maxLength' => 200,
			'pattern'   => '^[A-Za-z0-9_-]+$',
		);
		$props = match ( $this->mode ) {
			'templates'     => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'template-save' => array(
				'id'        => $id,
				'name'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'content'   => array(
					'type'      => 'string',
					'maxLength' => Shortcodes::MAX_BYTES,
				),
				'from_post' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			default         => array( 'id' => $id ),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => in_array( $this->mode, array( 'template-get', 'template-delete' ), true ) ? array( 'id' ) : array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'templates', 'template-get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'template-delete' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'templates', 'template-get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return WpBakery::available();
	}

	/**
	 * Refuse users WPBakery's Role Manager keeps out of templates.
	 *
	 * @throws ToolError When the Templates part is switched off for the user's role.
	 */
	private static function role_check(): void {
		if ( ! function_exists( 'vc_user_access' ) ) {
			return;
		}
		$access = vc_user_access()->part( 'templates' )->checkStateAny( true, null );
		if ( is_object( $access ) && method_exists( $access, 'get' ) && ! $access->get() ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'WPBakery\'s Role Manager does not give your role access to templates.' );
		}
	}

	/**
	 * Saved templates.
	 *
	 * @return array<string, array{name: string, template: string}>
	 */
	private static function saved(): array {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $row ) {
			if ( is_array( $row ) && isset( $row['template'] ) ) {
				$out[ (string) $id ] = array(
					'name'     => (string) ( $row['name'] ?? '' ),
					'template' => (string) $row['template'],
				);
			}
		}
		return $out;
	}

	/**
	 * A saved template, or a ToolError.
	 *
	 * @param string $id Template id.
	 * @return array{name: string, template: string}
	 * @throws ToolError When there is none.
	 */
	private static function template( string $id ): array {
		$all = self::saved();
		if ( ! isset( $all[ $id ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No saved WPBakery template "' . $id . '" (see kuduy-wpbakery-templates).' );
		}
		return $all[ $id ];
	}

	/**
	 * Parse and check a layout.
	 *
	 * @param string $content Shortcodes.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When it is empty or broken.
	 */
	private static function checked( string $content ): array {
		$nodes = WpBakery::parse( $content );
		if ( ! $nodes || 0 === Layout::count( $nodes ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The layout has no WPBakery elements. Nothing was saved.' );
		}
		$report = Layout::validate( $nodes );
		if ( $report['errors'] ) {
			$lines = array_map( static fn( array $e ) => $e['at'] . ': ' . $e['problem'], array_slice( $report['errors'], 0, 10 ) );
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'This layout is broken, so nothing was saved. ' . implode( ' | ', $lines ) );
		}
		return $nodes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( in_array( $this->mode, array( 'templates', 'template-get' ), true ) ) {
			return null;
		}
		$value = get_option( self::OPTION, null );
		return array(
			'object_type' => 'option',
			'object_id'   => self::OPTION,
			'payload'     => array(
				'exists' => null !== $value,
				'value'  => $value,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		if ( empty( $payload['exists'] ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $payload['value'], false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a template is missing, a layout broken or access refused.
	 */
	public function execute( array $input ): array {
		self::role_check();
		return match ( $this->mode ) {
			'templates'     => self::listing( strtolower( (string) ( $input['search'] ?? '' ) ) ),
			'template-get'  => self::get( (string) $input['id'] ),
			'template-save' => self::save( $input ),
			default         => self::delete( (string) $input['id'] ),
		};
	}

	/**
	 * Saved templates and the names of the shipped ones.
	 *
	 * @param string $search Lower-case search text.
	 * @return array<string, mixed>
	 */
	private static function listing( string $search ): array {
		$mine = array();
		foreach ( self::saved() as $id => $row ) {
			if ( '' !== $search && ! str_contains( strtolower( $row['name'] ), $search ) ) {
				continue;
			}
			$mine[] = array(
				'id'       => $id,
				'name'     => $row['name'],
				'elements' => Layout::count( WpBakery::parse( $row['template'] ) ),
			);
		}
		$shipped = array();
		if ( function_exists( 'visual_composer' ) ) {
			$panel = visual_composer()->templatesPanelEditor();
			if ( is_object( $panel ) && method_exists( $panel, 'getDefaultTemplates' ) ) {
				foreach ( (array) $panel->getDefaultTemplates() as $row ) {
					$name = (string) ( ( (array) $row )['name'] ?? '' );
					if ( '' !== $name && ( '' === $search || str_contains( strtolower( $name ), $search ) ) ) {
						$shipped[] = $name;
					}
				}
			}
		}
		return array(
			'templates' => $mine,
			'shipped'   => $shipped,
		);
	}

	/**
	 * One saved template.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>
	 */
	private static function get( string $id ): array {
		$row   = self::template( $id );
		$nodes = WpBakery::parse( $row['template'] );
		AuditLog::target( 'wpbakery_template', $id );
		return array(
			'id'       => $id,
			'name'     => $row['name'],
			'content'  => $row['template'],
			'elements' => Layout::count( $nodes ),
			'outline'  => Layout::outline( $nodes ),
		);
	}

	/**
	 * Create or change a saved template.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the input is incomplete or the layout broken.
	 */
	private static function save( array $input ): array {
		if ( isset( $input['content'] ) && isset( $input['from_post'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give the layout as content or from_post, not both. Nothing was saved.' );
		}
		$content = isset( $input['content'] ) ? (string) $input['content'] : null;
		if ( isset( $input['from_post'] ) ) {
			$post = get_post( (int) $input['from_post'] );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No page you may edit with id ' . (int) $input['from_post'] . '. Nothing was saved.' );
			}
			$content = $post->post_content;
		}
		$all = self::saved();
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		if ( isset( $input['id'] ) ) {
			$id  = (string) $input['id'];
			$row = self::template( $id );
			if ( null === $content && ! isset( $input['name'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give name and/or content (or from_post). Nothing was changed.' );
			}
		} else {
			if ( null === $content || ! isset( $input['name'] ) ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new template needs name and content (or from_post). Nothing was saved.' );
			}
			do {
				$id = sanitize_title( (string) $input['name'] ) . '_' . wp_rand();
			} while ( isset( $all[ $id ] ) );
			$row = array(
				'name'     => '',
				'template' => '',
			);
		}
		if ( null !== $content ) {
			self::checked( $content );
			$row['template'] = $content;
		}
		if ( isset( $input['name'] ) ) {
			$row['name'] = trim( (string) $input['name'] );
		}
		$raw[ $id ] = $row;
		AuditLog::target( 'wpbakery_template', $id );
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $raw, '', false );
		} else {
			update_option( self::OPTION, $raw );
		}
		return self::get( $id );
	}

	/**
	 * Delete a saved template.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>
	 */
	private static function delete( string $id ): array {
		$row = self::template( $id );
		$raw = (array) get_option( self::OPTION, array() );
		unset( $raw[ $id ] );
		AuditLog::target( 'wpbakery_template', $id );
		if ( $raw ) {
			update_option( self::OPTION, $raw );
		} else {
			delete_option( self::OPTION );
		}
		return array(
			'id'      => $id,
			'name'    => $row['name'],
			'deleted' => true,
		);
	}
}
