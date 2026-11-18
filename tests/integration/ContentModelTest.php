<?php
/**
 * Content model (test matrix FE6, phase E6, D58): post types and taxonomies with where they are defined, fields from
 * ACF and Meta Box per post type, and the clashes: one meta key used by both plugins on posts, and a post type defined
 * both by KuDiagnostics and by ACF. Runs fully when ACF and Meta Box are active (KUDUY_PLUGINS=advanced-custom-fields@…,
 * meta-box@…); without them only the core part is checked.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Types\ContentTypes;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ContentModelTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_fe6_core_part(): void {
		$model = $this->ok( 'kuduy-content-model', array( 'post_type' => 'post' ) );
		$this->assertSame( 'post', $model['post_types'][0]['post_type'] );
		$this->assertSame( 'core', $model['post_types'][0]['defined_by'] );
		$this->assertContains( 'category', $model['post_types'][0]['taxonomies'] );
		$this->assertContains( 'core', array_column( $model['taxonomies'], 'defined_by' ) );
	}

	public function test_fe6_fields_and_clashes(): void {
		if ( ! function_exists( 'acf_import_field_group' ) || ! function_exists( 'rwmb_get_registry' ) ) {
			$this->markTestSkipped( 'Needs ACF and Meta Box active together (KUDUY_PLUGINS).' );
		}
		acf_import_field_group(
			array(
				'key'      => 'group_kuduy_model',
				'title'    => 'Model test',
				'fields'   => array(
					array(
						'key'   => 'field_kuduy_model_isbn',
						'label' => 'ISBN',
						'name'  => 'kuduy_model_isbn',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
		rwmb_get_registry( 'meta_box' )->make(
			array(
				'id'         => 'kuduy_model_box',
				'title'      => 'Model box',
				'post_types' => array( 'post' ),
				'fields'     => array(
					array(
						'id'   => 'kuduy_model_isbn',
						'name' => 'ISBN (Meta Box)',
						'type' => 'text',
					),
				),
			)
		)->register_fields();

		$defs                             = ContentTypes::stored();
		$defs['post_types']['kuduy_twin'] = array(
			'singular' => 'Twin',
			'plural'   => 'Twins',
		);
		ContentTypes::save( $defs );
		acf_import_post_type(
			array(
				'key'       => 'post_type_kuduy_twin',
				'post_type' => 'kuduy_twin',
				'title'     => 'Twins',
				'labels'    => array(
					'name'          => 'Twins',
					'singular_name' => 'Twin',
				),
				'active'    => 1,
			)
		);

		$model = $this->ok( 'kuduy-content-model' );
		$this->assertContains( 'acf', $model['sources'] );
		$this->assertContains( 'meta-box', $model['sources'] );
		$posts  = array_column( $model['post_types'], null, 'post_type' )['post'];
		$fields = array_column( $posts['fields'], 'source', 'label' );
		$this->assertSame( 'acf', $fields['ISBN'] );
		$this->assertSame( 'meta-box', $fields['ISBN (Meta Box)'] );

		$clashes = array();
		foreach ( $model['clashes'] as $clash ) {
			$clashes[ $clash['kind'] . ':' . $clash['key'] ] = $clash['sources'];
		}
		$this->assertArrayHasKey( 'meta_key:kuduy_model_isbn', $clashes );
		$this->assertEqualsCanonicalizing( array( 'acf', 'meta-box' ), $clashes['meta_key:kuduy_model_isbn'] );
		$this->assertArrayHasKey( 'post_type:kuduy_twin', $clashes, wp_json_encode( $model['clashes'] ) );
		$this->assertEqualsCanonicalizing( array( 'kuduy-mcp', 'acf' ), $clashes['post_type:kuduy_twin'] );

		unset( $defs['post_types']['kuduy_twin'] );
		ContentTypes::save( $defs );
	}
}
