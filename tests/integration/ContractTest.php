<?php
/**
 * Tool contract (test matrix CT1–CT8, decisions D44–D46, D52): tool errors name their failure category; high-risk
 * tools run only with a single-use confirmation token bound to user, arguments and the state of the target;
 * kuduy-media-delete deletes permanently only after that; kuduy-integration-get and -diagnose report real state.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\Executor;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Modules\Core\IntegrationCatalog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ContractTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $admin2;
	private static int $author;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$admin2 = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Failure category of an error result (second text block and _meta agree).
	 *
	 * @param array<string, mixed> $result CallToolResult.
	 * @return array{code: string, retryable: bool}
	 */
	private function category( array $result ): array {
		$this->assertTrue( $result['isError'], (string) wp_json_encode( $result ) );
		$this->assertArrayNotHasKey( 'structuredContent', $result, 'An error carries no structured result.' );
		$error = json_decode( (string) ( $result['content'][1]['text'] ?? '' ), true )['error'] ?? null;
		$this->assertIsArray( $error, 'The second text block names the failure: ' . (string) wp_json_encode( $result ) );
		$this->assertContains( $error['code'], ToolError::CATEGORIES );
		$this->assertSame( $error, $result['_meta']['kuduy/error'] ?? null );
		return $error;
	}

	/**
	 * A media item with a real file, owned by a user.
	 *
	 * @param int $owner User id.
	 * @return array{0: int, 1: string} Attachment id and file path.
	 */
	private function attachment( int $owner ): array {
		$upload = wp_upload_dir();
		$path   = trailingslashit( $upload['path'] ) . 'kuduy-ct-' . wp_generate_password( 8, false ) . '.png';
		// Smallest valid PNG (1x1).
		file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) ); // phpcs:ignore
		$id = wp_insert_attachment(
			array(
				'post_title'     => 'Contract test image',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_author'    => $owner,
			),
			$path
		);
		$this->assertGreaterThan( 0, $id );
		return array( $id, $path );
	}

	public function test_errors_name_their_category(): void {
		$invalid = $this->category( $this->call( 'kuduy-post-get', array( 'id' => 'not-a-number' ) ) );
		$this->assertSame( ToolError::INVALID_ARGUMENT, $invalid['code'] );
		$this->assertFalse( $invalid['retryable'] );

		$missing = $this->call( 'kuduy-integration-get', array( 'integration' => 'no-such-thing' ) );
		$this->assertSame( ToolError::RESOURCE_NOT_FOUND, $this->category( $missing )['code'] );
		$this->assertStringContainsString( 'No integration with this id', $missing['content'][0]['text'], 'The first block stays the plain message.' );

		[ $id ] = $this->attachment( self::$admin );
		wp_set_current_user( self::$author );
		$this->assertSame( ToolError::PERMISSION_DENIED, $this->category( $this->call( 'kuduy-media-delete', array( 'id' => $id ) ) )['code'] );
		wp_set_current_user( self::$admin );
		wp_delete_attachment( $id, true );

		$generic = new ToolError( 'x' );
		$this->assertSame( ToolError::OPERATION_FAILED, $generic->category() );
		$this->assertSame( ToolError::OPERATION_FAILED, ( new ToolError( 'x', 'MADE_UP' ) )->category() );
		$this->assertTrue( ToolError::retryable( ToolError::CONFLICT ) );
	}

	public function test_unavailable_tool_names_its_category(): void {
		$unavailable = null;
		foreach ( ToolRegistry::create()->all() as $tool ) {
			if ( true !== $tool->is_available() ) {
				$unavailable = $tool;
				break;
			}
		}
		if ( null === $unavailable ) {
			$this->markTestSkipped( 'Every tool is available on this site.' );
		}
		$error = $this->category( Executor::call( $unavailable, array() ) );
		$this->assertContains( $error['code'], array( ToolError::PLUGIN_NOT_ACTIVE, ToolError::FEATURE_NOT_SUPPORTED ) );
	}

	public function test_media_delete_needs_a_confirmation(): void {
		[ $id, $path ] = $this->attachment( self::$admin );
		$post          = wp_insert_post(
			array(
				'post_title'  => 'Uses the image',
				'post_status' => 'publish',
			)
		);
		set_post_thumbnail( $post, $id );

		$first = $this->ok( 'kuduy-media-delete', array( 'id' => $id ) );
		$this->assertTrue( $first['confirmation_required'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $first['confirm_token'] );
		$this->assertSame( 600, $first['expires_in'] );
		$this->assertSame( $id, $first['preview']['id'] );
		$this->assertArrayNotHasKey( 'version', $first['preview'] );
		$this->assertSame( array( 'featured_image' ), array_column( $first['preview']['uses'], 'where' ) );
		$this->assertStringContainsString( 'cannot be undone', $first['preview']['warning'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $id ), 'The preview deletes nothing.' );
		$this->assertFileExists( $path );

		$done = $this->ok(
			'kuduy-media-delete',
			array(
				'id'            => $id,
				'confirm_token' => $first['confirm_token'],
			)
		);
		$this->assertTrue( $done['deleted'] );
		$this->assertNull( get_post( $id ) );
		$this->assertFileDoesNotExist( $path );

		global $wpdb;
		$rows = $wpdb->get_col( "SELECT args_redacted FROM {$wpdb->prefix}kuduy_audit_log WHERE tool = 'kuduy-media-delete'" ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $rows );
		$this->assertStringNotContainsString( $first['confirm_token'], implode( ' ', $rows ), 'The token is never written to the audit log.' );
		$this->assertStringContainsString( '_confirmation', implode( ' ', $rows ), 'The confirmed call carries a reference.' );
		wp_delete_post( $post, true );
	}

	public function test_a_token_fits_one_call_only(): void {
		[ $a ]    = $this->attachment( self::$admin );
		[ $b ]    = $this->attachment( self::$admin );
		$conflict = function ( array $args, string $because ) use ( $a ): void {
			$error = $this->category( $this->call( 'kuduy-media-delete', $args ) );
			$this->assertSame( ToolError::CONFLICT, $error['code'], $because );
			$this->assertTrue( $error['retryable'] );
			$this->assertInstanceOf( \WP_Post::class, get_post( $a ), $because );
		};
		$token    = fn( int $id ): string => $this->ok( 'kuduy-media-delete', array( 'id' => $id ) )['confirm_token'];

		$t = $token( $a );
		$conflict(
			array(
				'id'            => $b,
				'confirm_token' => $t,
			),
			'A token for one item does not delete another.'
		);
		$this->assertInstanceOf( \WP_Post::class, get_post( $b ) );
		$conflict(
			array(
				'id'            => $a,
				'confirm_token' => $t,
			),
			'A token that was tried once is used up.'
		);

		$t = $token( $a );
		wp_set_current_user( self::$admin2 );
		$conflict(
			array(
				'id'            => $a,
				'confirm_token' => $t,
			),
			'Another account cannot use the token.'
		);
		wp_set_current_user( self::$admin );

		$t    = $token( $a );
		$post = wp_insert_post(
			array(
				'post_title'  => 'Starts using the image',
				'post_status' => 'publish',
			)
		);
		set_post_thumbnail( $post, $a );
		$conflict(
			array(
				'id'            => $a,
				'confirm_token' => $t,
			),
			'The item changed since the preview.'
		);

		$t = $token( $a );
		delete_transient( 'kuduy_mcp_confirm_' . hash( 'sha256', $t ) );
		$conflict(
			array(
				'id'            => $a,
				'confirm_token' => $t,
			),
			'An expired token is refused.'
		);

		$this->fails(
			'kuduy-media-delete',
			array(
				'id'            => $a,
				'confirm_token' => 'short',
			),
			'A malformed token is refused by the schema'
		);
		$this->assertInstanceOf( \WP_Post::class, get_post( $a ) );

		wp_delete_post( $post, true );
		wp_delete_attachment( $a, true );
		wp_delete_attachment( $b, true );
	}

	public function test_media_delete_is_an_admin_scope_tool(): void {
		$tool = ToolRegistry::create()->get( 'kuduy-media-delete' );
		$this->assertSame( 'kuduy:admin', $tool->scope() );
		$this->assertTrue( $tool->annotations()['destructiveHint'] );
		$this->assertArrayNotHasKey( 'required', $tool->output_schema(), 'A preview result has other fields.' );
	}

	public function test_integration_get(): void {
		$core = $this->ok( 'kuduy-integration-get', array( 'integration' => 'gutenberg' ) );
		$this->assertTrue( $core['active'] );
		$this->assertSame( array(), $core['requires'] );
		$names = array_column( $core['tools'], 'name' );
		$this->assertContains( 'kuduy-block-types', $names );
		foreach ( $core['tools'] as $tool ) {
			$this->assertSame( true === ToolRegistry::create()->get( $tool['name'] )->is_available(), $tool['available'] );
			$this->assertArrayNotHasKey( 'input_schema', $tool );
			$this->assertContains( $tool['scope'], array( 'kuduy:read', 'kuduy:write', 'kuduy:admin', 'kuduy:code' ) );
		}
		$with = $this->ok(
			'kuduy-integration-get',
			array(
				'integration'     => 'gutenberg',
				'include_schemas' => true,
			)
		);
		$this->assertSame( 'object', $with['tools'][0]['input_schema']['type'] );

		$addon = $this->ok( 'kuduy-integration-get', array( 'integration' => 'happy-addons' ) );
		$this->assertSame( array( 'elementor' ), array_column( $addon['requires'], 'id' ) );
		$this->assertSame( defined( 'ELEMENTOR_VERSION' ), $addon['requires'][0]['active'] );

		wp_set_current_user( self::$editor );
		$this->assertArrayNotHasKey( 'version', $this->ok( 'kuduy-integration-get', array( 'integration' => 'gutenberg' ) ) );
	}

	public function test_integration_diagnose(): void {
		$one = $this->ok( 'kuduy-integration-diagnose', array( 'integration' => 'mosaic' ) );
		$this->assertSame( 1, $one['checked'] );
		$this->assertSame( array( 'info' ), array_column( $one['findings'], 'level' ) );
		$this->assertStringContainsString( 'not installed', $one['findings'][0]['message'] );

		$all = $this->ok( 'kuduy-integration-diagnose' );
		$this->assertGreaterThanOrEqual( 1, $all['checked'], 'WordPress itself is always checked.' );
		$this->assertContains( 'gutenberg', array_column( $all['findings'], 'integration' ), 'Partly supported is said.' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-integration-diagnose', array(), 'Diagnosis is for those who manage plugins' );
	}

	public function test_catalogue_dependencies_exist(): void {
		foreach ( IntegrationCatalog::all() as $row ) {
			foreach ( IntegrationCatalog::requires( $row['id'] ) as $parent ) {
				$this->assertNotNull( IntegrationCatalog::find( $parent ), $row['id'] . ' needs an integration that is not in the catalogue: ' . $parent );
			}
		}
		$this->assertNotNull( IntegrationCatalog::find( 'spectra-blocks' ), 'Spectra Blocks and Spectra Legacy are two plugins (D54).' );
		$this->assertSame( array( 'ultimate-addons-for-gutenberg' ), IntegrationCatalog::find( 'spectra' )['folders'] );
	}
}
