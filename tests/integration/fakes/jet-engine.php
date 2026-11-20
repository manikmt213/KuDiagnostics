<?php
/**
 * A stand-in for JetEngine in tests (licensed copy not in Playground), with its Features API switched on: the
 * registry and feature classes KuDiagnostics calls, with the names and behaviour read on JetEngine 3.8.15.4
 * (includes/core/mcp-tools/registry.php, feature.php, features/get-configuration.php): get_features(),
 * get_feature( "tool-…" ), Feature::get_name() = type-id, check_permissions() = manage_options when the feature sets
 * none, run(); get-configuration takes parts as a map part => true. Three features: get-configuration, add-cpt
 * (stores the post type in an option) and manage-modules (list). Loaded with KUDUY_FAKES=jet-engine.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Files.OneDeclarationPerFile, WordPress.NamingConventions -- test fake.

namespace Jet_Engine\MCP_Tools {

	class Feature {

		public function __construct( private string $id, private array $args = array() ) {}

		public function get_name() {
			return ( $this->args['type'] ?? 'tool' ) . '-' . $this->id;
		}

		public function get_label() {
			return $this->args['label'] ?? $this->id;
		}

		public function get_description() {
			return $this->args['description'] ?? '';
		}

		public function get_input_schema() {
			return array(
				'type'       => 'object',
				'properties' => $this->args['input_schema'] ?? array(),
			);
		}

		public function check_permissions( $request ) {
			if ( ! is_callable( $this->args['permission_callback'] ?? null ) ) {
				return current_user_can( 'manage_options' );
			}
			return call_user_func( $this->args['permission_callback'], $request );
		}

		public function run( $input = array() ) {
			return call_user_func( $this->args['execute_callback'], $input );
		}
	}

	class Registry {

		private static $instance = null;
		private $features        = array();

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
				self::$instance->add_feature(
					'get-configuration',
					array(
						'type'             => 'resource',
						'label'            => 'Get configuration',
						'description'      => 'Returns JetEngine configuration parts.',
						'input_schema'     => array( 'parts' => array( 'type' => 'object' ) ),
						'execute_callback' => static function ( $input ) {
							$parts = (array) ( $input['parts'] ?? array() );
							return array_filter(
								array(
									'success'    => true,
									'post_types' => ! empty( $parts['post_types'] ) ? array_values( (array) get_option( 'kuduy_fake_jet_cpts', array() ) ) : null,
									'meta_boxes' => ! empty( $parts['meta_boxes'] ) ? array() : null,
								),
								static fn( $v ) => null !== $v
							);
						},
					)
				);
				self::$instance->add_feature(
					'add-cpt',
					array(
						'label'            => 'Add Post Type',
						'description'      => 'Register a new Post Type with JetEngine.',
						'input_schema'     => array( 'general_settings' => array( 'type' => 'object' ) ),
						'execute_callback' => static function ( $input ) {
							$name = (string) ( $input['general_settings']['name'] ?? '' );
							if ( '' === $name ) {
								return new \WP_Error( 'missing_name', 'Post type name is required.' );
							}
							$slug   = sanitize_key( $input['general_settings']['slug'] ?? $name );
							$cpts   = (array) get_option( 'kuduy_fake_jet_cpts', array() );
							$cpts[] = array(
								'slug' => $slug,
								'name' => $name,
							);
							update_option( 'kuduy_fake_jet_cpts', $cpts );
							return array(
								'success' => true,
								'slug'    => $slug,
							);
						},
					)
				);
				self::$instance->add_feature(
					'manage-modules',
					array(
						'label'            => 'Manage modules',
						'description'      => 'List active modules or activate/deactivate JetEngine modules.',
						'execute_callback' => static fn() => array(
							'success' => true,
							'active'  => array( 'custom-content-types' ),
						),
					)
				);
			}
			return self::$instance;
		}

		public function add_feature( $id, $args = array() ) {
			$this->features[] = new Feature( $id, $args );
		}

		public function get_features() {
			return $this->features;
		}

		public function get_feature( $name ) {
			foreach ( $this->features as $feature ) {
				if ( $feature->get_name() === $name ) {
					return $feature;
				}
			}
			return null;
		}
	}
}

namespace {

	class Jet_Engine {}

	function jet_engine() {
		static $engine = null;
		return $engine ??= new Jet_Engine();
	}
}
