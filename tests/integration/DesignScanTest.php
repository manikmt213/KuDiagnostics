<?php
/**
 * Phase I (test matrix DS2–DS3): design scan (D71).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class DesignScanTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	/**
	 * DS2: tokens from theme.json, the Elementor Kit (when active) and the Customizer, each with its source; a summary.
	 */
	public function test_scan(): void {
		wp_set_current_user( self::make_user( 'editor' ) );
		set_theme_mod( 'background_color', 'f5f5f0' );
		$scan = $this->ok( 'kuduy-design-scan' );
		$this->assertSame( (string) get_bloginfo( 'name' ), $scan['site']['name'] );
		$this->assertNotEmpty( $scan['colors'] );
		foreach ( array_merge( $scan['colors'], $scan['fonts'], $scan['font_sizes'], $scan['spacing'] ) as $token ) {
			$this->assertNotSame( '', (string) $token['source'], 'Every token names its source.' );
		}
		$by_name = array_column( $scan['colors'], 'value', 'name' );
		$this->assertSame( '#f5f5f0', $by_name['background (Customizer)'] ?? null );
		if ( function_exists( 'wp_get_global_settings' ) && ! empty( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) ) ) {
			$this->assertNotEmpty( array_filter( $scan['colors'], static fn( array $c ): bool => str_starts_with( (string) $c['source'], 'theme.json' ) ), 'The theme palette is read.' );
		}
		if ( did_action( 'elementor/loaded' ) && (int) \Elementor\Plugin::$instance->kits_manager->get_active_id() > 0 ) {
			$this->assertContains( 'Elementor Kit', $scan['sources'] );
			$this->assertNotEmpty( array_filter( $scan['colors'], static fn( array $c ): bool => str_starts_with( (string) $c['source'], 'Elementor Kit' ) ) );
		}
		$this->assertStringContainsString( (string) get_bloginfo( 'name' ), $scan['summary'] );
		remove_theme_mod( 'background_color' );
	}

	/**
	 * DS3: the summary can be kept as a design memory; subscribers cannot scan.
	 */
	public function test_summary_to_memory_and_access(): void {
		wp_set_current_user( self::make_user( 'editor' ) );
		$scan  = $this->ok( 'kuduy-design-scan' );
		$saved = $this->ok(
			'kuduy-memory-save',
			array(
				'kind'  => 'design',
				'title' => 'Kuduy design tokens',
				'body'  => $scan['summary'],
			)
		)['memory'];
		$this->assertSame( 'design', $saved['kind'] );
		wp_set_current_user( self::make_user( 'subscriber' ) );
		$this->fails( 'kuduy-design-scan', array(), 'subscribers cannot scan' );
	}
}
