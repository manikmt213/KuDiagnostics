<?php
/**
 * JetEngine (test matrix JE1, phase L6, D61): its features listed, configuration read through its own
 * get-configuration feature, a post type added through its add-cpt feature, its refusal passed on, an unknown feature
 * refused, editors refused by JetEngine's own permission check. JetEngine is paid: this runs against the stand-in
 * (KUDUY_FAKES=jet-engine), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class JetEngineTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( '\Jet_Engine\MCP_Tools\Registry' ) ) {
			self::markTestSkipped( 'JetEngine with its Features API (or its stand-in) is not loaded (KUDUY_FAKES=jet-engine).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	public function test_je1_features_config_run(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$names = array_column( $this->ok( 'kuduy-jetengine-features' )['features'], 'name' );
		$this->assertContains( 'tool-add-cpt', $names );
		$this->assertContains( 'resource-get-configuration', $names );

		$made = $this->ok(
			'kuduy-jetengine-run',
			array(
				'feature' => 'tool-add-cpt',
				'input'   => array(
					'general_settings' => array(
						'name' => 'Kuduy Books',
						'slug' => 'kuduy-books',
					),
				),
			)
		);
		$this->assertSame( 'kuduy-books', $made['result']['slug'] );

		$config = $this->ok( 'kuduy-jetengine-config', array( 'parts' => array( 'post_types' ) ) )['result'];
		$this->assertSame( 'kuduy-books', $config['post_types'][0]['slug'] );
		$this->assertArrayNotHasKey( 'meta_boxes', $config, 'Only the asked parts.' );

		$this->fails(
			'kuduy-jetengine-run',
			array(
				'feature' => 'tool-add-cpt',
				'input'   => array( 'general_settings' => array() ),
			),
			'JetEngine refuses a post type without a name'
		);
		$this->fails( 'kuduy-jetengine-run', array( 'feature' => 'tool-nope' ), 'no such feature' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-jetengine-config', array(), 'JetEngine lets only site managers run its features' );
	}
}
