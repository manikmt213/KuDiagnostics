<?php
/**
 * Phase R (test matrix WC1–WC5): WPCode tools against the real WPCode Lite 2.4.2 with its "AI & Automation Access"
 * setting on (job "+ WPCode"; set by scripts/test-integration.mjs).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WpCodeTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		if ( ! defined( 'WPCODE_VERSION' ) ) {
			$this->markTestSkipped( 'WPCode is not active in this run.' );
		}
		wp_set_current_user( self::$admin );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	/**
	 * Run a confirmable call through both steps.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function confirmed( string $tool, array $args ): array {
		$preview = $this->ok( $tool, $args );
		$this->assertTrue( $preview['confirmation_required'] );
		return array( $preview, $this->ok( $tool, $args + array( 'confirm_token' => $preview['confirm_token'] ) ) );
	}

	/**
	 * WC1: a CSS snippet is created, listed, read, changed and the change undone, through WPCode's abilities.
	 */
	public function test_css_snippet_lifecycle(): void {
		[ $preview, $saved ] = $this->confirmed(
			'kuduy-wpcode-snippet-save',
			array(
				'title'     => 'Kuduy WPCode styles',
				'code'      => '.kuduy-wpcode { color: red; }',
				'code_type' => 'css',
				'location'  => 'site_wide_header',
				'active'    => true,
				'tags'      => array( 'kuduy' ),
			)
		);
		$this->assertSame( 'create', $preview['preview']['action'] );
		$this->assertStringContainsString( 'does not make code safe', $preview['preview']['check']['note'] );
		$this->assertSame( 'css', $saved['code_type'] );
		$this->assertTrue( $saved['active'] );
		$this->assertSame( 'site_wide_header', $saved['location'] );
		$id = $saved['id'];

		$listed = array_column( $this->ok( 'kuduy-wpcode-snippets', array( 'code_type' => 'css' ) )['snippets'], 'title', 'id' );
		$this->assertSame( 'Kuduy WPCode styles', $listed[ $id ] ?? null );
		$this->assertSame( '.kuduy-wpcode { color: red; }', $this->ok( 'kuduy-wpcode-snippet-get', array( 'id' => $id ) )['code'] );

		[ , $changed ] = $this->confirmed(
			'kuduy-wpcode-snippet-save',
			array(
				'id'   => $id,
				'code' => '.kuduy-wpcode { color: blue; }',
			)
		);
		$this->assertSame( 'Kuduy WPCode styles', $changed['title'], 'Fields not given stay.' );
		$this->assertSame( '.kuduy-wpcode { color: blue; }', $changed['code'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$back = $this->ok( 'kuduy-wpcode-snippet-get', array( 'id' => $id ) );
		$this->assertSame( '.kuduy-wpcode { color: red; }', $back['code'], 'Undo puts the old code back.' );
		$this->assertTrue( $back['active'] );

		$off = $this->ok( 'kuduy-wpcode-snippet-deactivate', array( 'id' => $id ) );
		$this->assertFalse( $off['active'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$this->assertTrue( $this->ok( 'kuduy-wpcode-snippet-get', array( 'id' => $id ) )['active'], 'Undo switches it on again.' );
		wp_delete_post( $id, true );
	}

	/**
	 * WC2: hard-coded credentials are masked on read and masked code is never saved back.
	 */
	public function test_credentials_are_masked(): void {
		[ , $keyed ] = $this->confirmed(
			'kuduy-wpcode-snippet-save',
			array(
				'title'     => 'Kuduy keyed script',
				'code'      => "var api_key = 'kuduy-live-0123456789abcdef';",
				'code_type' => 'js',
			)
		);
		$shown       = $this->ok( 'kuduy-wpcode-snippet-get', array( 'id' => $keyed['id'] ) )['code'];
		$this->assertStringNotContainsString( 'kuduy-live-0123456789abcdef', $shown );
		$this->assertStringContainsString( '[redacted]', $shown );
		$this->fails(
			'kuduy-wpcode-snippet-save',
			array(
				'id'   => $keyed['id'],
				'code' => $shown,
			),
			'saving masked code would replace the real value'
		);
		wp_delete_post( $keyed['id'], true );
	}

	/**
	 * WC3: PHP is guarded — refused by the normal save, and the PHP tool needs code execution.
	 */
	public function test_php_is_guarded(): void {
		$this->fails(
			'kuduy-wpcode-snippet-save',
			array(
				'title'     => 'Kuduy PHP',
				'code'      => 'add_filter( "kuduy_x", "__return_true" );',
				'code_type' => 'php',
			),
			'code_type'
		);
		$snippet = new \WPCode_Snippet(
			array(
				'title'     => 'Kuduy existing PHP',
				'code'      => 'add_filter( "kuduy_y", "__return_true" );',
				'code_type' => 'php',
			)
		);
		$id      = (int) $snippet->save();
		$this->assertGreaterThan( 0, $id );
		$this->fails(
			'kuduy-wpcode-snippet-save',
			array(
				'id'   => $id,
				'code' => 'add_filter( "kuduy_z", "__return_true" );',
			),
			'kuduy-wpcode-snippet-php-save'
		);
		$tool    = \Kuduy\Mcp\Mcp\ToolRegistry::create()->get( 'kuduy-wpcode-snippet-php-save' );
		$allowed = defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' );
		$this->assertSame( 'kuduy:code', $tool->scope() );
		$this->assertSame( $allowed, true === $tool->is_available(), 'PHP snippets exist only where code execution is allowed.' );
		wp_delete_post( $id, true );
	}

	/**
	 * WC4: site-wide header and footer code is appended, read masked, and the change undone.
	 */
	public function test_headers_footers(): void {
		$before              = $this->ok( 'kuduy-wpcode-headers-footers' );
		[ $preview, $saved ] = $this->confirmed(
			'kuduy-wpcode-headers-footers-save',
			array(
				'footer' => '<!-- kuduy footer -->',
				'header' => "<script>var token = 'kuduy-secret-0123456789abcdef';</script>",
			)
		);
		$this->assertSame( 'append', $preview['preview']['action'] );
		$this->assertStringContainsString( '<!-- kuduy footer -->', $saved['footer'] );
		$read = $this->ok( 'kuduy-wpcode-headers-footers' );
		$this->assertStringNotContainsString( 'kuduy-secret-0123456789abcdef', $read['header'], 'Credentials in header code are masked.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $saved['_snapshot_id'] ) );
		$after = $this->ok( 'kuduy-wpcode-headers-footers' );
		$this->assertSame( $before['footer'], $after['footer'], 'Undo puts the previous footer code back.' );
		$this->assertSame( $before['header'], $after['header'] );
	}

	/**
	 * WC5: who may do what — an author has no WPCode capability, and nobody without unfiltered_html reads header code.
	 */
	public function test_permissions(): void {
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-wpcode-snippets', array(), 'permission' );
		$this->fails( 'kuduy-wpcode-headers-footers', array(), 'permission' );
	}
}
