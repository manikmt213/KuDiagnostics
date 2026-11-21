<?php
/**
 * Phase J (test matrix MJ1–MJ7): read-only mode over every tool (D80), snapshot comparison (D81), abilities hub (D82).
 * The hub's abilities are registered by this test: the abilities registry is rebuilt with them and rebuilt again
 * without them afterwards, so other tests see the site's own abilities only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use Kuduy\Mcp\Policy\ReadOnlyMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ModesTest extends TestCase {

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
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		self::rebuild_abilities();
		// MJ2 calls every write tool once; the per-user rate limit (120 a minute) is not under test here.
		add_filter( 'kuduy_mcp_rate_limit', array( self::class, 'no_rate_limit' ) );
	}

	/**
	 * Rate limit lifted for this class.
	 */
	public static function no_rate_limit(): int {
		return 100000;
	}

	public static function tearDownAfterClass(): void {
		remove_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		remove_filter( 'kuduy_mcp_rate_limit', array( self::class, 'no_rate_limit' ) );
		self::rebuild_abilities();
		delete_option( ReadOnlyMode::OPTION );
		delete_option( 'kuduy_test_note' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		delete_option( ReadOnlyMode::OPTION );
	}

	/**
	 * Empty the abilities registry so the next lookup fires wp_abilities_api_init again.
	 */
	private static function rebuild_abilities(): void {
		$property = new \ReflectionProperty( \WP_Abilities_Registry::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		\WP_Abilities_Registry::get_instance();
	}

	/**
	 * Test abilities: public read and write, one not public, one that runs code, one of a covered plugin.
	 */
	public static function register_abilities(): void {
		$public = static fn( bool $reads ): array => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array( 'readonly' => $reads ),
		);
		wp_register_ability(
			'kuduy-test/get-note',
			array(
				'label'               => 'Get note',
				'description'         => 'Reads the test note.',
				'category'            => 'site',
				'output_schema'       => array( 'type' => 'string' ),
				'execute_callback'    => static fn(): string => (string) get_option( 'kuduy_test_note', '' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'meta'                => $public( true ),
			)
		);
		wp_register_ability(
			'kuduy-test/set-note',
			array(
				'label'               => 'Set note',
				'description'         => 'Sets the test note.',
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'note' => array(
							'type'      => 'string',
							'maxLength' => 50,
						),
					),
					'required'             => array( 'note' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'boolean' ),
				'execute_callback'    => static fn( array $input ): bool => update_option( 'kuduy_test_note', $input['note'] ) || true,
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'meta'                => $public( false ),
			)
		);
		wp_register_ability(
			'kuduy-test/private-thing',
			array(
				'label'               => 'Private',
				'description'         => 'Not public for MCP.',
				'category'            => 'site',
				'execute_callback'    => static fn(): bool => true,
				'permission_callback' => '__return_true',
				'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
			)
		);
		wp_register_ability(
			'kuduy-test/run-php',
			array(
				'label'               => 'Run PHP',
				'description'         => 'Would run code.',
				'category'            => 'site',
				'execute_callback'    => static fn(): bool => update_option( 'kuduy_test_note', 'php ran' ),
				'permission_callback' => '__return_true',
				'meta'                => $public( false ),
			)
		);
		wp_register_ability(
			'meta-box/kuduy-test-get',
			array(
				'label'               => 'Covered',
				'description'         => 'A plugin KuDiagnostics has its own tools for.',
				'category'            => 'site',
				'execute_callback'    => static fn(): bool => true,
				'permission_callback' => '__return_true',
				'meta'                => $public( true ),
			)
		);
	}

	/**
	 * Tool names from tools/list through the real endpoint.
	 *
	 * @return list<string>
	 */
	private function tool_names(): array {
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
		$request->set_header( 'Mcp-Method', 'tools/list' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ) ),
				)
			)
		);
		$data = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		return array_column( (array) ( $data['result']['tools'] ?? array() ), 'name' );
	}

	/**
	 * Available tools, split by readOnlyHint.
	 *
	 * @return array{0: list<string>, 1: list<string>} Read tools, other tools.
	 */
	private static function tools_by_kind(): array {
		$read  = array();
		$write = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			if ( true !== $tool->is_available() ) {
				continue;
			}
			if ( true === ( $tool->annotations()['readOnlyHint'] ?? false ) ) {
				$read[] = $tool->name();
			} else {
				$write[] = $tool->name();
			}
		}
		return array( $read, $write );
	}

	public function test_mj1_read_only_mode_lists_only_read_tools(): void {
		[ $read, $write ] = self::tools_by_kind();
		$this->assertNotEmpty( $write );
		$this->assertNotEmpty( array_intersect( $write, $this->tool_names() ), 'write tools are listed normally' );

		update_option( ReadOnlyMode::OPTION, '1' );
		$listed = $this->tool_names();
		$this->assertSame( array(), array_values( array_intersect( $write, $listed ) ), 'no write tool is listed in read-only mode' );
		$this->assertContains( 'kuduy-site-info', $listed );
		$this->assertContains( 'kuduy-post-list', $listed );
		$this->assertTrue( $this->ok( 'kuduy-site-info' )['read_only'] );
	}

	public function test_mj2_every_write_tool_is_refused_in_read_only_mode(): void {
		[ , $write ] = self::tools_by_kind();
		$post        = wp_insert_post(
			array(
				'post_title'  => 'Read-only target',
				'post_status' => 'draft',
			)
		);
		update_option( ReadOnlyMode::OPTION, '1' );
		$ran = array();
		foreach ( $write as $name ) {
			// An id of a real post: a tool that ignored the switch would have something to change.
			$result = $this->call( $name, array( 'id' => $post ) );
			if ( empty( $result['isError'] ) || ! str_contains( (string) ( $result['content'][0]['text'] ?? '' ), 'read-only mode' ) ) {
				$ran[] = $name;
			}
		}
		$this->assertSame( array(), $ran, 'These write tools were not refused in read-only mode.' );
		$this->assertSame( 'Read-only target', get_post( $post )->post_title );
		$this->assertSame( 'draft', get_post_status( $post ), 'not trashed, deleted or published' );
		$this->assertSame( $post, $this->ok( 'kuduy-post-get', array( 'id' => $post ) )['id'], 'read tools still work' );

		delete_option( ReadOnlyMode::OPTION );
		$this->assertFalse(
			$this->call(
				'kuduy-post-update',
				array(
					'id'    => $post,
					'title' => 'Writable again',
				)
			)['isError']
		);
		$this->assertSame( 'Writable again', get_post( $post )->post_title );
	}

	public function test_mj3_jobs_stop_when_the_site_turns_read_only(): void {
		$post = wp_insert_post(
			array(
				'post_title'  => 'Job target',
				'post_status' => 'draft',
			)
		);
		$job  = Jobs::create(
			'kuduy-post-update',
			array(
				array(
					'id'    => $post,
					'title' => 'Changed by a job',
				),
			),
			self::$admin,
			null,
			null
		);
		update_option( ReadOnlyMode::OPTION, '1' );
		Jobs::run( $job ); // The hook WP-Cron fires.
		$row = Jobs::get( $job );
		$this->assertSame( 'stopped', $row['status'] );
		$this->assertStringContainsString( 'read-only', $row['error'] );
		$this->assertSame( 'Job target', get_post( $post )->post_title );
	}

	public function test_mj5_read_tools_are_never_destructive_or_confirmed(): void {
		$wrong = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			$hints = $tool->annotations();
			// The executor snapshots tools that are Snapshottable and destructiveHint, and confirms Confirmable ones.
			if ( true === ( $hints['readOnlyHint'] ?? false ) && ( true === ( $hints['destructiveHint'] ?? false ) || $tool instanceof Confirmable ) ) {
				$wrong[] = $tool->name();
			}
		}
		$this->assertSame( array(), $wrong, 'Tools marked readOnlyHint that change things (they would stay open in read-only mode).' );
	}

	public function test_mj6_snapshot_diff_shows_what_changed(): void {
		$post    = wp_insert_post(
			array(
				'post_title'   => 'Before',
				'post_content' => 'Body',
				'post_status'  => 'draft',
			)
		);
		$updated = $this->ok(
			'kuduy-post-update',
			array(
				'id'    => $post,
				'title' => 'After',
			)
		);
		$snap    = (int) $updated['_snapshot_id'];
		$diff    = $this->ok( 'kuduy-snapshot-diff', array( 'snapshot_id' => $snap ) );
		$this->assertTrue( $diff['current_available'] );
		$this->assertFalse( $diff['identical'] );
		$this->assertFalse( $diff['restored'] );
		$rows = array_column( $diff['changes'], null, 'path' );
		$this->assertSame(
			array(
				'path'   => 'post_title',
				'change' => 'changed',
				'before' => 'Before',
				'after'  => 'After',
			),
			$rows['post_title'] ?? null
		);
		$this->assertArrayNotHasKey( 'post_content', $rows, 'unchanged fields are not reported' );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-snapshot-diff', array( 'snapshot_id' => $snap ), 'another user\'s snapshot' );
		wp_set_current_user( self::$admin );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snap ) );
		$again = $this->ok( 'kuduy-snapshot-diff', array( 'snapshot_id' => $snap ) );
		$this->assertTrue( $again['restored'] );
		$this->assertTrue( $again['identical'], 'after undo the object matches the snapshot' );
		$this->fails( 'kuduy-snapshot-diff', array( 'snapshot_id' => 999999 ), 'unknown snapshot' );
	}

	public function test_mj7_abilities_hub(): void {
		$list  = $this->ok( 'kuduy-abilities' );
		$rows  = array_column( $list['abilities'], null, 'name' );
		$names = array_keys( $rows );
		$this->assertContains( 'kuduy-test/get-note', $names );
		$this->assertContains( 'kuduy-test/set-note', $names );
		$this->assertNotContains( 'kuduy-test/private-thing', $names, 'only abilities marked public for MCP' );
		$this->assertNotContains( 'meta-box/kuduy-test-get', $names, 'covered plugins are left to their own tools' );
		$this->assertContains( 'Meta Box', array_column( $list['covered'], 'product' ) );
		$this->assertSame( 'kuduy-ability-read', $rows['kuduy-test/get-note']['run_with'] );
		$this->assertSame( 'kuduy-ability-run', $rows['kuduy-test/set-note']['run_with'] );
		$this->assertSame( 'not run here', $rows['kuduy-test/run-php']['run_with'] );
		$one = $this->ok( 'kuduy-abilities', array( 'name' => 'kuduy-test/set-note' ) )['ability'];
		$this->assertSame( array( 'note' ), $one['input_schema']['required'] );

		update_option( 'kuduy_test_note', 'hello' );
		$this->assertSame( 'hello', $this->ok( 'kuduy-ability-read', array( 'name' => 'kuduy-test/get-note' ) )['result'] );
		$this->fails(
			'kuduy-ability-read',
			array(
				'name'  => 'kuduy-test/set-note',
				'input' => array( 'note' => 'x' ),
			),
			'read refuses a write ability'
		);
		$this->fails( 'kuduy-ability-read', array( 'name' => 'kuduy-test/private-thing' ), 'not public' );
		$this->fails( 'kuduy-ability-read', array( 'name' => 'meta-box/kuduy-test-get' ), 'covered' );
		$this->fails( 'kuduy-ability-run', array( 'name' => 'kuduy-test/run-php' ), 'code abilities never run' );
		$this->assertSame( 'hello', get_option( 'kuduy_test_note' ) );

		// Write: preview and token first, then the ability runs once.
		$args    = array(
			'name'  => 'kuduy-test/set-note',
			'input' => array( 'note' => 'changed' ),
		);
		$preview = $this->ok( 'kuduy-ability-run', $args );
		$this->assertTrue( $preview['confirmation_required'] );
		$this->assertSame( 'hello', get_option( 'kuduy_test_note' ), 'the preview changes nothing' );
		$this->fails(
			'kuduy-ability-run',
			array(
				'name'          => 'kuduy-test/set-note',
				'input'         => array( 'note' => 'other' ),
				'confirm_token' => $preview['confirm_token'],
			),
			'token bound to the arguments'
		);
		$preview = $this->ok( 'kuduy-ability-run', $args );
		$done    = $this->ok( 'kuduy-ability-run', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		$this->assertSame( 'kuduy-test/set-note', $done['ability'] );
		$this->assertSame( 'changed', get_option( 'kuduy_test_note' ) );
		$this->fails( 'kuduy-ability-run', $args + array( 'confirm_token' => $preview['confirm_token'] ), 'single use' );
		$this->fails(
			'kuduy-ability-run',
			array(
				'name'  => 'kuduy-test/set-note',
				'input' => array( 'note' => str_repeat( 'x', 60 ) ),
			),
			'the ability validates its own input'
		);

		// The ability's own permission callback decides: an author may read, not write.
		wp_set_current_user( self::$author );
		$this->assertSame( 'changed', $this->ok( 'kuduy-ability-read', array( 'name' => 'kuduy-test/get-note' ) )['result'] );
		$this->assertStringContainsString( 'does not allow', $this->fails( 'kuduy-ability-run', $args, 'manage_options only' ) );
		wp_set_current_user( self::$admin );

		update_option( ReadOnlyMode::OPTION, '1' );
		$this->assertStringContainsString( 'read-only mode', $this->fails( 'kuduy-ability-run', $args, 'blocked in read-only mode' ) );
		$this->assertSame( 'changed', $this->ok( 'kuduy-ability-read', array( 'name' => 'kuduy-test/get-note' ) )['result'], 'reads still work' );
	}

	/**
	 * Last: the constant decides over the option (defining it lasts for the rest of the run, so it is set to false).
	 */
	public function test_mj4_the_constant_wins_over_the_option(): void {
		update_option( ReadOnlyMode::OPTION, '1' );
		$this->assertTrue( ReadOnlyMode::is_on() );
		if ( defined( ReadOnlyMode::CONSTANT ) ) {
			$this->markTestSkipped( 'KUDUY_MCP_READ_ONLY is already defined.' );
		}
		define( 'KUDUY_MCP_READ_ONLY', false );
		$this->assertTrue( ReadOnlyMode::forced() );
		$this->assertFalse( ReadOnlyMode::is_on() );
		$this->assertFalse( $this->ok( 'kuduy-site-info' )['read_only'] );
		$this->assertContains( 'kuduy-post-update', $this->tool_names() );
	}
}
