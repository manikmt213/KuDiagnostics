<?php
/**
 * Phase G3 (test matrix CS1–CS7, SC1, JR1): Code Snippets tools against the real Code Snippets 4.1.0 (job
 * "+ code snippets"; PHP snippets only in the code-execution job), the shortcode list and job retry.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CodeAndJobsTest extends TestCase {

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
		wp_set_current_user( self::$admin );
	}

	/**
	 * Skip without Code Snippets.
	 */
	private function needs_snippets(): void {
		if ( ! defined( 'CODE_SNIPPETS_VERSION' ) ) {
			$this->markTestSkipped( 'Code Snippets is not active in this run.' );
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

	public function test_css_snippet_lifecycle(): void {
		$this->needs_snippets();
		[ $preview, $saved ] = $this->confirmed(
			'kuduy-code-snippets-save',
			array(
				'name'   => 'Kuduy test styles',
				'code'   => '.kuduy-test { color: red; }',
				'scope'  => 'site-css',
				'active' => true,
				'tags'   => array( 'kuduy' ),
			)
		);
		$this->assertSame( 'create', $preview['preview']['action'] );
		$this->assertSame( 'css', $preview['preview']['type'] );
		$this->assertStringContainsString( 'does not make code safe', $preview['preview']['check']['note'] );
		$this->assertSame( 'css', $saved['type'] );
		$this->assertTrue( $saved['active'] );
		$id = $saved['id'];

		$listed = array_column( $this->ok( 'kuduy-code-snippets-list', array( 'type' => 'css' ) )['snippets'], 'name', 'id' );
		$this->assertSame( 'Kuduy test styles', $listed[ $id ] ?? null );
		$this->assertSame( '.kuduy-test { color: red; }', $this->ok( 'kuduy-code-snippets-get', array( 'id' => $id ) )['code'] );

		[ , $changed ] = $this->confirmed(
			'kuduy-code-snippets-save',
			array(
				'id'   => $id,
				'code' => '.kuduy-test { color: blue; }',
			)
		);
		$this->assertSame( 'Kuduy test styles', $changed['name'], 'Fields not given stay.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( '.kuduy-test { color: red; }', $this->ok( 'kuduy-code-snippets-get', array( 'id' => $id ) )['code'], 'Undo puts the old code back.' );

		[ , $keyed ] = $this->confirmed(
			'kuduy-code-snippets-save',
			array(
				'name'  => 'Kuduy keyed script',
				'code'  => "var api_key = 'kuduy-live-0123456789abcdef';",
				'scope' => 'site-footer-js',
			)
		);
		$shown       = $this->ok( 'kuduy-code-snippets-get', array( 'id' => $keyed['id'] ) )['code'];
		$this->assertStringNotContainsString( 'kuduy-live-0123456789abcdef', $shown, 'A hard-coded credential is masked.' );
		$this->assertStringContainsString( '[redacted]', $shown );
		$this->fails(
			'kuduy-code-snippets-save',
			array(
				'id'   => $keyed['id'],
				'code' => $shown,
			),
			'saving masked code would replace the real value'
		);

		$off = $this->ok( 'kuduy-code-snippets-deactivate', array( 'id' => $id ) );
		$this->assertFalse( $off['active'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$this->assertTrue( $this->ok( 'kuduy-code-snippets-get', array( 'id' => $id ) )['active'] );

		$trash = $this->ok( 'kuduy-code-snippets-trash', array( 'id' => $id ) );
		$this->assertArrayNotHasKey( $id, array_column( $this->ok( 'kuduy-code-snippets-list' )['snippets'], 'name', 'id' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$this->assertArrayHasKey( $id, array_column( $this->ok( 'kuduy-code-snippets-list' )['snippets'], 'name', 'id' ) );
	}

	public function test_php_is_guarded(): void {
		$this->needs_snippets();
		$this->fails(
			'kuduy-code-snippets-save',
			array(
				'name'  => 'Sneaky',
				'code'  => 'echo 1;',
				'scope' => 'global',
			),
			'The HTML/CSS/JS tool refuses PHP scopes in its schema'
		);
		$php = \Code_Snippets\save_snippet(
			new \Code_Snippets\Model\Snippet(
				array(
					'name'  => 'Kuduy existing PHP',
					'code'  => 'add_filter( "kuduy_test_filter", "__return_true" );',
					'scope' => 'global',
				)
			)
		);
		$this->assertNotNull( $php );
		$this->fails(
			'kuduy-code-snippets-save',
			array(
				'id'     => (int) $php->id,
				'active' => true,
			),
			'An existing PHP snippet cannot be switched on through the HTML/CSS/JS tool'
		);
		$this->assertFalse( (bool) \Code_Snippets\get_snippet( (int) $php->id )->active );

		$check = $this->ok( 'kuduy-code-snippets-get', array( 'id' => (int) $php->id ) )['check'];
		$this->assertSame( 'ok', $check['syntax'] );

		$tool = ToolRegistry::create()->get( 'kuduy-code-snippets-php-save' );
		$this->assertSame( 'kuduy:code', $tool->scope() );
		$allowed = defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' );
		$this->assertSame( $allowed, true === $tool->is_available(), 'PHP snippets exist only where code execution is allowed.' );
	}

	public function test_php_snippet_with_code_execution(): void {
		$this->needs_snippets();
		if ( ! defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) {
			$this->markTestSkipped( 'Code execution is not allowed in this run.' );
		}
		$bad = $this->call(
			'kuduy-code-snippets-php-save',
			array(
				'name'   => 'Broken',
				'code'   => 'function kuduy_broken( { }',
				'scope'  => 'global',
				'active' => true,
			)
		);
		$this->assertTrue( $bad['isError'], 'Code with a syntax error is not saved active.' );

		[ $preview, $saved ] = $this->confirmed(
			'kuduy-code-snippets-php-save',
			array(
				'name'   => 'Kuduy PHP test',
				'code'   => "add_filter( 'kuduy_php_snippet_test', '__return_true' );\n\$x = base64_decode( 'eA==' );",
				'scope'  => 'admin',
				'active' => false,
			)
		);
		$this->assertSame( 'ok', $preview['preview']['check']['syntax'] );
		$this->assertContains( 'base64_decode(): often hides code', $preview['preview']['check']['risky'] );
		$this->assertFalse( $saved['active'] );
	}

	public function test_shortcodes(): void {
		add_shortcode( 'kuduy_test_sc', static fn() => 'x' );
		$all = array_column( $this->ok( 'kuduy-shortcodes' )['shortcodes'], null, 'tag' );
		$this->assertSame( 'core', $all['gallery']['source'] );
		$this->assertSame( 'plugin', $all['kuduy_test_sc']['source'] );
		$this->assertSame( 'kuduy-mcp', $all['kuduy_test_sc']['provider'] );
		// Other active plugins may have gallery shortcodes too (Qi Addons: qi_addons_for_elementor_cards_gallery).
		$found = array_column( $this->ok( 'kuduy-shortcodes', array( 'search' => 'gallery' ) )['shortcodes'], 'tag' );
		$this->assertContains( 'gallery', $found );
		$this->assertNotContains( 'kuduy_test_sc', $found, 'The search filters.' );
		foreach ( $found as $tag ) {
			$this->assertStringContainsString( 'gallery', $tag );
		}
		remove_shortcode( 'kuduy_test_sc' );
	}

	/**
	 * An image attachment with a real file, owned by a user (as in JobsTest).
	 *
	 * @param int $owner User id.
	 */
	private function image( int $owner ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this PHP build.' );
		}
		$gd = imagecreatetruecolor( 40, 30 );
		ob_start();
		imagepng( $gd );
		$up = wp_upload_bits( 'kuduy-retry-' . wp_generate_password( 6, false ) . '.png', null, (string) ob_get_clean() );
		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Kuduy retry image',
				'post_status'    => 'inherit',
				'post_author'    => $owner,
			),
			$up['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		return $id;
	}

	public function test_job_retry(): void {
		$own    = $this->image( self::$author );
		$admins = $this->image( self::$admin );
		wp_set_current_user( self::$author );
		$job = $this->ok(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-media-update',
				'items' => array(
					array(
						'id'  => $own,
						'alt' => 'Mine',
					),
					array(
						'id'  => $admins,
						'alt' => 'Not mine',
					),
				),
			)
		);
		$this->fails( 'kuduy-job-retry', array( 'id' => $job['id'] ), 'A queued job is not retried' );
		wp_set_current_user( 0 );
		Jobs::run( $job['id'] );
		wp_set_current_user( self::$author );
		$this->assertSame( 1, $this->ok( 'kuduy-job-status', array( 'id' => $job['id'] ) )['failed'] );

		wp_set_current_user( self::$admin );
		$retry = $this->ok( 'kuduy-job-retry', array( 'id' => $job['id'] ) );
		$this->assertSame( $job['id'], $retry['retried_from'] );
		$this->assertSame( 1, $retry['total'], 'Only the failed item is retried.' );
		wp_set_current_user( 0 );
		Jobs::run( $retry['id'] );
		wp_set_current_user( self::$admin );
		$status = $this->ok( 'kuduy-job-status', array( 'id' => $retry['id'] ) );
		$this->assertSame( 0, $status['failed'], 'The site manager may change the image.' );
		$this->assertSame( 'Not mine', get_post_meta( $admins, '_wp_attachment_image_alt', true ) );
		$this->fails( 'kuduy-job-retry', array( 'id' => $retry['id'] ), 'Nothing left to retry' );
	}
}
