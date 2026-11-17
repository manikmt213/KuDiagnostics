<?php
/**
 * Tool: kuduy-shortcodes.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

/**
 * The shortcodes registered on this site (phase G3): WordPress' own `$shortcode_tags`, each with where its callback
 * is defined (WordPress, a plugin or the theme, found by reflection on the callback), so a shortcode in content can
 * be traced to what provides it. Read only; nothing is run.
 */
final class Shortcodes implements \Kuduy\Mcp\Mcp\Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-shortcodes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Registered shortcodes', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists the shortcodes registered on this site (e.g. [gallery], [contact-form-7], [products]) with what provides each: core (WordPress itself), a plugin (its folder) or the theme, and the callback. Filter with search or source. Read only; shortcodes are not run. To find where a shortcode is used, search content with kuduy-post-list.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'source' => array(
					'type' => 'string',
					'enum' => array( 'core', 'plugin', 'theme', 'other' ),
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
				'count'      => array( 'type' => 'integer' ),
				'shortcodes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'tag'      => array( 'type' => 'string' ),
							'source'   => array( 'type' => 'string' ),
							'provider' => array( 'type' => 'string' ),
							'callback' => array( 'type' => 'string' ),
						),
					),
				),
			),
			'required'   => array( 'count', 'shortcodes' ),
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
	 * Where a callback is defined: [source, provider, readable callback].
	 *
	 * @param mixed $callback Callback.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function origin( mixed $callback ): array {
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				$reflection = new \ReflectionMethod( $class, (string) $callback[1] );
				$label      = $class . '::' . $callback[1];
			} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( $callback );
				$label      = $callback;
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
				$label      = is_string( $callback ) ? $callback : 'closure';
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
				$label      = get_class( $callback ) . '::__invoke';
			} else {
				return array( 'other', '', '' );
			}
		} catch ( \ReflectionException $e ) {
			return array( 'other', '', '' );
		}
		$file    = wp_normalize_path( (string) $reflection->getFileName() );
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu      = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		foreach ( array(
			'plugin' => $plugins,
			'mu'     => $mu,
			'theme'  => $themes,
		) as $source => $root ) {
			if ( str_starts_with( $file, $root ) ) {
				$folder = explode( '/', substr( $file, strlen( $root ) ) )[0];
				return array( 'mu' === $source ? 'plugin' : $source, $folder, $label );
			}
		}
		if ( str_starts_with( $file, wp_normalize_path( ABSPATH . 'wp-includes' ) ) ) {
			return array( 'core', 'WordPress', $label );
		}
		return array( 'other', '', $label );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		global $shortcode_tags;
		$search = strtolower( (string) ( $input['search'] ?? '' ) );
		$out    = array();
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			[ $source, $provider, $label ] = self::origin( $callback );
			if ( ( isset( $input['source'] ) && $input['source'] !== $source ) || ( '' !== $search && ! str_contains( strtolower( $tag . ' ' . $provider ), $search ) ) ) {
				continue;
			}
			$out[] = array(
				'tag'      => (string) $tag,
				'source'   => $source,
				'provider' => $provider,
				'callback' => $label,
			);
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['tag'], $b['tag'] ) );
		return array(
			'count'      => count( $out ),
			'shortcodes' => $out,
		);
	}
}
