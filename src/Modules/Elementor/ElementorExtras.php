<?php
/**
 * Tools: kuduy-elementor-usage, kuduy-elementor-extensions.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\Diagnostics;

/**
 * Elementor usage search and the extensions add-ons attach to Elementor (phase F2, D67).
 *
 * Usage: every post with Elementor data (_elementor_data, the JSON Elementor saves) is read and its element tree
 * walked; only posts the user may edit are counted. A widget or element type narrows the database query to posts whose
 * data contains it; the plugin that provides each type comes from Elementor's own registries (types of an inactive
 * plugin are reported as not registered).
 *
 * Extensions: add-ons extend Elementor's own widgets, sections and containers by hooking into
 * elementor/element/{element}/{section}/{before|after}_section_{start|end}, the generic before/after section hooks,
 * elementor/frontend/{element}/before_render and the widget render filters. The callbacks on those hooks are grouped by
 * the plugin they live in (by reflection, as kuduy-hooks-inspect does), with the elements and sections they extend.
 */
final class ElementorExtras implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode usage | extensions.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'usage' === $this->mode ? __( 'Where Elementor widgets are used', 'kuduy-mcp' ) : __( 'What add-ons add to Elementor', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'usage' === $this->mode
			? 'Searches the Elementor pages, posts and templates of the site for widget and element types. Without filters: every type used, how often, on how many pages, and the plugin that provides it (types whose plugin is inactive are marked not registered). With widget (a type name such as "heading" or "ha-card") or plugin (an add-on folder or part of its name): the pages that use it, with counts. Only posts you can edit are counted. Paged by limit/offset over the posts.'
			: 'Lists what Elementor add-ons attach to Elementor\'s own widgets, sections and containers (extra controls such as effects, motion or display conditions, and changes to how elements render), grouped by the plugin that added them: the elements and control sections they extend and the hooks used. Read from the callbacks registered on Elementor\'s element hooks; nothing is run.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'extensions' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
				),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget'    => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Widget or element type name (see kuduy-elementor-widgets).',
				),
				'plugin'    => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Plugin folder or part of its name.',
				),
				'post_type' => array(
					'type'      => 'string',
					'maxLength' => 40,
				),
				'limit'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1000,
				),
				'offset'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
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
				'types'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'pages'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'plugins'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'scanned'     => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'next_offset' => array( 'type' => array( 'integer', 'null' ) ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'usage' === $this->mode ? 'edit_posts' : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ? true : 'Elementor is not active on this site.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a filter matches nothing that can be searched.
	 */
	public function execute( array $input ): array {
		return 'usage' === $this->mode ? self::usage( $input ) : self::extensions( (string) ( $input['plugin'] ?? '' ) );
	}

	/**
	 * Registered widget and element types: name => [folder, plugin name].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function registry(): array {
		$plugin = \Elementor\Plugin::$instance;
		$out    = array();
		foreach ( array( (array) $plugin->elements_manager->get_element_types(), (array) $plugin->widgets_manager->get_widget_types() ) as $types ) {
			foreach ( $types as $name => $type ) {
				if ( is_object( $type ) ) {
					$out[ (string) $name ] = array( Elementor::source( $type ), Elementor::source_name( $type ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Usage search.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the plugin filter matches no registered type.
	 */
	private static function usage( array $input ): array {
		$registry = self::registry();
		$widget   = (string) ( $input['widget'] ?? '' );
		$plugin   = (string) ( $input['plugin'] ?? '' );
		$wanted   = array();
		if ( '' !== $widget ) {
			$wanted = array( $widget );
		} elseif ( '' !== $plugin ) {
			// An exact plugin folder matches only that folder; otherwise part of the plugin's name.
			$exact = in_array( $plugin, array_column( $registry, 0 ), true );
			foreach ( $registry as $name => [ $folder, $label ] ) {
				if ( $exact ? $folder === $plugin : false !== stripos( $label, $plugin ) ) {
					$wanted[] = $name;
				}
			}
			if ( ! $wanted ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No active plugin "' . $plugin . '" provides Elementor widgets on this site (see kuduy-elementor-widgets).' );
			}
		}
		$limit  = min( 1000, max( 1, (int) ( $input['limit'] ?? 200 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$types  = isset( $input['post_type'] ) ? array( (string) $input['post_type'] ) : array_values( array_diff( get_post_types(), array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ) ) );
		$meta   = array(
			array(
				'key'     => '_elementor_data',
				'compare' => 'EXISTS',
			),
		);
		if ( '' !== $widget ) {
			// A cheap first cut in the database; the tree walk below decides.
			$meta[] = array(
				'key'     => '_elementor_data',
				'value'   => '"' . $widget . '"',
				'compare' => 'LIKE',
			);
		}
		$query   = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'any',
				'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the data lives in post meta; bounded by limit.
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
			)
		);
		$counts  = array();
		$pages   = array();
		$scanned = 0;
		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			++$scanned;
			$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$here = array();
			Elementor::walk(
				array_values( $data ),
				static function ( array $element ) use ( &$here ): void {
					$type = Elementor::type( $element );
					if ( '' !== $type ) {
						$here[ $type ] = ( $here[ $type ] ?? 0 ) + 1;
					}
				}
			);
			foreach ( $here as $type => $n ) {
				$counts[ $type ]['uses']  = ( $counts[ $type ]['uses'] ?? 0 ) + $n;
				$counts[ $type ]['pages'] = ( $counts[ $type ]['pages'] ?? 0 ) + 1;
			}
			if ( $wanted ) {
				$hit = array_intersect_key( $here, array_flip( $wanted ) );
				if ( $hit ) {
					$post    = get_post( $id );
					$pages[] = array(
						'post_id'   => $id,
						'title'     => $post ? $post->post_title : '',
						'post_type' => $post ? $post->post_type : '',
						'status'    => $post ? $post->post_status : '',
						'uses'      => array_sum( $hit ),
						'types'     => $hit,
					);
				}
			}
		}//end foreach
		$rows = array();
		foreach ( $counts as $type => $c ) {
			if ( $wanted && ! in_array( (string) $type, $wanted, true ) ) {
				continue;
			}
			$rows[] = array(
				'type'       => (string) $type,
				'plugin'     => $registry[ $type ][1] ?? 'not registered',
				'folder'     => $registry[ $type ][0] ?? '',
				'uses'       => $c['uses'],
				'pages'      => $c['pages'],
				'registered' => isset( $registry[ $type ] ),
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => $b['uses'] <=> $a['uses'] );
		$total = (int) $query->found_posts;
		$out   = array(
			'types'       => $rows,
			'scanned'     => $scanned,
			'total'       => $total,
			'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
		);
		if ( $wanted ) {
			$out['pages'] = $pages;
		}
		return $out;
	}

	/**
	 * Add-ons' callbacks on Elementor's element hooks, by plugin.
	 *
	 * @param string $filter Plugin folder or part of its name, or ''.
	 * @return array<string, mixed>
	 */
	private static function extensions( string $filter ): array {
		global $wp_filter;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$names = array();
		foreach ( get_plugins() as $file => $data ) {
			$names[ (string) strtok( (string) $file, '/' ) ] = wp_strip_all_tags( (string) ( $data['Name'] ?? '' ) );
		}
		$by = array();
		foreach ( (array) $wp_filter as $hook => $object ) {
			$hook = (string) $hook;
			if ( ! preg_match( '#^elementor/(element|frontend|widget)/#', $hook ) || ! $object instanceof \WP_Hook ) {
				continue;
			}
			[ $element, $section ] = self::target( $hook );
			foreach ( $object->callbacks as $callbacks ) {
				foreach ( (array) $callbacks as $entry ) {
					$source = Diagnostics::describe( $entry['function'] )['source'];
					if ( ! str_starts_with( $source, 'plugin: ' ) ) {
						continue;
					}
					$folder = substr( $source, strlen( 'plugin: ' ) );
					if ( in_array( $folder, array( 'elementor', 'elementor-pro', 'kuduy-mcp' ), true ) ) {
						continue;
					}
					$label = $names[ $folder ] ?? $folder;
					if ( '' !== $filter && ( isset( $names[ $filter ] ) ? $folder !== $filter : false === stripos( $label, $filter ) ) ) {
						continue;
					}
					$by[ $folder ]['plugin']               = $label;
					$by[ $folder ]['folder']               = $folder;
					$by[ $folder ]['hooks'][ $hook ]       = true;
					$by[ $folder ]['elements'][ $element ] = true;
					if ( '' !== $section ) {
						$by[ $folder ]['sections'][ $section ] = true;
					}
				}//end foreach
			}//end foreach
		}//end foreach
		$out = array();
		foreach ( $by as $row ) {
			$out[] = array(
				'plugin'   => $row['plugin'],
				'folder'   => $row['folder'],
				'elements' => array_keys( $row['elements'] ),
				'sections' => array_keys( $row['sections'] ?? array() ),
				'hooks'    => array_keys( $row['hooks'] ),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['plugin'], $b['plugin'] ) );
		return array( 'plugins' => $out );
	}

	/**
	 * Element and control section a hook targets ("*" for all elements).
	 *
	 * @param string $hook Hook name.
	 * @return array{0: string, 1: string}
	 */
	private static function target( string $hook ): array {
		$parts = explode( '/', $hook );
		if ( 'element' === $parts[1] && count( $parts ) >= 5 ) {
			return array( $parts[2], $parts[3] );
		}
		if ( 'element' === $parts[1] && 4 === count( $parts ) ) {
			// elementor/element/{element}/{section} is rare; elementor/element/{element}/{when} is not used by Elementor.
			return array( $parts[2], $parts[3] );
		}
		if ( 'frontend' === $parts[1] && count( $parts ) >= 4 ) {
			return array( $parts[2], '' );
		}
		return array( '*', '' );
	}
}
