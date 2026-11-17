<?php
/**
 * Tools: kuduy-site-templates, kuduy-site-template-get, kuduy-site-template-create, kuduy-site-template-update,
 * kuduy-site-template-revert.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Block theme templates and template parts through core's templates controller (D18 A). Changing a theme template
 * makes a database copy, as in the Site Editor; undo removes that copy again (the theme file is used) or puts the
 * previous content back. Revert moves a customized theme template's copy to the trash (core's non-forced delete), so
 * the theme file is used; undo restores the copy. Nothing is deleted permanently except a copy KuDiagnostics itself created,
 * on undo.
 */
final class TemplateTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | create | update | revert.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-site-templates' : 'kuduy-site-template-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'   => __( 'Site Editor templates', 'kuduy-mcp' ),
			'get'    => __( 'Read a template', 'kuduy-mcp' ),
			'create' => __( 'Create a template or template part', 'kuduy-mcp' ),
			'update' => __( 'Change a template', 'kuduy-mcp' ),
			default  => __( 'Revert a template to the theme', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'   => 'Lists the templates (type "template", default) or template parts (type "part": header, footer, …) of the active block theme: id ("theme//slug"), slug, title, source (theme = the theme file, custom = saved on the site), customized (a theme template changed on the site), area for parts.',
			'get'    => 'Returns one template or template part with its block markup (content), title, description and source.',
			'create' => 'Creates a new template (type "template", e.g. slug "single-product" or "page-landing") or template part (type "part", with area header, footer or uncategorized) in the active block theme, as the Site Editor does: slug, title, content (block markup), description. Refuses a slug the theme or site already has (change that one with kuduy-site-template-update). Use a part in a template with <!-- wp:template-part {"slug":"…","area":"…"} /-->. Returns the new id ("theme//slug"). Remove it again in the Site Editor.',
			'update' => 'Changes a template or template part like the Site Editor does: content (block markup), title, description. A theme template is saved as a site copy; the theme file is not changed. Snapshot first; undo with kuduy-snapshot-restore (a copy made by this change is removed again, so the theme file is used).',
			default  => 'Puts a customized theme template or part back to the theme file: WordPress moves the site copy to the trash. Only for templates the theme provides. Snapshot first; undo with kuduy-snapshot-restore brings the copy back.',
		};
	}

	/**
	 * Input property for the type.
	 *
	 * @return array<string, mixed>
	 */
	private static function type_input(): array {
		return array(
			'type'    => 'string',
			'enum'    => array( 'template', 'part' ),
			'default' => 'template',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array( 'type' => self::type_input() );
		$required   = array();
		if ( 'create' === $this->mode ) {
			$properties += array(
				'slug'        => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9][a-z0-9_-]*$',
					'maxLength' => 100,
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'content'     => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'area'        => array(
					'type'        => 'string',
					'maxLength'   => 50,
					'description' => 'Template parts only: header, footer or uncategorized (default), or an area the theme registers.',
				),
			);
			$required    = array( 'slug', 'content' );
		} elseif ( 'list' !== $this->mode ) {
			$properties['id'] = array(
				'type'        => 'string',
				'pattern'     => '^[^/]+//[^/]+$',
				'description' => 'Template id: "theme//slug", as kuduy-site-templates lists it.',
			);
			$required[]       = 'id';
		}//end if
		if ( 'update' === $this->mode ) {
			$properties += array(
				'content'     => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'templates' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'templates' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'string' ),
				'source'       => array( 'type' => 'string' ),
				'customized'   => array( 'type' => 'boolean' ),
				'content'      => array( 'type' => 'string' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'source', 'customized' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'list', 'get' ), true ) ) {
			return Content::read_only();
		}
		if ( 'create' === $this->mode ) {
			return array(
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			);
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
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? 'edit_posts' : 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return wp_is_block_theme() ? true : 'The active theme is a classic theme, so it has no Site Editor templates (use the widget and menu tools)';
	}

	/**
	 * REST base and post type for the input type.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: string}
	 */
	private static function kind( array $input ): array {
		return 'part' === ( $input['type'] ?? 'template' )
			? array( '/wp/v2/template-parts', 'wp_template_part' )
			: array( '/wp/v2/templates', 'wp_template' );
	}

	/**
	 * A template by id, or a ToolError.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When there is none.
	 */
	private static function template( array $input ): \WP_Block_Template {
		[ , $type ] = self::kind( $input );
		$template   = get_block_template( (string) ( $input['id'] ?? '' ), $type );
		if ( ! $template instanceof \WP_Block_Template ) {
			throw new ToolError( 'No ' . ( 'wp_template' === $type ? 'template' : 'template part' ) . ' with id "' . esc_html( (string) ( $input['id'] ?? '' ) ) . '" in the active theme.' );
		}
		return $template;
	}

	/**
	 * One template in the tool's shape.
	 *
	 * @param \WP_Block_Template $template Template.
	 * @param bool               $content  Include the block markup.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Block_Template $template, bool $content ): array {
		$out = array(
			'id'             => (string) $template->id,
			'slug'           => (string) $template->slug,
			'title'          => (string) $template->title,
			'description'    => (string) $template->description,
			'source'         => (string) $template->source,
			'has_theme_file' => (bool) $template->has_theme_file,
			'customized'     => 'custom' === $template->source && (bool) $template->has_theme_file,
			'status'         => (string) $template->status,
		);
		if ( 'wp_template_part' === $template->type ) {
			$out['area'] = (string) $template->area;
		}
		if ( $content ) {
			$out['content'] = (string) $template->content;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the template is missing or cannot be reverted.
	 */
	public function snapshot( array $input ): ?array {
		if ( ! in_array( $this->mode, array( 'update', 'revert' ), true ) ) {
			return null;
		}
		$template = self::template( $input );
		if ( 'revert' === $this->mode ) {
			self::assert_revertable( $template );
		}
		return array(
			'object_type' => (string) $template->type,
			'object_id'   => (string) $template->id,
			'payload'     => array(
				'mode'        => $this->mode,
				'type'        => 'wp_template_part' === $template->type ? 'part' : 'template',
				'id'          => (string) $template->id,
				'was_custom'  => 'custom' === $template->source,
				'wp_id'       => (int) $template->wp_id,
				'status'      => (string) $template->status,
				'content'     => (string) $template->content,
				'title'       => (string) $template->title,
				'description' => (string) $template->description,
			),
		);
	}

	/**
	 * Create a template or part through core's controller.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @param string               $route REST base.
	 * @param string               $type  wp_template | wp_template_part.
	 * @return array<string, mixed>
	 * @throws ToolError When the slug is taken, the area unknown or core refuses.
	 */
	private static function create( array $input, string $route, string $type ): array {
		$slug = (string) $input['slug'];
		$id   = get_stylesheet() . '//' . $slug;
		if ( get_block_template( $id, $type ) instanceof \WP_Block_Template ) {
			throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'The active theme already has a ' . ( 'wp_template' === $type ? 'template' : 'template part' ) . ' "' . $slug . '"; change it with kuduy-site-template-update. Nothing was created.' );
		}
		$body = array(
			'slug'    => $slug,
			'title'   => (string) ( $input['title'] ?? $slug ),
			'content' => (string) $input['content'],
		);
		if ( isset( $input['description'] ) ) {
			$body['description'] = (string) $input['description'];
		}
		if ( 'wp_template_part' === $type ) {
			$area  = (string) ( $input['area'] ?? WP_TEMPLATE_PART_AREA_UNCATEGORIZED );
			$areas = array_column( get_allowed_block_template_part_areas(), 'area' );
			if ( ! in_array( $area, $areas, true ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'Unknown template part area "' . $area . '". This theme has: ' . implode( ', ', $areas ) . '. Nothing was created.' );
			}
			$body['area'] = $area;
		} elseif ( isset( $input['area'] ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'area is for template parts (type "part"). Nothing was created.' );
		}
		AuditLog::target( $type, $id );
		CoreRest::call( 'POST', $route, $body );
		return self::shape(
			self::template(
				array(
					'id'   => $id,
					'type' => 'wp_template_part' === $type ? 'part' : 'template',
				)
			),
			true
		);
	}

	/**
	 * Only a customized theme template can be reverted (anything else would be a delete).
	 *
	 * @param \WP_Block_Template $template Template.
	 * @throws ToolError When it is not one.
	 */
	private static function assert_revertable( \WP_Block_Template $template ): void {
		if ( 'custom' !== $template->source ) {
			throw new ToolError( 'This template is the theme file itself; there is nothing to revert.' );
		}
		if ( ! $template->has_theme_file ) {
			throw new ToolError( 'This template was made on the site and has no theme file to go back to; KuDiagnostics does not delete templates.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When core refuses.
	 */
	public function restore( array $payload ): void {
		[ $route ] = self::kind( $payload );
		$id        = (string) ( $payload['id'] ?? '' );
		if ( 'revert' === ( $payload['mode'] ?? '' ) ) {
			$wp_id = (int) ( $payload['wp_id'] ?? 0 );
			if ( $wp_id <= 0 || ! get_post( $wp_id ) ) {
				throw new ToolError( 'The template copy no longer exists.' );
			}
			wp_untrash_post( $wp_id );
			wp_update_post(
				array(
					'ID'          => $wp_id,
					'post_status' => (string) ( $payload['status'] ?? 'publish' ),
				)
			);
			return;
		}
		if ( empty( $payload['was_custom'] ) ) {
			// Before the change only the theme file existed: remove the copy the change created.
			[ , $type ] = self::kind( $payload );
			$current    = get_block_template( $id, $type );
			if ( $current instanceof \WP_Block_Template && 'custom' === $current->source && $current->has_theme_file ) {
				CoreRest::call( 'DELETE', $route . '/' . $id, array( 'force' => true ) );
			}
			return;
		}
		CoreRest::call(
			'POST',
			$route . '/' . $id,
			array(
				'content'     => (string) ( $payload['content'] ?? '' ),
				'title'       => (string) ( $payload['title'] ?? '' ),
				'description' => (string) ( $payload['description'] ?? '' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the template is missing or core refuses.
	 */
	public function execute( array $input ): array {
		[ $route, $type ] = self::kind( $input );
		if ( 'list' === $this->mode ) {
			$out = array();
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$out[] = self::shape( $template, false );
			}
			return array( 'templates' => $out );
		}
		if ( 'create' === $this->mode ) {
			return self::create( $input, $route, $type );
		}
		$template = self::template( $input );
		AuditLog::target( $type, (string) $template->id );
		if ( 'get' === $this->mode ) {
			// Through core's controller, so its read permission applies as in the Site Editor.
			CoreRest::call( 'GET', $route . '/' . $template->id, array( 'context' => 'edit' ) );
			return self::shape( $template, true );
		}
		if ( 'revert' === $this->mode ) {
			self::assert_revertable( $template );
			CoreRest::call( 'DELETE', $route . '/' . $template->id );
			return self::shape( self::template( $input ), true );
		}
		$body = array_intersect_key( $input, array_flip( array( 'content', 'title', 'description' ) ) );
		if ( ! $body ) {
			throw new ToolError( 'Give content, title or description. Nothing was changed.' );
		}
		CoreRest::call( 'POST', $route . '/' . $template->id, $body );
		return self::shape( self::template( $input ), true );
	}
}
