<?php
/**
 * Phase I (test matrix ME1–ME7): memory tools (D70).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Memory\MemoryStore;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MemoryTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;
	private static int $other;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$other  = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$editor );
	}

	/**
	 * ME1: save, read, change with version check, undo; persists in the table (survives a new connection).
	 */
	public function test_save_get_change_undo(): void {
		$saved = $this->ok(
			'kuduy-memory-save',
			array(
				'kind'    => 'decision',
				'title'   => 'Kuduy brand blue',
				'body'    => 'Buttons use the brand blue #1e40af; never green.',
				'tags'    => array( 'brand', 'colors' ),
				'project' => 'kuduy-redesign',
			)
		)['memory'];
		$this->assertSame( 1, $saved['version'] );
		$this->assertTrue( $saved['shared'] );
		$this->assertTrue( $saved['mine'] );
		$this->assertNotNull( MemoryStore::get( $saved['id'] ), 'Stored in the table, so it outlives the connection.' );

		$read = $this->ok( 'kuduy-memory-get', array( 'id' => $saved['id'] ) )['memory'];
		$this->assertSame( 'Buttons use the brand blue #1e40af; never green.', $read['body'] );

		$changed = $this->ok(
			'kuduy-memory-save',
			array(
				'id'      => $saved['id'],
				'version' => 1,
				'body'    => 'Buttons use the brand blue #1d4ed8.',
			)
		);
		$this->assertSame( 2, $changed['memory']['version'] );
		$this->assertSame( 'Kuduy brand blue', $changed['memory']['title'], 'Fields not given stay.' );
		$this->fails(
			'kuduy-memory-save',
			array(
				'id'      => $saved['id'],
				'version' => 1,
				'body'    => 'stale write',
			),
			'a stale version is refused'
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( 'Buttons use the brand blue #1e40af; never green.', $this->ok( 'kuduy-memory-get', array( 'id' => $saved['id'] ) )['memory']['body'] );
	}

	/**
	 * ME2: secrets are refused in title, body, tags and import — nothing is written.
	 */
	public function test_secrets_refused(): void {
		$before = MemoryStore::find( array( 'include_expired' => true ), self::$editor, 5000, 0, true )[1];
		// Fake keys are put together at runtime, so no key-shaped text sits in the repository (secret scanners would
		// rightly stop a push that contains one).
		$fill = str_repeat( 'x', 24 );
		$join = static fn( string ...$parts ): string => implode( '', $parts );
		foreach ( array(
			$join( 'The Stripe key is sk', '_live_', $fill ),
			'password = "hunter2hunter2"',
			'api_key: "abcdefghijklmnop1234"',
			$join( 'Use token gh', 'p_', str_repeat( 'k', 36 ) ),
			$join( 'AWS AK', 'IA', str_repeat( 'Q', 16 ), ' for uploads' ),
			$join( '-----BEGIN RSA PRIVATE', " KEY-----\nMIIE" ),
			$join( 'Authorization: Bearer ey', 'J', $fill, '.ey', 'J', $fill, '.', $fill ),
			$join( 'OpenAI sk', '-proj-', $fill ),
		) as $secret ) {
			$this->fails(
				'kuduy-memory-save',
				array(
					'title' => 'Kuduy secret test',
					'body'  => $secret,
				),
				'secret in body: ' . $secret
			);
		}
		$this->fails(
			'kuduy-memory-save',
			array(
				'title' => 'token=abcdef0123456789abcdef',
				'body'  => 'x',
			),
			'secret in title'
		);
		wp_set_current_user( self::$admin );
		$this->fails(
			'kuduy-memory-import',
			array(
				'items' => array(
					array( 'title' => 'Kuduy fine' ),
					array(
						'title' => 'Kuduy bad',
						'body'  => 'secret: "abcdefghijklmnopqrstuv"',
					),
				),
			),
			'one bad item stops the import'
		);
		$this->assertSame( $before, MemoryStore::find( array( 'include_expired' => true ), self::$editor, 5000, 0, true )[1], 'Nothing was written.' );
		$this->ok(
			'kuduy-memory-save',
			array(
				'title' => 'Kuduy where the key lives',
				'body'  => 'The Stripe key is set in WooCommerce > Payments > Stripe.',
			)
		);
	}

	/**
	 * ME3: private memories stay private; others' shared memories cannot be changed or deleted except by admins.
	 */
	public function test_visibility_and_ownership(): void {
		$private = $this->ok(
			'kuduy-memory-save',
			array(
				'title'  => 'Kuduy my private note',
				'shared' => false,
			)
		)['memory'];
		$shared  = $this->ok( 'kuduy-memory-save', array( 'title' => 'Kuduy shared note' ) )['memory'];

		wp_set_current_user( self::$other );
		$this->fails( 'kuduy-memory-get', array( 'id' => $private['id'] ), 'another user cannot read a private memory' );
		$ids = array_column( $this->ok( 'kuduy-memory-list', array( 'per_page' => 100 ) )['memories'], 'id' );
		$this->assertNotContains( $private['id'], $ids );
		$this->assertContains( $shared['id'], $ids );
		$this->fails(
			'kuduy-memory-save',
			array(
				'id'    => $shared['id'],
				'title' => 'hijack',
			),
			'only the author or an admin changes it'
		);
		$this->fails( 'kuduy-memory-delete', array( 'id' => $shared['id'] ), 'only the author or an admin deletes it' );

		wp_set_current_user( self::$admin );
		$this->ok(
			'kuduy-memory-save',
			array(
				'id'     => $shared['id'],
				'status' => 'outdated',
			)
		);
		$this->fails( 'kuduy-memory-get', array( 'id' => $private['id'] ), 'even an admin does not read someone\'s private memory' );

		wp_set_current_user( self::make_user( 'subscriber' ) );
		$this->fails( 'kuduy-memory-list', array(), 'subscribers have no memory access' );
	}

	/**
	 * ME4: relevance search orders title hits before body hits; expired memories are left out.
	 */
	public function test_search_and_expiry(): void {
		$body  = $this->ok(
			'kuduy-memory-save',
			array(
				'title' => 'Kuduy checkout notes',
				'body'  => 'The zebrawidget appears once.',
			)
		)['memory'];
		$title = $this->ok(
			'kuduy-memory-save',
			array(
				'title' => 'Kuduy zebrawidget placement',
				'body'  => 'Header only.',
			)
		)['memory'];
		$gone  = $this->ok(
			'kuduy-memory-save',
			array(
				'title'      => 'Kuduy zebrawidget old',
				'expires_at' => '2000-01-01',
			)
		)['memory'];
		$hits  = array_column( $this->ok( 'kuduy-memory-search', array( 'query' => 'zebrawidget' ) )['memories'], 'id' );
		$this->assertSame( array( $title['id'], $body['id'] ), $hits, 'Title hit first; the expired one left out.' );
		$this->assertContains( $gone['id'], array_column( $this->ok( 'kuduy-memory-list', array( 'include_expired' => true ) )['memories'], 'id' ) );
	}

	/**
	 * ME5: context flags memories whose integrations are not active now.
	 */
	public function test_context_conflicts(): void {
		$saved = $this->ok(
			'kuduy-memory-save',
			array(
				'title'   => 'Kuduy shop runs on WooCommerce',
				'kind'    => 'fact',
				'project' => 'kuduy-context',
				'refs'    => array( 'woocommerce', 'no-such-integration' ),
			)
		)['memory'];
		$ctx   = $this->ok( 'kuduy-memory-context', array( 'project' => 'kuduy-context' ) );
		$row   = array_column( $ctx['memories'], null, 'id' )[ $saved['id'] ];
		$this->assertContains( 'no-such-integration is not an integration KuDiagnostics knows', $row['conflicts'] );
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertNotEmpty( array_filter( $row['conflicts'], static fn( string $c ): bool => str_starts_with( $c, 'woocommerce is ' ) ), 'WooCommerce is not active in this run.' );
		}
		$this->assertSame( 1, $ctx['kinds']['fact'] ?? null );
	}

	/**
	 * ME6: export and import round trip; bulk delete needs an admin and confirm, and undo puts the rows back.
	 */
	public function test_export_import_bulk_delete(): void {
		$this->ok(
			'kuduy-memory-save',
			array(
				'title'   => 'Kuduy export one',
				'project' => 'kuduy-export',
			)
		);
		$this->ok(
			'kuduy-memory-save',
			array(
				'title'   => 'Kuduy export two',
				'project' => 'kuduy-export',
			)
		);
		$export = $this->ok( 'kuduy-memory-export', array( 'project' => 'kuduy-export' ) );
		$this->assertSame( 2, $export['total'] );
		$this->fails( 'kuduy-memory-import', array( 'items' => array( array( 'title' => 'x' ) ) ), 'editors cannot import' );

		wp_set_current_user( self::$admin );
		$items = array_map(
			static function ( array $m ): array {
				$m['project'] = 'kuduy-imported';
				return $m;
			},
			$export['memories']
		);
		$this->assertSame( 2, $this->ok( 'kuduy-memory-import', array( 'items' => $items ) )['total'] );
		$this->assertSame( 2, $this->ok( 'kuduy-memory-list', array( 'project' => 'kuduy-imported' ) )['total'] );

		$this->fails(
			'kuduy-memory-delete',
			array(
				'project' => 'kuduy-export',
				'all'     => true,
			),
			'bulk delete needs confirm'
		);
		$deleted = $this->ok(
			'kuduy-memory-delete',
			array(
				'project' => 'kuduy-export',
				'all'     => true,
				'confirm' => true,
			)
		);
		$this->assertSame( 2, $deleted['deleted'] );
		$this->assertSame( 0, $this->ok( 'kuduy-memory-list', array( 'project' => 'kuduy-export' ) )['total'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( 2, $this->ok( 'kuduy-memory-list', array( 'project' => 'kuduy-export' ) )['total'], 'Undo puts them back.' );

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-memory-delete',
			array(
				'all'     => true,
				'confirm' => true,
			),
			'editors cannot bulk delete'
		);
	}

	/**
	 * ME7: the audit row of a save holds no secret even when a save is refused for one.
	 */
	public function test_audit_has_no_secret(): void {
		$this->fails(
			'kuduy-memory-save',
			array(
				'title' => 'Kuduy audit',
				'body'  => 'api_key = "kuduy-audit-0123456789abcdef"',
			),
			'refused'
		);
		global $wpdb;
		$args = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT args_redacted FROM %i WHERE tool = %s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'kuduy_audit_log', 'kuduy-memory-save' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test reads the plugin's own audit table.
		$this->assertStringNotContainsString( 'kuduy-audit-0123456789abcdef', $args );
	}
}
