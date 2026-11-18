<?php
/**
 * Tool listing (test matrix F9): for an administrator, every tool that is available on this site passes its own
 * capability check without arguments, so tools/list shows it. A tool whose capability needs an object id must fall
 * back to a general capability when no id is given (found live: kuduy-kirki-edit was hidden from the list).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\Executor;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ToolListingTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	public function test_f9_every_available_tool_is_listed_for_an_administrator(): void {
		wp_set_current_user( self::$admin );
		$hidden = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			if ( true === $tool->is_available() && ! Executor::allowed( $tool, array() ) ) {
				$hidden[] = $tool->name();
			}
		}
		$this->assertSame( array(), $hidden, 'These tools are hidden from tools/list for an administrator.' );
	}
}
