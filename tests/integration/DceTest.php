<?php
/**
 * Dynamic Content for Elementor (test matrix DC1, phase L7): features listed and filtered, two switched off through
 * its own feature store with undo, an unknown feature refused with nothing changed, PDF HTML templates listed and one
 * read with credentials masked, editors refused. DCE is paid: this runs against the stand-in (KUDUY_FAKES=dce), so it
 * proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class DceTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( '\DynamicContentForElementor\Plugin' ) ) {
			self::markTestSkipped( 'Dynamic Content for Elementor (or its stand-in) is not loaded (KUDUY_FAKES=dce).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	public function test_dc1_features_and_templates(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$widgets = $this->ok( 'kuduy-dce-features', array( 'type' => 'widget' ) )['features'];
		$this->assertSame( array( 'wdg_modals', 'wdg_pdf_button' ), array_column( $widgets, 'id' ) );
		$this->assertTrue( $widgets[0]['active'] );

		$off = $this->ok(
			'kuduy-dce-feature-toggle',
			array(
				'features' => array(
					'wdg_modals'     => false,
					'ext_animations' => false,
				),
			)
		);
		$this->assertSame( array( false, false ), array_column( $off['features'], 'active' ) );
		$this->assertSame( 'inactive', json_decode( (string) get_option( 'dce_features_status_option' ), true )['wdg_modals'], 'Saved in DCE\'s own option.' );
		$this->assertTrue( array_column( $this->ok( 'kuduy-dce-features' )['features'], 'active', 'id' )['wdg_pdf_button'], 'Other features keep their state.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$this->assertTrue( array_column( $this->ok( 'kuduy-dce-features' )['features'], 'active', 'id' )['wdg_modals'], 'Undo switches it on again.' );

		$this->fails( 'kuduy-dce-feature-toggle', array( 'features' => array( 'nope' => false ) ), 'unknown feature' );

		$tpl = (int) wp_insert_post(
			array(
				'post_type'   => 'dce_html_template',
				'post_title'  => 'Kuduy invoice',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $tpl, 'dce-html-code', '<h1>Invoice</h1> <!-- api_key = "kuduy-live-0123456789abcdef" -->' );
		update_post_meta( $tpl, 'dce-html-format', 'A4' );
		$this->assertSame( 'Kuduy invoice', $this->ok( 'kuduy-dce-html-templates' )['templates'][0]['title'] );
		$one = $this->ok( 'kuduy-dce-html-templates', array( 'id' => $tpl ) )['template'];
		$this->assertSame( 'A4', $one['format'] );
		$this->assertStringContainsString( '<h1>Invoice</h1>', $one['code'] );
		$this->assertStringNotContainsString( 'kuduy-live-0123456789abcdef', $one['code'], 'Credentials are masked.' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-dce-features', array(), 'DCE settings are for site managers' );
	}
}
