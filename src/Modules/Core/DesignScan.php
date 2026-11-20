<?php
/**
 * Tool: kuduy-design-scan.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Elementor\ElementorRead;

/**
 * The site's design tokens in one shape (phase I, D71), each with its source: theme.json settings and global styles
 * (wp_get_global_settings(), wp_get_global_styles(): palette, font families and sizes, spacing sizes, layout widths,
 * page colors), the active Elementor Kit (system and custom colors and typography, content width) and the Customizer
 * (background colour, header text colour), plus the site's name, tagline, logo and icon. Nothing is written; the
 * summary can be kept with kuduy-memory-save (kind "design").
 */
final class DesignScan implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-design-scan';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Scan the site design', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Collects the site\'s design tokens in one place, each with where it is set: colors (theme palette, page background and text, link color, Elementor Kit colors, Customizer colors), fonts and font sizes (theme and Elementor Kit), spacing sizes and layout widths, plus the site name, tagline, logo and icon. Use it before designing or changing pages so new work matches; the summary can be saved with kuduy-memory-save (kind "design"). Reads only.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
				'site'       => array( 'type' => 'object' ),
				'colors'     => $list,
				'fonts'      => $list,
				'font_sizes' => $list,
				'spacing'    => $list,
				'layout'     => array( 'type' => 'object' ),
				'sources'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'summary'    => array( 'type' => 'string' ),
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
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		$colors  = array();
		$fonts   = array();
		$sizes   = array();
		$spacing = array();
		$layout  = array();
		$sources = array();

		$settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : array();
		$theme    = 'theme.json (' . get_stylesheet() . ')';
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? array() ) as $item ) {
				$colors[] = array(
					'name'   => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'slug'   => (string) ( $item['slug'] ?? '' ),
					'value'  => (string) ( $item['color'] ?? '' ),
					'source' => 'default' === $origin ? 'WordPress default palette' : $theme . ( 'custom' === $origin ? ', user changes' : '' ),
				);
			}
			foreach ( (array) ( $settings['typography']['fontFamilies'][ $origin ] ?? array() ) as $item ) {
				$fonts[] = array(
					'name'   => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'slug'   => (string) ( $item['slug'] ?? '' ),
					'family' => (string) ( $item['fontFamily'] ?? '' ),
					'source' => $theme,
				);
			}
			foreach ( (array) ( $settings['typography']['fontSizes'][ $origin ] ?? array() ) as $item ) {
				if ( 'default' === $origin && ! empty( $settings['typography']['fontSizes']['theme'] ) ) {
					continue;
				}
				$sizes[] = array(
					'name'   => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'slug'   => (string) ( $item['slug'] ?? '' ),
					'value'  => is_scalar( $item['size'] ?? null ) ? (string) $item['size'] : '',
					'source' => 'default' === $origin ? 'WordPress default sizes' : $theme,
				);
			}
			foreach ( (array) ( $settings['spacing']['spacingSizes'][ $origin ] ?? array() ) as $item ) {
				$spacing[] = array(
					'name'   => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'slug'   => (string) ( $item['slug'] ?? '' ),
					'value'  => (string) ( $item['size'] ?? '' ),
					'source' => 'default' === $origin ? 'WordPress default spacing' : $theme,
				);
			}
		}//end foreach
		foreach ( array(
			'contentSize' => 'content_width',
			'wideSize'    => 'wide_width',
		) as $key => $label ) {
			if ( ! empty( $settings['layout'][ $key ] ) ) {
				$layout[ $label ] = (string) $settings['layout'][ $key ];
			}
		}
		if ( $colors || $fonts || $layout ) {
			$sources[] = $theme;
		}
		$styles = function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : array();
		foreach ( array(
			'page background' => $styles['color']['background'] ?? null,
			'page text'       => $styles['color']['text'] ?? null,
			'links'           => $styles['elements']['link']['color']['text'] ?? null,
		) as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$colors[] = array(
					'name'   => $name,
					'slug'   => '',
					'value'  => $value,
					'source' => 'global styles',
				);
			}
		}
		if ( is_string( $styles['typography']['fontFamily'] ?? null ) ) {
			$fonts[] = array(
				'name'   => 'body text',
				'slug'   => '',
				'family' => (string) $styles['typography']['fontFamily'],
				'source' => 'global styles',
			);
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			try {
				$kit = ElementorRead::kit();
				foreach ( (array) $kit['colors'] as $item ) {
					$colors[] = array(
						'name'   => (string) $item['title'],
						'slug'   => (string) $item['id'],
						'value'  => (string) $item['color'],
						'source' => 'Elementor Kit ' . (int) $kit['kit_id'] . ( $item['system'] ? ' (global)' : ' (custom)' ),
					);
				}
				foreach ( (array) $kit['fonts'] as $item ) {
					$fonts[] = array(
						'name'   => (string) $item['title'],
						'slug'   => (string) $item['id'],
						'family' => trim( (string) $item['family'] . ' ' . (string) $item['weight'] ),
						'source' => 'Elementor Kit ' . (int) $kit['kit_id'],
					);
				}
				if ( is_array( $kit['content_width'] ) && isset( $kit['content_width']['size'] ) && '' !== (string) $kit['content_width']['size'] ) {
					$layout['elementor_content_width'] = (string) $kit['content_width']['size'] . (string) ( $kit['content_width']['unit'] ?? 'px' );
				}
				$sources[] = 'Elementor Kit';
			} catch ( ToolError $e ) {
				// No active Kit: nothing from Elementor.
				unset( $e );
			}//end try
		}//end if

		foreach ( array(
			'background (Customizer)'  => get_theme_mod( 'background_color' ),
			'header text (Customizer)' => get_theme_mod( 'header_textcolor' ),
		) as $name => $value ) {
			if ( is_string( $value ) && preg_match( '/^[0-9a-f]{3,8}$/i', $value ) ) {
				$colors[]  = array(
					'name'   => $name,
					'slug'   => '',
					'value'  => '#' . $value,
					'source' => 'Customizer (' . get_stylesheet() . ')',
				);
				$sources[] = 'Customizer';
			}
		}

		$logo    = (int) get_theme_mod( 'custom_logo' );
		$site    = array(
			'name'    => (string) get_bloginfo( 'name' ),
			'tagline' => (string) get_bloginfo( 'description' ),
			'theme'   => (string) wp_get_theme()->get( 'Name' ),
			'logo'    => $logo > 0 ? (string) wp_get_attachment_url( $logo ) : '',
			'icon'    => (string) get_site_icon_url(),
		);
		$sources = array_values( array_unique( $sources ) );
		return array(
			'site'       => $site,
			'colors'     => $colors,
			'fonts'      => $fonts,
			'font_sizes' => $sizes,
			'spacing'    => $spacing,
			'layout'     => $layout ? $layout : new \stdClass(),
			'sources'    => $sources,
			'summary'    => self::summary( $site, $colors, $fonts, $layout ),
		);
	}

	/**
	 * A short text of the main tokens, to keep as a design memory.
	 *
	 * @param array<string, string>      $site   Site identity.
	 * @param list<array<string, mixed>> $colors Colors.
	 * @param list<array<string, mixed>> $fonts  Fonts.
	 * @param array<string, string>      $layout Layout.
	 */
	private static function summary( array $site, array $colors, array $fonts, array $layout ): string {
		$lines = array( 'Design of "' . $site['name'] . '" (theme ' . $site['theme'] . ').' );
		$main  = array();
		foreach ( array_slice( array_values( array_filter( $colors, static fn( array $c ): bool => ! str_starts_with( (string) $c['source'], 'WordPress default' ) ) ), 0, 12 ) as $c ) {
			$main[] = $c['name'] . ' ' . $c['value'];
		}
		if ( $main ) {
			$lines[] = 'Colors: ' . implode( ', ', $main ) . '.';
		}
		$families = array_values( array_unique( array_filter( array_map( static fn( array $f ): string => (string) $f['family'], $fonts ) ) ) );
		if ( $families ) {
			$lines[] = 'Fonts: ' . implode( '; ', array_slice( $families, 0, 6 ) ) . '.';
		}
		if ( $layout ) {
			$lines[] = 'Widths: ' . implode( ', ', array_map( static fn( string $k, string $v ): string => str_replace( '_', ' ', $k ) . ' ' . $v, array_keys( $layout ), $layout ) ) . '.';
		}
		return implode( "\n", $lines );
	}
}
