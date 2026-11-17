<?php
/**
 * Block plugins' own tools (phase C2, test matrix BP1–BP8) against the real GenerateBlocks 2.5.1, Kadence Blocks
 * 3.9.1, Spectra Legacy 2.20.6 and Spectra Blocks 1.0.15 (job "+ block plugins"; each test is skipped when its
 * plugin is not active).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class BlockPluginsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Skip unless a constant of the plugin is defined.
	 *
	 * @param string $constant Constant.
	 * @param string $plugin   Plugin name.
	 */
	private function needs( string $constant, string $plugin ): void {
		if ( ! defined( $constant ) ) {
			$this->markTestSkipped( $plugin . ' is not active in this run.' );
		}
	}

	public function test_generateblocks_status_and_settings(): void {
		$this->needs( 'GENERATEBLOCKS_VERSION', 'GenerateBlocks' );
		$status = $this->ok( 'kuduy-generateblocks-status' );
		$this->assertSame( GENERATEBLOCKS_VERSION, $status['version'] );
		$this->assertFalse( $status['pro'] );
		$this->assertArrayHasKey( 'container_width', $status['settings'] );
		$this->assertContains( 'generateblocks/text', array_column( $status['blocks'], 'name' ) );

		$result = $this->ok( 'kuduy-generateblocks-settings-update', array( 'settings' => array( 'container_width' => '1240' ) ) );
		$this->assertSame( 1240, $result['settings']['container_width'], 'GenerateBlocks\' own sanitizer (absint) ran.' );
		$this->assertSame( 1240, get_option( 'generateblocks' )['container_width'] );
		$this->fails( 'kuduy-generateblocks-settings-update', array( 'settings' => array( 'not_a_setting' => 1 ) ), 'Unknown keys are refused' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertNotSame( 1240, ( (array) get_option( 'generateblocks', array() ) )['container_width'] ?? null, 'Undo puts the stored settings back.' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-generateblocks-settings-update', array( 'settings' => array( 'container_width' => 900 ) ), 'Editors cannot change plugin settings' );
	}

	public function test_kadence_status_and_settings(): void {
		$this->needs( 'KADENCE_BLOCKS_VERSION', 'Kadence Blocks' );
		update_option( 'kadence_blocks_recaptcha_secret_key', 'kuduy-test-secret' );
		$status = $this->ok( 'kuduy-kadence-blocks-status' );
		$this->assertSame( KADENCE_BLOCKS_VERSION, $status['version'] );
		$this->assertContains( 'kadence/rowlayout', array_column( $status['blocks'], 'name' ) );
		$secret = $status['settings']['kadence_blocks_recaptcha_secret_key'];
		$this->assertTrue( $secret['write_only'] );
		$this->assertTrue( $secret['has_value'] );
		$this->assertArrayNotHasKey( 'value', $secret );
		$this->assertStringNotContainsString( 'kuduy-test-secret', (string) wp_json_encode( $status ), 'A secret never leaves the site.' );

		$result = $this->ok( 'kuduy-kadence-blocks-settings-update', array( 'settings' => array( 'kadence_blocks_recaptcha_language' => 'de' ) ) );
		$this->assertSame( 'de', get_option( 'kadence_blocks_recaptcha_language' ) );
		$this->fails( 'kuduy-kadence-blocks-settings-update', array( 'settings' => array( 'kadence_blocks_recaptcha_secret_key' => 'x' ) ), 'Secrets are not changed here' );
		$this->assertSame( 'kuduy-test-secret', get_option( 'kadence_blocks_recaptcha_secret_key' ) );
		$this->fails( 'kuduy-kadence-blocks-settings-update', array( 'settings' => array( 'kadence_blocks_nope' => 'x' ) ), 'Unknown keys are refused' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertNotSame( 'de', get_option( 'kadence_blocks_recaptcha_language' ) );
	}

	public function test_spectra_status_and_block_switch(): void {
		$this->needs( 'UAGB_VER', 'Spectra Legacy' );
		$status = $this->ok( 'kuduy-spectra-status' );
		$this->assertSame( UAGB_VER, $status['version'] );
		$blocks = array_column( $status['blocks'], 'enabled', 'name' );
		$this->assertArrayHasKey( 'uagb/advanced-heading', $blocks );
		$this->assertTrue( $blocks['uagb/advanced-heading'] );

		$result = $this->ok( 'kuduy-spectra-block-toggle', array( 'disable' => array( 'advanced-heading' ) ) );
		$this->assertSame( array( 'uagb/advanced-heading' ), $result['changed'] );
		$this->assertSame( 'disabled', get_option( '_uagb_blocks' )['advanced-heading'] );
		$this->assertFalse( array_column( $this->ok( 'kuduy-spectra-status' )['blocks'], 'enabled', 'name' )['uagb/advanced-heading'] );
		$this->fails( 'kuduy-spectra-block-toggle', array( 'enable' => array( 'no-such-block' ) ), 'Unknown blocks are refused' );
		$this->fails(
			'kuduy-spectra-block-toggle',
			array(
				'enable'  => array( 'advanced-heading' ),
				'disable' => array( 'uagb/advanced-heading' ),
			),
			'A block in both lists is refused'
		);

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $result['_snapshot_id'] ) );
		$this->assertTrue( array_column( $this->ok( 'kuduy-spectra-status' )['blocks'], 'enabled', 'name' )['uagb/advanced-heading'] );
	}

	public function test_spectra_legacy_settings(): void {
		$this->needs( 'UAGB_VER', 'Spectra Legacy' );
		$status = $this->ok( 'kuduy-spectra-status' )['settings'];
		$this->assertSame( 'disabled', $status['uag_visibility_mode'] );
		$this->assertSame( 'disabled', $status['uag_enable_block_condition'] );
		$this->assertArrayNotHasKey( 'uag_recaptcha_secret_key_v2', $status, 'Secrets are not shown.' );

		$changed = $this->ok(
			'kuduy-spectra-settings-update',
			array(
				'settings' => array(
					'uag_enable_block_condition' => 'enabled',
					'uag_content_width'          => '1200',
					'uag_dynamic_content_mode'   => 'sidebar',
				),
			)
		);
		$this->assertSame( 'enabled', $changed['settings']['uag_enable_block_condition'] );
		$this->assertSame( '1200', get_option( 'uag_content_width' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertFalse( get_option( 'uag_content_width' ), 'Undo removes an option that was not set before.' );
		$this->assertSame( 'disabled', $this->ok( 'kuduy-spectra-status' )['settings']['uag_enable_block_condition'] );

		$this->fails( 'kuduy-spectra-settings-update', array( 'settings' => array( 'uag_recaptcha_secret_key_v2' => 'x' ) ), 'secrets are not written' );
		$this->fails( 'kuduy-spectra-settings-update', array( 'settings' => array( 'uag_copy_paste' => 'maybe' ) ), 'unknown value' );
		$this->fails( 'kuduy-spectra-settings-update', array( 'settings' => array( 'uag_visibility_mode' => 'maintenance' ) ), 'maintenance needs a page' );
		$post = (int) wp_insert_post(
			array(
				'post_title'  => 'Not a page',
				'post_status' => 'publish',
			)
		);
		$this->fails( 'kuduy-spectra-settings-update', array( 'settings' => array( 'uag_visibility_page' => (string) $post ) ), 'a post is not a page' );
		$page = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy coming soon',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$mode = $this->ok(
			'kuduy-spectra-settings-update',
			array(
				'settings' => array(
					'uag_visibility_page' => (string) $page,
					'uag_visibility_mode' => 'comingsoon',
				),
			)
		);
		$this->assertSame( 'comingsoon', get_option( 'uag_visibility_mode' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $mode['_snapshot_id'] ) );
		$this->assertFalse( get_option( 'uag_visibility_mode' ) );
		$this->assertSame( 'kuduy:admin', \Kuduy\Mcp\Mcp\ToolRegistry::create()->get( 'kuduy-spectra-settings-update' )->scope() );
	}

	/**
	 * Popup tools of one Spectra plugin.
	 *
	 * @param string $plugin spectra | spectra-blocks.
	 * @param string $block  Popup builder block.
	 * @param string $meta   Field prefix.
	 */
	private function popups( string $plugin, string $block, string $meta ): void {
		$new = $this->ok(
			'kuduy-' . $plugin . '-popup-save',
			array(
				'title'      => 'Kuduy popup',
				'type'       => 'popup',
				'enabled'    => false,
				'repetition' => 2,
			)
		);
		$this->assertSame( 'draft', $new['status'] );
		$this->assertSame( 'popup', $new['type'] );
		$this->assertFalse( $new['enabled'] );
		$this->assertSame( 2, $new['repetition'] );
		$this->assertStringContainsString( '<!-- wp:' . $block, $new['content'] );
		$this->assertSame( 'popup', get_post_meta( $new['id'], $meta . 'type', true ) );
		$this->assertContains( $new['id'], array_column( $this->ok( 'kuduy-' . $plugin . '-popups' )['popups'], 'id' ) );

		$changed = $this->ok(
			'kuduy-' . $plugin . '-popup-save',
			array(
				'id'      => $new['id'],
				'enabled' => true,
				'content' => '<!-- wp:' . $block . " -->\n<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n<!-- /wp:" . $block . ' -->',
			)
		);
		$this->assertTrue( $changed['enabled'] );
		$this->assertSame( 'Kuduy popup', $changed['title'], 'Fields not given stay.' );
		$this->assertStringContainsString( '<p>Hello</p>', $this->ok( 'kuduy-' . $plugin . '-popup-get', array( 'id' => $new['id'] ) )['content'] );

		$this->fails(
			'kuduy-' . $plugin . '-popup-save',
			array(
				'id'      => $new['id'],
				'content' => "<!-- wp:paragraph -->\n<p>No popup block</p>\n<!-- /wp:paragraph -->",
			),
			'Content must be one popup builder block'
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertFalse( (bool) get_post_meta( $new['id'], $meta . 'enabled', true ), 'Undo switches it off again.' );

		$trash = $this->ok( 'kuduy-' . $plugin . '-popup-trash', array( 'id' => $new['id'] ) );
		$this->assertSame( 'trash', get_post_status( $new['id'] ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$this->assertSame( 'draft', get_post_status( $new['id'] ) );

		$this->fails( 'kuduy-' . $plugin . '-popup-get', array( 'id' => 999999 ), 'Unknown popup' );
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-' . $plugin . '-popups', array(), 'Popups are for site managers, as in the plugin' );
		wp_set_current_user( self::$admin );
	}

	public function test_spectra_legacy_popups(): void {
		$this->needs( 'UAGB_VER', 'Spectra Legacy' );
		$this->popups( 'spectra', 'uagb/popup-builder', 'spectra-popup-' );
	}

	public function test_spectra_blocks_popups(): void {
		$this->needs( 'SPECTRA_BLOCKS_VER', 'Spectra Blocks' );
		$this->popups( 'spectra-blocks', 'spectra/popup-builder', 'spectra-blocks-popup-' );
	}

	public function test_spectra_blocks_abilities(): void {
		$this->needs( 'SPECTRA_BLOCKS_VER', 'Spectra Blocks' );
		$list = $this->ok( 'kuduy-spectra-blocks-abilities' );
		$rows = array_column( $list['abilities'], null, 'name' );
		if ( ! $rows ) {
			$this->markTestSkipped( 'Spectra Blocks abilities are switched off in this run.' );
		}
		$this->assertArrayHasKey( 'spectra-blocks/list-available-blocks', $rows );
		$this->assertSame( 'not run here', $rows['spectra-blocks/delete-popup']['run_with'] ?? null, 'Deleting for good is never run.' );
		$this->assertSame( 'not run here', $rows['spectra-blocks/update-plugin-setting']['run_with'] ?? null, 'Site-wide settings are not run.' );
		$blocks = $this->ok(
			'kuduy-spectra-blocks-ability-get',
			array(
				'name'  => 'spectra-blocks/list-available-blocks',
				'input' => new \stdClass(),
			)
		);
		$this->assertNotEmpty( $blocks['result'] );
	}

	public function test_tools_hidden_without_their_plugin(): void {
		foreach ( array(
			'kuduy-generateblocks-status' => 'GENERATEBLOCKS_VERSION',
			'kuduy-kadence-blocks-status' => 'KADENCE_BLOCKS_VERSION',
			'kuduy-spectra-status'        => 'UAGB_VER',
			'kuduy-spectra-blocks-popups' => 'SPECTRA_BLOCKS_VER',
		) as $tool => $constant ) {
			$this->assertSame( defined( $constant ), true === \Kuduy\Mcp\Mcp\ToolRegistry::create()->get( $tool )->is_available(), $tool );
		}
	}
}
