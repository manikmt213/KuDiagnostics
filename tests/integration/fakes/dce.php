<?php
/**
 * A stand-in for Dynamic Content for Elementor in tests (licensed copy not in Playground): its plugin instance,
 * feature store and PDF HTML template post type, with the names and behaviour read on DCE 3.4.20 (core/plugin.php,
 * class/features.php, class/pdf-html-templates.php): features keyed by id (name differs), status active/inactive,
 * filter() through wp_list_filter, db_update_features_status( subset ) merged over the current status and saved as
 * JSON in dce_features_status_option; post type dce_html_template with meta dce-html-code, dce-html-format,
 * dce-html-orientation. Loaded with KUDUY_FAKES=dce; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Files.OneDeclarationPerFile -- test fake.

namespace DynamicContentForElementor {

	class Features {

		public $all_features = array();

		public function __construct() {
			$status = json_decode( (string) get_option( 'dce_features_status_option', '{}' ), true );
			foreach ( array(
				'ext_animations' => array( 'dce_extension_animations', 'extension', 'Dynamic Animations' ),
				'wdg_pdf_button' => array( 'dce-pdf-button', 'widget', 'PDF Button' ),
				'wdg_modals'     => array( 'dce-modalwindow', 'widget', 'Modals' ),
			) as $key => [ $name, $type, $title ] ) {
				$this->all_features[ $key ] = array(
					'name'   => $name,
					'type'   => $type,
					'title'  => $title,
					'status' => is_array( $status ) && isset( $status[ $key ] ) ? $status[ $key ] : 'active',
				);
			}
		}

		public function filter( array $args, string $operator = 'AND' ) {
			return wp_list_filter( $this->all_features, $args, $operator );
		}

		public function db_update_features_status( $subset_status ) {
			$before     = array_map( static fn( $f ) => $f['status'], $this->all_features );
			$new_status = $subset_status + $before;
			update_option( 'dce_features_status_option', wp_json_encode( $new_status ) );
			foreach ( $new_status as $key => $value ) {
				$this->all_features[ $key ]['status'] = $value;
			}
		}
	}

	class Plugin {

		private static $instance = null;

		public $features;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance           = new self();
				self::$instance->features = new Features();
			}
			return self::$instance;
		}
	}
}

namespace {

	add_action(
		'init',
		static function () {
			register_post_type(
				'dce_html_template',
				array(
					'public'          => false,
					'show_ui'         => true,
					'capability_type' => 'post',
					'supports'        => array( 'title' ),
				)
			);
		}
	);
}
