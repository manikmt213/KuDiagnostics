<?php
/**
 * Elementor Pro popups (test matrix EP1–EP4): triggers and timing read and changed through the popup document's own
 * display-settings methods, values checked against its controls, undo. Runs against a stand-in for Pro's popup
 * document (KUDUY_FAKES=elementor-popup) or Elementor Pro itself; skips otherwise.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorPopupTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	/**
	 * Elementor's cached "front-end view" flag before these tests.
	 *
	 * @var mixed
	 */
	private static $was_frontend;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) || ! \Elementor\Plugin::$instance->documents->get_document_type( 'popup', false ) ) {
			self::markTestSkipped( 'No Elementor popup document type in this run (KUDUY_FAKES=elementor-popup or Elementor Pro).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		// MCP calls are REST requests, where Elementor keeps every control setting; these tests call the tools directly,
		// where Elementor would treat the request as a front-end view and drop options and labels from new stacks.
		$frontend = new \ReflectionProperty( \Elementor\Core\Frontend\Performance::class, 'is_frontend' );
		$frontend->setAccessible( true );
		self::$was_frontend = $frontend->getValue();
		$frontend->setValue( null, false );
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	public static function tearDownAfterClass(): void {
		if ( class_exists( \Elementor\Core\Frontend\Performance::class ) ) {
			$frontend = new \ReflectionProperty( \Elementor\Core\Frontend\Performance::class, 'is_frontend' );
			$frontend->setAccessible( true );
			$frontend->setValue( null, self::$was_frontend );
		}
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A draft popup made through Elementor's documents manager.
	 */
	private function popup(): int {
		$doc = \Elementor\Plugin::$instance->documents->create(
			'popup',
			array(
				'post_title'  => 'Kuduy popup - one',
				'post_status' => 'draft',
			)
		);
		$this->assertIsObject( $doc );
		return (int) $doc->get_main_id();
	}

	/**
	 * Stored display settings (read again on every call).
	 *
	 * @phpstan-impure
	 * @param int $id Popup id.
	 * @return mixed
	 */
	private function stored( int $id ) {
		return get_post_meta( $id, '_elementor_popup_display_settings', true );
	}

	public function test_ep1_read_settings_and_their_definitions(): void {
		$id   = $this->popup();
		$read = $this->ok( 'kuduy-elementor-popup-settings', array( 'id' => $id ) );
		$this->assertSame( 'Kuduy popup - one', $read['title'], 'Plain text title.' );
		$this->assertSame( array(), (array) $read['triggers'] );
		$json = (string) $this->call( 'kuduy-elementor-popup-settings', array( 'id' => $id ) )['content'][0]['text'];
		$this->assertStringContainsString( '"triggers":{}', $json, 'Empty settings are a JSON object, as the output schema says.' );
		$triggers = array_column( $read['controls']['triggers'], null, 'name' );
		$this->assertSame( 'switcher', $triggers['page_load']['type'] );
		$this->assertSame( 'number', $triggers['page_load_delay']['type'] );
		$this->assertSame( 100, $triggers['scrolling_offset']['max'] );
		$this->assertSame( array( 'scrolling' => 'yes' ), $triggers['scrolling_offset']['only_when'] );
		$this->assertArrayNotHasKey( 'page_load_heading', $triggers, 'Labels are not settings.' );
		$timing = array_column( $read['controls']['timing'], null, 'name' );
		$this->assertTrue( $timing['devices_devices']['multiple'] );
		$this->assertArrayNotHasKey( 'schedule_server_datetime', $timing, 'Hidden values are Elementor\'s own.' );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy not a popup',
				'post_status' => 'draft',
			)
		);
		$this->fails( 'kuduy-elementor-popup-settings', array( 'id' => $page ), 'a page is not a popup' );
	}

	public function test_ep2_change_triggers_and_timing_with_undo(): void {
		$id  = $this->popup();
		$set = $this->ok(
			'kuduy-elementor-popup-settings-update',
			array(
				'id'       => $id,
				'triggers' => array(
					'page_load'       => 'yes',
					'page_load_delay' => 2.5,
				),
				'timing'   => array(
					'devices'         => true,
					'devices_devices' => array( 'desktop' ),
					'times'           => 'yes',
					'times_period'    => '',
				),
			)
		);
		$this->assertContains( 'set triggers.page_load', $set['changed'] );
		$this->assertSame(
			array(
				'triggers' => array(
					'page_load'       => 'yes',
					'page_load_delay' => 2.5,
				),
				'timing'   => array(
					'devices'         => 'yes',
					'devices_devices' => array( 'desktop' ),
					'times'           => 'yes',
					'times_period'    => '',
				),
			),
			$this->stored( $id )
		);

		$more  = $this->ok(
			'kuduy-elementor-popup-settings-update',
			array(
				'id'       => $id,
				'triggers' => array( 'exit_intent' => 'yes' ),
				'unset'    => array( 'triggers' => array( 'page_load_delay' ) ),
			)
		);
		$after = $this->stored( $id );
		$this->assertSame( 'yes', $after['triggers']['exit_intent'] );
		$this->assertArrayNotHasKey( 'page_load_delay', $after['triggers'] );
		$this->assertSame( array( 'desktop' ), $after['timing']['devices_devices'], 'Timing kept.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $more['_snapshot_id'] ) );
		$this->assertSame( 2.5, $this->stored( $id )['triggers']['page_load_delay'], 'Undo puts the previous settings back.' );
		$this->assertArrayNotHasKey( 'exit_intent', $this->stored( $id )['triggers'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertSame(
			array(
				'triggers' => array(),
				'timing'   => array(),
			),
			$this->stored( $id ),
			'Undo of the first change: nothing set.'
		);
	}

	public function test_ep3_wrong_values_are_refused_and_nothing_is_saved(): void {
		$id  = $this->popup();
		$bad = array(
			array( 'triggers' => array( 'no_such' => 1 ) ),
			array( 'triggers' => array( 'scrolling_offset' => 150 ) ),
			array( 'triggers' => array( 'scrolling_offset' => 'many' ) ),
			array( 'triggers' => array( 'scrolling_direction' => 'left' ) ),
			array( 'triggers' => array( 'page_load' => 'maybe' ) ),
			array( 'triggers' => array( 'page_load_heading' => 'x' ) ),
			array( 'timing' => array( 'devices_devices' => array( 'tv' ) ) ),
			array( 'timing' => array( 'devices_devices' => 'desktop' ) ),
			array( 'timing' => array( 'schedule_server_datetime' => '2030-01-01 00:00' ) ),
			array( 'timing' => array( 'schedule_start_date' => 'next week' ) ),
			array( 'unset' => array( 'timing' => array( 'nope' ) ) ),
			array(),
		);
		foreach ( $bad as $args ) {
			$text = $this->fails( 'kuduy-elementor-popup-settings-update', array( 'id' => $id ) + $args, (string) wp_json_encode( $args ) );
			$this->assertStringNotContainsString( '&quot;', $text );
		}
		// One bad value among good ones: nothing is saved.
		$this->fails(
			'kuduy-elementor-popup-settings-update',
			array(
				'id'       => $id,
				'triggers' => array(
					'page_load'        => 'yes',
					'scrolling_offset' => 0,
				),
			),
			'below the minimum'
		);
		$this->assertEmpty( $this->stored( $id ) );
	}

	public function test_ep4_permissions(): void {
		$id = $this->popup();
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-elementor-popup-settings-update',
			array(
				'id'       => $id,
				'triggers' => array( 'exit_intent' => 'yes' ),
			),
			'an author may not edit someone else\'s popup'
		);
		$this->assertEmpty( $this->stored( $id ) );
	}
}
