<?php
/**
 * Tools: kuduy-dynamic-shortcodes-types, kuduy-dynamic-shortcodes-lint, kuduy-dynamic-shortcodes-power.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Code;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Dev\DevText;

/**
 * Dynamic Shortcodes by Dynamic.ooo (phase L3), read through its own shortcodes manager (read on Dynamic Shortcodes
 * 1.9.4: DynamicShortcodes\Plugin::$instance->shortcodes_manager, core/shortcodes/manager.php): the shortcode types the
 * site has (get_shortcode_types_ids(), get_shortcode_type_class()), a check of text with dynamic shortcodes such as
 * {post:title} through its own lint() (its parser in strict mode; the text is never evaluated), and the saved Power
 * Shortcodes (get_power_shortcodes()). Types whose evaluate() asks for full privileges (for example call, which runs a
 * PHP function) are marked, and a check lists where the text uses them. Nothing here evaluates or saves shortcodes:
 * evaluating can run PHP functions, and saving Power Shortcodes is left to the plugin's screen (D4).
 */
final class DynamicShortcodes implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode types | lint | power.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-dynamic-shortcodes-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'types' => 'Dynamic Shortcodes types',
			'lint'  => 'Check Dynamic Shortcodes text',
			default => 'Dynamic Shortcodes Power Shortcodes',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'types' => 'Lists the Dynamic Shortcodes types this site has (the part before the colon in {post:title}), with the class that handles each and whether it needs full privileges (types such as call can run PHP functions and work only where the plugin grants them).',
			'lint'  => 'Checks text containing Dynamic Shortcodes (e.g. "Hello {user:display-name}") with the plugin\'s own parser in strict mode and reports syntax errors with line and column, the types used, and which of them need full privileges. The text is never evaluated.',
			default => 'Lists the Power Shortcodes saved in Dynamic Shortcodes: name, description and code (values that look like credentials are masked). Read only: Power Shortcodes are changed in the plugin\'s screen.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'lint' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'text' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 20000,
					),
				),
				'required'             => array( 'text' ),
				'additionalProperties' => false,
			);
		}
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
		return array(
			'type'       => 'object',
			'properties' => array(
				'types'            => array( 'type' => 'array' ),
				'valid'            => array( 'type' => 'boolean' ),
				'errors'           => array( 'type' => 'array' ),
				'types_used'       => array( 'type' => 'array' ),
				'privileged_used'  => array( 'type' => 'array' ),
				'power_shortcodes' => array( 'type' => 'array' ),
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
		return 'power' === $this->mode ? 'manage_options' : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return null === self::manager() ? 'Dynamic Shortcodes is not active.' : true;
	}

	/**
	 * The plugin's shortcodes manager, or null.
	 */
	private static function manager(): ?object {
		if ( ! class_exists( '\DynamicShortcodes\Plugin' ) ) {
			return null;
		}
		$plugin  = \DynamicShortcodes\Plugin::$instance ?? null;
		$manager = is_object( $plugin ) ? ( $plugin->shortcodes_manager ?? null ) : null;
		return is_object( $manager ) && method_exists( $manager, 'lint' ) ? $manager : null;
	}

	/**
	 * Whether a type class's evaluate() asks for full privileges (read from its source, the way it checks them).
	 *
	 * @param string $class_name Class.
	 */
	private static function privileged( string $class_name ): bool {
		if ( ! class_exists( $class_name ) || ! method_exists( $class_name, 'evaluate' ) ) {
			return false;
		}
		$method = new \ReflectionMethod( $class_name, 'evaluate' );
		$file   = (string) $method->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}
		$lines = file( $file );
		$body  = implode( '', array_slice( is_array( $lines ) ? $lines : array(), (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1 ) );
		return (bool) preg_match( '/(has|ensure)_all_privileges\s*\(/', $body );
	}

	/**
	 * Types: id => privileged.
	 *
	 * @param object $manager Manager.
	 * @return array<string, array{class: string, privileged: bool}>
	 */
	private static function types( object $manager ): array {
		$out = array();
		foreach ( (array) $manager->get_shortcode_types_ids() as $type ) {
			// Dynamic Shortcodes keeps [ class, context ] per type (manager.php register(); found by the live check).
			$entry                 = $manager->get_shortcode_type_class( (string) $type );
			$class                 = (string) ( is_array( $entry ) ? ( $entry[0] ?? '' ) : $entry );
			$out[ (string) $type ] = array(
				'class'      => $class,
				'privileged' => self::privileged( $class ),
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the plugin is not loaded.
	 */
	public function execute( array $input ): array {
		$manager = self::manager();
		if ( null === $manager ) {
			throw ToolError::of( ToolError::PLUGIN_NOT_ACTIVE, 'Dynamic Shortcodes is not active.' );
		}
		if ( 'power' === $this->mode ) {
			$rows = array();
			foreach ( (array) $manager->get_power_shortcodes() as $row ) {
				$rows[] = array(
					'name'        => (string) ( $row['name'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'code'        => DevText::mask( (string) ( $row['code'] ?? '' ) ),
				);
			}
			return array( 'power_shortcodes' => $rows );
		}
		$types = self::types( $manager );
		if ( 'types' === $this->mode ) {
			$rows = array();
			foreach ( $types as $type => $info ) {
				$rows[] = array( 'type' => $type ) + $info;
			}
			return array( 'types' => $rows );
		}
		$text   = (string) $input['text'];
		$result = (array) $manager->lint( $text );
		preg_match_all( '/\{!?([a-zA-Z][a-zA-Z0-9_-]*):/', $text, $found );
		$used    = array_values( array_unique( array_map( 'strtolower', $found[1] ) ) );
		$unknown = array_values( array_diff( $used, array_keys( $types ) ) );
		$errors  = array_values( array_map( 'strval', (array) ( $result['errors'] ?? array() ) ) );
		foreach ( $unknown as $type ) {
			$errors[] = 'Unknown type "' . $type . '" on this site.';
		}
		return array(
			'valid'           => ! empty( $result['valid'] ) && ! $unknown,
			'errors'          => $errors,
			'types_used'      => $used,
			'privileged_used' => array_values( array_filter( $used, static fn( $t ) => ! empty( $types[ $t ]['privileged'] ) ) ),
		);
	}
}
