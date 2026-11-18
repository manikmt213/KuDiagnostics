<?php
/**
 * Dynamic Shortcodes (test matrix DS1, phase L3): types with the privileged ones marked, a strict check of text through
 * the plugin's own lint (valid, unclosed, missing colon, unknown type, privileged type used), Power Shortcodes read
 * with secrets masked, editors may check but not read Power Shortcodes. Runs against the stand-in
 * (KUDUY_FAKES=dynamic-shortcodes), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class DynamicShortcodesTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( '\DynamicShortcodes\Plugin' ) ) {
			self::markTestSkipped( 'Dynamic Shortcodes (or its stand-in) is not loaded (KUDUY_FAKES=dynamic-shortcodes).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	public function test_ds1_types_lint_power(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$types = array_column( $this->ok( 'kuduy-dynamic-shortcodes-types' )['types'], 'privileged', 'type' );
		$this->assertFalse( $types['post'] );
		$this->assertTrue( $types['call'], 'call needs full privileges.' );
		$this->assertTrue( $types['option'] );

		$ok = $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => 'Hi {user:display-name}, see {post:title}' ) );
		$this->assertTrue( $ok['valid'] );
		$this->assertSame( array( 'user', 'post' ), $ok['types_used'] );
		$this->assertSame( array(), $ok['privileged_used'] );

		$bad = $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => "line one\n{post:title" ) );
		$this->assertFalse( $bad['valid'] );
		$this->assertStringContainsString( 'line 2', $bad['errors'][0] );

		$colon = $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => '{post title}' ) );
		$this->assertFalse( $colon['valid'] );

		$unknown = $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => '{nope:x}' ) );
		$this->assertFalse( $unknown['valid'] );
		$this->assertStringContainsString( 'Unknown type "nope"', implode( ' ', $unknown['errors'] ) );

		$risky = $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => '{call:phpinfo}' ) );
		$this->assertSame( array( 'call' ), $risky['privileged_used'] );

		update_option(
			'dsh_power_shortcodes_json',
			wp_json_encode(
				array(
					array(
						'name'        => 'greeting',
						'description' => 'Says hi',
						'code'        => 'Hi {user:display-name} api_key=sk_live_abcdefghijklmnop1234',
					),
				)
			)
		);
		$power = $this->ok( 'kuduy-dynamic-shortcodes-power' )['power_shortcodes'];
		$this->assertSame( 'greeting', $power[0]['name'] );
		$this->assertStringNotContainsString( 'sk_live_abcdefghijklmnop1234', $power[0]['code'], 'Secrets are masked.' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->assertTrue( $this->ok( 'kuduy-dynamic-shortcodes-lint', array( 'text' => '{post:title}' ) )['valid'], 'Editors may check text.' );
		$this->fails( 'kuduy-dynamic-shortcodes-power', array(), 'Power Shortcodes need a site manager' );
	}
}
