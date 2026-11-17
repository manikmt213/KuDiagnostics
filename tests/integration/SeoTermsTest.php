<?php
/**
 * SEO phase G2 (test matrix SG1–SG5), run in each SEO plugin's job: term SEO read, written through the plugin's own
 * save path, undone; settings read; the audit's noindex and duplicates checks.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SeoTermsTest extends TestCase {

	use CallsTools;

	private static string $plugin;
	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		$detected = array_keys( Seo::detected() );
		if ( 1 !== count( $detected ) || ! in_array( $detected[0], array( 'yoast', 'rank-math', 'seopress', 'aioseo' ), true ) ) {
			self::markTestSkipped( 'Not exactly one SEO plugin is active in this run (KUDUY_PLUGINS).' );
		}
		self::$plugin = $detected[0];
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
	 * A fresh category.
	 */
	private static function category(): int {
		$term = wp_insert_term( 'Kuduy SEO cat ' . wp_generate_password( 6, false ), 'category' );
		return is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	public function test_term_seo_round_trip(): void {
		if ( 'aioseo' === self::$plugin ) {
			$this->assertNull( \Kuduy\Mcp\Mcp\ToolRegistry::create()->get( 'kuduy-aioseo-term-seo' ), 'AIOSEO keeps term SEO in Pro only: no tools.' );
			return;
		}
		$p    = self::$plugin;
		$term = self::category();
		$this->assertGreaterThan( 0, $term );
		$before = $this->ok( 'kuduy-' . $p . '-term-seo', array( 'term_id' => $term ) );
		$this->assertSame( 'category', $before['taxonomy'] );
		$this->assertNull( $before['seo']['title'] );

		$after = $this->ok(
			'kuduy-' . $p . '-term-seo-update',
			array(
				'term_id' => $term,
				'seo'     => array(
					'title'          => 'Kuduy category title',
					'description'    => 'All about the Kuduy category.',
					'robots.noindex' => true,
					'og.title'       => 'Kuduy social title',
				),
			)
		);
		$this->assertSame( 'Kuduy category title', $after['seo']['title'] );
		$this->assertSame( 'All about the Kuduy category.', $after['seo']['description'] );
		$this->assertTrue( $after['seo']['robots.noindex'] );
		$this->assertSame( 'Kuduy social title', $after['seo']['og.title'] );
		$this->assertSame( 'Kuduy category title', $this->ok( 'kuduy-' . $p . '-term-seo', array( 'term_id' => $term ) )['seo']['title'], 'Stored, read back fresh.' );

		$cleared = $this->ok(
			'kuduy-' . $p . '-term-seo-update',
			array(
				'term_id' => $term,
				'seo'     => array( 'title' => null ),
			)
		);
		$this->assertNull( $cleared['seo']['title'] );
		$this->assertSame( 'All about the Kuduy category.', $cleared['seo']['description'], 'Fields not given stay.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $cleared['_snapshot_id'] ) );
		$this->assertSame( 'Kuduy category title', $this->ok( 'kuduy-' . $p . '-term-seo', array( 'term_id' => $term ) )['seo']['title'], 'Undo puts the title back.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $after['_snapshot_id'] ) );
		$back = $this->ok( 'kuduy-' . $p . '-term-seo', array( 'term_id' => $term ) )['seo'];
		$this->assertNull( $back['title'] );
		$this->assertNull( $back['robots.noindex'], 'Undo of the first change returns to the taxonomy default.' );

		$this->fails( 'kuduy-' . $p . '-term-seo', array( 'term_id' => 999999 ), 'Unknown term' );
		$this->fails(
			'kuduy-' . $p . '-term-seo-update',
			array(
				'term_id' => $term,
				'seo'     => array( 'colour' => 'blue' ),
			),
			'Unknown fields are refused'
		);
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-' . $p . '-term-seo-update',
			array(
				'term_id' => $term,
				'seo'     => array( 'title' => 'x' ),
			),
			'Authors cannot edit categories'
		);
	}

	public function test_settings(): void {
		if ( 'aioseo' === self::$plugin ) {
			$this->markTestSkipped( 'No settings tool for All in One SEO yet.' );
		}
		$settings = $this->ok( 'kuduy-' . self::$plugin . '-settings' )['settings'];
		$this->assertNotEmpty( $settings );
		$expect = match ( self::$plugin ) {
			'yoast'     => 'title-post',
			'rank-math' => 'pt_post_title',
			default     => 'seopress_titles_sep',
		};
		$this->assertArrayHasKey( $expect, $settings );
	}

	public function test_audit_noindex_and_duplicates(): void {
		$ids = array();
		foreach ( array( 1, 2 ) as $n ) {
			$ids[] = (int) wp_insert_post(
				array(
					'post_title'  => 'Kuduy audit ' . $n,
					'post_status' => 'publish',
				)
			);
		}
		foreach ( $ids as $id ) {
			$this->ok(
				'kuduy-seo-update',
				array(
					'post_id' => $id,
					'title'   => 'Kuduy shared title',
				)
			);
		}
		$this->ok(
			'kuduy-seo-update',
			array(
				'post_id' => $ids[0],
				'robots'  => array( 'noindex' => true ),
			)
		);
		$dup   = $this->ok( 'kuduy-seo-audit', array( 'check' => 'duplicates' ) );
		$found = array_values( array_filter( $dup['duplicates'], static fn( array $d ): bool => 'title' === $d['field'] && 'Kuduy shared title' === $d['value'] ) );
		$this->assertCount( 1, $found );
		$this->assertEqualsCanonicalizing( $ids, $found[0]['ids'] );

		$noindex = array_column( $this->ok( 'kuduy-seo-audit', array( 'check' => 'noindex' ) )['posts'], 'missing', 'id' );
		$this->assertSame( array( 'robots.noindex' ), $noindex[ $ids[0] ] ?? null );
		$this->assertArrayNotHasKey( $ids[1], $noindex );
	}
}
