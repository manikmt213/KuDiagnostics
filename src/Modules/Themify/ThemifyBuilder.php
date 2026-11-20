<?php
/**
 * Tools: kuduy-themify-builder-get, kuduy-themify-builder-module-update, kuduy-mosaic-sources.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Themify;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Themify Builder and its Mosaic add-on (phase R, D65; source of Themify Builder 7.8.5 and Builder Mosaic 3.6.2).
 *
 * A Themify Builder layout is JSON in the post meta _themify_builder_settings_json: rows with cols, cols with modules
 * (or sub-rows with their own cols); every element has an element_id, modules a mod_name and mod_settings. It is read
 * with ThemifyBuilder_Data_Manager::get_data() and written with save_data( $data, $post_id, 'backend' ), which is what
 * Themify's own editor calls: it escapes the data (kses for users without unfiltered_html), runs in a transaction,
 * makes a builder revision, refreshes the static content and fires themify_builder_save_data. One module's settings
 * are changed at a time, by element_id; the layout meta and post content are saved first for undo.
 *
 * Builder Mosaic registers the module "mosaic" (tile layouts); mod_settings.tiled_posts_display names its data
 * provider (posts, terms, text, gallery, wc, portfolio, ptb, tep, nextgen, rss, files — only those whose plugin is
 * present are available), and saved tile presets are posts of type tftp_template.
 */
final class ThemifyBuilder implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode get | module-update | mosaic-sources.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'mosaic-sources' === $this->mode ? 'kuduy-mosaic-sources' : 'kuduy-themify-builder-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'get'           => __( 'Read a Themify Builder layout', 'kuduy-mcp' ),
			'module-update' => __( 'Change a Themify Builder module', 'kuduy-mcp' ),
			default         => __( 'List Mosaic sources and presets', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'get'           => 'Returns the Themify Builder layout of a post or page as a tree: rows, columns, sub-rows and modules with their element_id; modules with their type (mod_name, e.g. text, image, buttons, mosaic) and a short text preview. With element_id it returns that one element with all its settings (mod_settings).',
			'module-update' => 'Changes the settings of one Themify Builder module, named by post_id and element_id (from kuduy-themify-builder-get): keys given in settings are set, keys set to null are removed, the rest stay. Saved through Themify Builder\'s own save (escaping, revision, static content). For a Mosaic module, tiled_posts_display must be a source Mosaic has on this site (kuduy-mosaic-sources). Undo with kuduy-snapshot-restore.',
			default         => 'Lists what Builder Mosaic can show on this site: its data sources (tiled_posts_display values: posts, terms, text, gallery, wc for WooCommerce products, portfolio, ptb, tep for events, nextgen, rss, files — only those available here) and the tile presets saved in Mosaic (id, title).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$element    = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
		);
		$properties = match ( $this->mode ) {
			'get'           => array(
				'post_id'    => $id,
				'element_id' => $element,
			),
			'module-update' => array(
				'post_id'    => $id,
				'element_id' => $element,
				'settings'   => array(
					'type'          => 'object',
					'minProperties' => 1,
					'description'   => 'Module settings to set (null removes a key).',
				),
			),
			default         => array(),
		};
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( 'get' === $this->mode ) {
			$schema['required'] = array( 'post_id' );
		}
		if ( 'module-update' === $this->mode ) {
			$schema['required'] = array( 'post_id', 'element_id', 'settings' );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'      => array( 'type' => 'integer' ),
				'rows'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'element'      => array( 'type' => 'object' ),
				'sources'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'presets'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'module-update' !== $this->mode ) {
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
		return 'module-update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( 'mosaic-sources' === $this->mode ) {
			return 'edit_posts';
		}
		return $post_id > 0 && get_post( $post_id ) ? array( 'edit_post', $post_id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! class_exists( 'ThemifyBuilder_Data_Manager' ) ) {
			return 'Themify Builder is not active on this site.';
		}
		if ( 'mosaic-sources' === $this->mode && ! class_exists( 'Builder_Mosaic' ) ) {
			return 'Builder Mosaic is not active on this site.';
		}
		return true;
	}

	/**
	 * The post and its layout.
	 *
	 * @param int $id Post id.
	 * @return array{0: \WP_Post, 1: array<int, mixed>}
	 * @throws ToolError When the post is unknown.
	 */
	private static function layout( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No post with id ' . $id . '.' );
		}
		$data = \ThemifyBuilder_Data_Manager::get_data( $id );
		return array( $post, is_array( $data ) ? array_values( $data ) : array() );
	}

	/**
	 * Kind of a layout element.
	 *
	 * @param array<string, mixed> $el    Element.
	 * @param int                  $depth Depth (0 = row).
	 */
	private static function kind( array $el, int $depth ): string {
		if ( isset( $el['mod_name'] ) ) {
			return 'module';
		}
		if ( isset( $el['cols'] ) ) {
			return 0 === $depth ? 'row' : 'subrow';
		}
		return 'column';
	}

	/**
	 * The layout as a tree of ids, kinds and short previews.
	 *
	 * @param array<int|string, mixed> $elements Elements.
	 * @param int                      $depth    Depth.
	 * @return list<array<string, mixed>>
	 */
	private static function tree( array $elements, int $depth = 0 ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$kind = self::kind( $el, $depth );
			$node = array(
				'element_id' => (string) ( $el['element_id'] ?? '' ),
				'kind'       => $kind,
			);
			if ( 'module' === $kind ) {
				$node['mod_name'] = (string) $el['mod_name'];
				$text             = self::preview( (array) ( $el['mod_settings'] ?? array() ) );
				if ( '' !== $text ) {
					$node['preview'] = $text;
				}
				if ( 'mosaic' === $el['mod_name'] ) {
					$node['source'] = (string) ( $el['mod_settings']['tiled_posts_display'] ?? '' );
				}
			}
			foreach ( array( 'cols', 'modules' ) as $children ) {
				if ( isset( $el[ $children ] ) && is_array( $el[ $children ] ) ) {
					$node['children'] = self::tree( $el[ $children ], 'cols' === $children ? $depth + 1 : $depth + 1 );
				}
			}
			$out[] = $node;
		}//end foreach
		return $out;
	}

	/**
	 * Short text of a module (its title or first text setting).
	 *
	 * @param array<string, mixed> $settings Module settings.
	 */
	private static function preview( array $settings ): string {
		foreach ( array( 'mod_title', 'heading', 'content_text', 'title_text', 'text_button', 'caption_image', 'title_image' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				return mb_substr( trim( wp_strip_all_tags( $settings[ $key ] ) ), 0, 120 );
			}
		}
		return '';
	}

	/**
	 * Find an element by id; returns a reference path.
	 *
	 * @param array<int|string, mixed> $elements Elements.
	 * @param string                   $id       Element id.
	 * @param list<int|string>         $path     Path so far.
	 * @return list<int|string>|null
	 */
	private static function find( array $elements, string $id, array $path = array() ): ?array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['element_id'] ?? '' ) === $id ) {
				return array_merge( $path, array( $i ) );
			}
			foreach ( array( 'cols', 'modules' ) as $children ) {
				if ( isset( $el[ $children ] ) && is_array( $el[ $children ] ) ) {
					$found = self::find( $el[ $children ], $id, array_merge( $path, array( $i, $children ) ) );
					if ( null !== $found ) {
						return $found;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Element at a path.
	 *
	 * @param array<int|string, mixed> $data Layout.
	 * @param list<int|string>         $path Path.
	 * @return array<string, mixed>
	 */
	private static function at( array $data, array $path ): array {
		$el = $data;
		foreach ( $path as $step ) {
			$el = $el[ $step ];
		}
		return (array) $el;
	}

	/**
	 * Mosaic's available data sources on this site.
	 *
	 * @return list<string>
	 */
	private static function sources(): array {
		if ( ! class_exists( 'Builder_Mosaic_Provider' ) ) {
			return array();
		}
		$providers = \Builder_Mosaic_Provider::get_providers( 'all' );
		return is_array( $providers ) ? array_map( 'strval', array_keys( $providers ) ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'module-update' !== $this->mode ) {
			return null;
		}
		$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'      => $post->ID,
				'layout'  => (string) get_post_meta( $post->ID, \ThemifyBuilder_Data_Manager::META_KEY, true ),
				'content' => $post->post_content,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post is gone or Themify Builder refuses.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( ! get_post( $id ) instanceof \WP_Post ) {
			throw new ToolError( 'The post of this snapshot no longer exists.' );
		}
		$data   = json_decode( (string) ( $payload['layout'] ?? '' ), true );
		$result = \ThemifyBuilder_Data_Manager::save_data( is_array( $data ) ? $data : array(), $id, 'backend' );
		if ( empty( $result['mid'] ) ) {
			throw new ToolError( 'Themify Builder did not take the previous layout back.' );
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => (string) ( $payload['content'] ?? '' ),
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post or element is unknown, a Mosaic source is not available, or the save fails.
	 */
	public function execute( array $input ): array {
		if ( 'mosaic-sources' === $this->mode ) {
			$presets = array();
			foreach ( get_posts(
				array(
					'post_type'      => 'tftp_template',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
				)
			) as $preset ) {
				$presets[] = array(
					'id'    => $preset->ID,
					'title' => $preset->post_title,
				);
			}
			return array(
				'sources' => self::sources(),
				'presets' => $presets,
			);
		}
		[ $post, $data ] = self::layout( (int) $input['post_id'] );
		AuditLog::target( 'post', (string) $post->ID );
		if ( 'get' === $this->mode ) {
			if ( isset( $input['element_id'] ) ) {
				$path = self::find( $data, (string) $input['element_id'] );
				if ( null === $path ) {
					throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No element "' . (string) $input['element_id'] . '" in the layout of post ' . $post->ID . ' (see kuduy-themify-builder-get without element_id).' );
				}
				return array(
					'post_id' => $post->ID,
					'element' => self::at( $data, $path ),
				);
			}
			return array(
				'post_id' => $post->ID,
				'rows'    => self::tree( $data ),
			);
		}
		$path = self::find( $data, (string) $input['element_id'] );
		if ( null === $path ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No element "' . (string) $input['element_id'] . '" in the layout of post ' . $post->ID . '. Nothing was changed.' );
		}
		$module = self::at( $data, $path );
		if ( ! isset( $module['mod_name'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Element "' . (string) $input['element_id'] . '" is a ' . self::kind( $module, count( $path ) > 1 ? 1 : 0 ) . ', not a module. Nothing was changed.' );
		}
		$settings = (array) ( $module['mod_settings'] ?? array() );
		foreach ( (array) $input['settings'] as $key => $value ) {
			if ( null === $value ) {
				unset( $settings[ (string) $key ] );
			} else {
				$settings[ (string) $key ] = $value;
			}
		}
		if ( 'mosaic' === $module['mod_name'] && isset( $settings['tiled_posts_display'] ) && class_exists( 'Builder_Mosaic_Provider' ) && ! in_array( (string) $settings['tiled_posts_display'], self::sources(), true ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Mosaic cannot show "' . (string) $settings['tiled_posts_display'] . '" on this site. Available: ' . implode( ', ', self::sources() ) . '. Nothing was changed.' );
		}
		$ref = &$data;
		foreach ( $path as $step ) {
			$ref = &$ref[ $step ];
		}
		$ref['mod_settings'] = $settings;
		unset( $ref );
		$result = \ThemifyBuilder_Data_Manager::save_data( $data, $post->ID, 'backend' );
		if ( empty( $result['mid'] ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Themify Builder did not save the layout (its save rolled back). Nothing was changed.' );
		}
		[ , $saved ] = self::layout( $post->ID );
		$path        = self::find( $saved, (string) $input['element_id'] );
		return array(
			'post_id' => $post->ID,
			'element' => null === $path ? array() : self::at( $saved, $path ),
		);
	}
}
