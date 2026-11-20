<?php
/**
 * A stand-in for Dynamic Shortcodes (Dynamic.ooo) in tests (licensed copy not in Playground): the plugin instance and
 * the shortcodes-manager methods KuDiagnostics calls, with the names and return shapes read on Dynamic Shortcodes 1.9.4
 * (plugin.php, core/shortcodes/manager.php): get_shortcode_types_ids(), get_shortcode_type_class(), lint() returning
 * {valid, errors, shortcode}, get_power_shortcodes() from its JSON option. Four types; Call checks full privileges in
 * evaluate() as the real one does. Loaded with KUDUY_FAKES=dynamic-shortcodes; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Files.OneDeclarationPerFile -- test fake.

namespace DynamicShortcodes\Core\Shortcodes\Types {

	class Post {
		public function evaluate() {
			return get_the_title();
		}
	}

	class User {
		public function evaluate() {
			return wp_get_current_user()->display_name;
		}
	}

	class Option {
		public function evaluate() {
			$this->ensure_all_privileges();
			return '';
		}

		private function ensure_all_privileges() {}
	}

	class Call {
		public function evaluate() {
			if ( ! $this->has_all_privileges() ) {
				return '';
			}
			return '';
		}

		private function has_all_privileges() {
			return false;
		}
	}
}

namespace DynamicShortcodes\Core\Shortcodes {

	class Manager {

		private const TYPES = array(
			'post'   => Types\Post::class,
			'user'   => Types\User::class,
			'option' => Types\Option::class,
			'call'   => Types\Call::class,
		);

		public function get_shortcode_types_ids() {
			return array_keys( self::TYPES );
		}

		public function get_shortcode_type_class( $type ) {
			return isset( self::TYPES[ $type ] ) ? array( self::TYPES[ $type ], null ) : array( false, null );
		}

		public function lint( string $shortcode ) {
			$errors = array();
			$depth  = 0;
			$line   = 1;
			$col    = 0;
			$len    = strlen( $shortcode );
			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $shortcode[ $i ];
				++$col;
				if ( "\n" === $ch ) {
					++$line;
					$col = 0;
				} elseif ( '{' === $ch ) {
					++$depth;
				} elseif ( '}' === $ch ) {
					--$depth;
				}
			}
			if ( $depth > 0 ) {
				$errors[] = "Syntax error: unexpected token EOF on line $line, column $col";
			}
			if ( ! $errors && preg_match_all( '/\{([a-zA-Z][a-zA-Z0-9_-]*+)([^:}])/', $shortcode, $m ) ) {
				foreach ( $m[1] as $n => $type ) {
					$errors[] = 'Syntax error: missing colon after type `{' . $type . '` (got `' . $m[2][ $n ] . '`)';
				}
			}
			return array(
				'valid'     => array() === $errors,
				'errors'    => $errors,
				'shortcode' => $shortcode,
			);
		}

		public function get_power_shortcodes(): array {
			$raw     = get_option( 'dsh_power_shortcodes_json', '' );
			$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
			return is_array( $decoded ) ? $decoded : array();
		}
	}
}

namespace DynamicShortcodes {

	class Plugin {

		public static $instance;

		public $shortcodes_manager;

		public function __construct() {
			self::$instance           = $this;
			$this->shortcodes_manager = new Core\Shortcodes\Manager();
		}
	}

	new Plugin();
}
