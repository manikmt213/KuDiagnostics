<?php
/**
 * Phase O (test matrix AO1-AO7): administration tools (D74-D78).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AdminToolsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	/**
	 * Mails WordPress tried to send.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $mails = array();

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		add_filter( 'pre_wp_mail', array( self::class, 'capture_mail' ), 10, 2 );
	}

	public static function tearDownAfterClass(): void {
		remove_filter( 'pre_wp_mail', array( self::class, 'capture_mail' ), 10 );
	}

	/**
	 * Keep the mail instead of sending it.
	 *
	 * @param null|bool            $short Short-circuit.
	 * @param array<string, mixed> $atts  wp_mail() arguments.
	 */
	public static function capture_mail( $short, array $atts ): bool {
		self::$mails[] = $atts;
		return true;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Run a two-step tool: preview, then confirm with the token.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function confirmed( string $tool, array $args ): array {
		$preview = $this->ok( $tool, $args );
		$this->assertTrue( $preview['confirmation_required'], $tool . ' asks first' );
		return array( $preview, $this->ok( $tool, $args + array( 'confirm_token' => $preview['confirm_token'] ) ) );
	}

	/**
	 * A ZIP of a small plugin in the media library.
	 *
	 * @param string $folder Top folder in the ZIP.
	 * @return int Attachment id.
	 */
	private static function plugin_zip( string $folder ): int {
		$uploads = wp_upload_dir();
		$path    = $uploads['path'] . '/' . $folder . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip     = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( $folder . '/' . $folder . '.php', "<?php\n/**\n * Plugin Name: Kuduy Test Upload\n * Version: 1.2.3\n */\n" );
		$zip->addFromString( $folder . '/uninstall.php', "<?php\ndelete_option( 'kuduy_test_upload' );\n" );
		$zip->close();
		return (int) wp_insert_attachment(
			array(
				'post_title'     => $folder,
				'post_mime_type' => 'application/zip',
				'post_status'    => 'inherit',
			),
			$path
		);
	}

	/**
	 * AO1: upload a plugin ZIP from the media library (installed, not active), inspect it, delete it.
	 */
	public function test_ao1_plugin_upload_inspect_delete(): void {
		$folder             = 'kuduy-test-upload';
		[ $preview, $done ] = $this->confirmed( 'kuduy-plugin-upload', array( 'attachment_id' => self::plugin_zip( $folder ) ) );
		$this->assertSame( $folder, $preview['preview']['folder'] );
		$this->assertSame( $folder . '/' . $folder . '.php', $done['file'] );
		$this->assertSame( '1.2.3', $done['version'] );
		$this->assertFalse( is_plugin_active( $done['file'] ), 'Uploaded, not activated.' );

		$this->fails( 'kuduy-plugin-upload', array( 'attachment_id' => self::plugin_zip( $folder ) ), 'the folder already exists' );

		$info = $this->ok( 'kuduy-plugin-inspect', array( 'file' => $done['file'] ) );
		$this->assertSame( 'Kuduy Test Upload', $info['name'] );
		$this->assertContains( 'kuduy-plugin-upload', array_column( $info['history'], 'tool' ), 'The upload is in the plugin\'s history (text object ids are stored as text).' );

		[ $before, $deleted ] = $this->confirmed( 'kuduy-plugin-delete', array( 'file' => $done['file'] ) );
		$this->assertStringContainsString( 'uninstall routine', $before['preview']['uninstall'] );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/' . $folder );
	}

	/**
	 * AO2: refusals — KuDiagnostics itself, an active plugin, a ZIP that leaves its folder, editors.
	 */
	public function test_ao2_plugin_refusals(): void {
		$this->fails( 'kuduy-plugin-delete', array( 'file' => 'kuduy-mcp/kuduy-mcp.php' ), 'never deletes itself' );
		$this->fails( 'kuduy-plugin-upload', array( 'attachment_id' => self::plugin_zip( 'kuduy-mcp' ) ), 'never installs over itself' );
		$active = (string) array_values( array_diff( (array) get_option( 'active_plugins' ), array( 'kuduy-mcp/kuduy-mcp.php' ) ) )[0];
		if ( '' !== $active ) {
			$this->fails( 'kuduy-plugin-delete', array( 'file' => $active ), 'an active plugin is not deleted' );
		}
		$uploads = wp_upload_dir();
		$path    = $uploads['path'] . '/evil-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip     = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( '../evil.php', '<?php' );
		$zip->close();
		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'application/zip',
				'post_status'    => 'inherit',
			),
			$path
		);
		$this->fails( 'kuduy-plugin-upload', array( 'attachment_id' => $id ), 'a path that leaves the folder' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/evil.php' );
		$this->fails( 'kuduy-plugin-inspect', array( 'file' => 'no-such/plugin.php' ), 'unknown plugin' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-plugin-inspect', array( 'file' => 'kuduy-mcp/kuduy-mcp.php' ), 'editors cannot manage plugins' );
	}

	/**
	 * AO3: permanent delete only from the trash, with undo.
	 */
	public function test_ao3_post_delete_from_trash_with_undo(): void {
		$id = (int) wp_insert_post(
			array(
				'post_title'   => 'Kuduy delete me',
				'post_content' => 'Body',
				'post_status'  => 'publish',
			)
		);
		add_post_meta( $id, 'kuduy_note', 'kept' );
		$this->fails( 'kuduy-post-delete', array( 'id' => $id ), 'not in the trash' );
		wp_trash_post( $id );
		[ , $done ] = $this->confirmed( 'kuduy-post-delete', array( 'id' => $id ) );
		$this->assertTrue( $done['deleted'] );
		$this->assertNull( get_post( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$back = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $back, 'Undo puts it back with the same id.' );
		$this->assertSame( 'trash', $back->post_status, 'Back in the trash.' );
		$this->assertSame( 'kept', get_post_meta( $id, 'kuduy_note', true ) );
		wp_delete_post( $id, true );
	}

	/**
	 * AO4: deleting users — never yourself, never the last administrator; content reassigned; password reset email.
	 */
	public function test_ao4_users(): void {
		$this->fails( 'kuduy-user-delete', array( 'user_id' => self::$admin ), 'not your own account' );
		$author             = self::make_user( 'author' );
		$post               = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy author post',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);
		[ $preview, $done ] = $this->confirmed(
			'kuduy-user-delete',
			array(
				'user_id'     => $author,
				'reassign_to' => self::$admin,
			)
		);
		$this->assertTrue( $done['deleted'] );
		$this->assertFalse( get_userdata( $author ) );
		$this->assertSame( self::$admin, (int) get_post( $post )->post_author, 'Content moved to the chosen user.' );
		$this->assertSame( 1, $preview['preview']['content'] );
		wp_delete_post( $post, true );

		// The last administrator: a user manager (custom role with delete_users) may not delete the only one left.
		add_role(
			'kuduy_user_manager',
			'Kuduy user manager',
			array(
				'read'         => true,
				'list_users'   => true,
				'edit_users'   => true,
				'delete_users' => true,
			)
		);
		$manager = self::make_user( 'kuduy_user_manager' );
		$others  = array_diff(
			array_map(
				'intval',
				get_users(
					array(
						'role'   => 'administrator',
						'fields' => 'ID',
					)
				)
			),
			array( self::$admin )
		);
		foreach ( $others as $other ) {
			( new \WP_User( $other ) )->set_role( 'editor' );
		}
		wp_set_current_user( $manager );
		$message = $this->fails( 'kuduy-user-delete', array( 'user_id' => self::$admin ), 'the last administrator is not deleted' );
		// KuDiagnostics' own guard, checked directly: with WooCommerce active, WooCommerce already denies non-administrators
		// any change to an administrator (wc_modify_map_meta_cap), so the call above stops at the capability check.
		$guard = '';
		$tool  = ToolRegistry::create()->get( 'kuduy-user-delete' );
		$this->assertInstanceOf( Confirmable::class, $tool );
		try {
			$tool->preview( array( 'user_id' => self::$admin ) );
		} catch ( ToolError $e ) {
			$guard = $e->getMessage();
		}
		foreach ( $others as $other ) {
			( new \WP_User( $other ) )->set_role( 'administrator' );
		}
		wp_set_current_user( self::$admin );
		$this->assertStringContainsString( 'last administrator', $guard );
		$this->assertMatchesRegularExpression( '/last administrator|not allowed/', $message );
		$this->assertInstanceOf( \WP_User::class, get_userdata( self::$admin ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $manager );
		remove_role( 'kuduy_user_manager' );

		self::$mails = array();
		$this->assertFalse( $this->ok( 'kuduy-user-password-reset', array( 'user_id' => self::$editor ) )['sent'], 'Asks first.' );
		$this->assertCount( 0, self::$mails );
		$this->assertTrue(
			$this->ok(
				'kuduy-user-password-reset',
				array(
					'user_id' => self::$editor,
					'confirm' => true,
				)
			)['sent']
		);
		$this->assertCount( 1, self::$mails );
		$this->assertSame( get_userdata( self::$editor )->user_email, self::$mails[0]['to'] );
		$this->assertStringNotContainsString(
			'key=',
			(string) wp_json_encode(
				$this->call(
					'kuduy-user-password-reset',
					array(
						'user_id' => self::$editor,
						'confirm' => true,
					)
				)
			),
			'The reset link is never returned.'
		);
	}

	/**
	 * AO5: cron — run now (a one-off is queued, the schedule stays), unschedule with undo.
	 */
	public function test_ao5_cron_run_and_unschedule(): void {
		$hook = 'kuduy_test_cron_hook';
		$args = array( 'a' => 1 );
		wp_schedule_event( time() + 3600, 'daily', $hook, array( $args ) );
		$key  = md5( serialize( array( $args ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress' own cron key.
		$list = $this->ok( 'kuduy-cron-list', array() );
		$this->assertStringContainsString( $key, (string) wp_json_encode( $list ), 'kuduy-cron-list shows the key.' );

		$run = $this->ok(
			'kuduy-cron-run',
			array(
				'hook' => $hook,
				'key'  => $key,
			)
		);
		$this->assertTrue( $run['queued'] );
		$this->assertSame( 2, count( array_filter( (array) _get_cron_array(), static fn( $hooks ) => isset( $hooks[ $hook ] ) ) ), 'A one-off next to the daily event.' );
		wp_unschedule_event( (int) wp_next_scheduled( $hook, array( $args ) ), $hook, array( $args ) );

		$gone = $this->ok(
			'kuduy-cron-unschedule',
			array(
				'hook' => $hook,
				'key'  => $key,
			)
		);
		$this->assertTrue( $gone['removed'] );
		$this->assertFalse( wp_next_scheduled( $hook, array( $args ) ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $gone['_snapshot_id'] ) );
		$this->assertNotFalse( wp_next_scheduled( $hook, array( $args ) ), 'Undo schedules it again.' );
		$this->assertSame( 'daily', wp_get_schedule( $hook, array( $args ) ) );

		// Its plugin scheduled it again before the undo: the undo adds no second copy (found live 2026-11-20).
		$again = $this->ok(
			'kuduy-cron-unschedule',
			array(
				'hook' => $hook,
				'key'  => $key,
			)
		);
		wp_schedule_event( time() + 60, 'daily', $hook, array( $args ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $again['_snapshot_id'] ) );
		$this->assertSame( 1, count( array_filter( (array) _get_cron_array(), static fn( $hooks ) => isset( $hooks[ $hook ] ) ) ), 'One event, not two.' );
		wp_clear_scheduled_hook( $hook, array( $args ) );

		$this->fails(
			'kuduy-cron-unschedule',
			array(
				'hook' => $hook,
				'key'  => str_repeat( 'a', 32 ),
			),
			'unknown event'
		);
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-cron-run',
			array(
				'hook' => 'wp_version_check',
				'key'  => md5( serialize( array() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress' own cron key.
			),
			'editors cannot run cron events'
		);
	}

	/**
	 * AO6: dependency and conflict checks (read only).
	 */
	public function test_ao6_dependencies_and_conflicts(): void {
		$dir = WP_PLUGIN_DIR . '/kuduy-test-needs';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/kuduy-test-needs.php', "<?php\n/**\n * Plugin Name: Kuduy Test Needs\n * Requires Plugins: kuduy-not-there\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test plugin.
		wp_clean_plugins_cache();
		$deps = $this->ok( 'kuduy-integration-dependencies', array() );
		$row  = array_values( array_filter( $deps['requirements'], static fn( array $r ): bool => 'Kuduy Test Needs' === $r['plugin'] ) );
		$this->assertSame( 'not installed', $row[0]['needs_state'] ?? null );
		$this->assertFalse( $row[0]['plugin_active'], 'Inactive, so not a problem.' );
		$this->assertNotContains( 'Kuduy Test Needs', array_column( $deps['problems'], 'plugin' ) );
		wp_delete_file( $dir . '/kuduy-test-needs.php' );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test plugin.
		wp_clean_plugins_cache();

		$conflicts = $this->ok( 'kuduy-integration-conflicts', array() );
		$this->assertIsArray( $conflicts['conflicts'] );
		$seo        = array_values( array_filter( $conflicts['conflicts'], static fn( array $c ): bool => 'seo' === $c['category'] ) );
		$active_seo = array_intersect( array( 'wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php', 'all-in-one-seo-pack/all_in_one_seo_pack.php' ), (array) get_option( 'active_plugins' ) );
		$this->assertSame( count( $active_seo ) > 1, count( $seo ) > 0, 'An SEO conflict is reported exactly when two SEO plugins are active.' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-integration-conflicts', array(), 'editors cannot read plugin checks' );
	}
}
