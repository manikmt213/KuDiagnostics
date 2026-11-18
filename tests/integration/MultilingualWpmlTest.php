<?php
/**
 * Multilingual with WPML (test matrix L5, phase L4, D60): status, setting a language with undo (also from no
 * language), linking translations with undo, creating a translation draft, terms by term_taxonomy_id, and the missing
 * translations report. WPML is paid: this runs against the stand-in for its documented hooks (KUDUY_FAKES=wpml), so
 * it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MultilingualWpmlTest extends TestCase {

	use CallsTools;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) ) {
			self::markTestSkipped( 'WPML (or its stand-in) is not loaded without Polylang (KUDUY_FAKES=wpml).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
	}

	protected function setUp(): void {
		wp_set_current_user( self::make_user( 'editor' ) );
	}

	private static function post( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	public function test_l5_wpml_languages_links_and_missing(): void {
		$status = $this->ok( 'kuduy-lang-status' );
		$this->assertSame( 'wpml', $status['plugin'] );
		$this->assertSame( array( 'en', 'de', 'fr' ), array_column( $status['languages'], 'slug' ) );
		$this->assertTrue( $status['languages'][0]['default'] );
		$this->assertContains( 'page', $status['post_types'] );

		$en = self::post( 'Kuduy WPML en' );
		$de = self::post( 'Kuduy WPML de' );
		$this->assertNull( $this->ok( 'kuduy-lang-get', array( 'id' => $en ) )['language'] );

		$set = $this->ok(
			'kuduy-lang-set',
			array(
				'id'       => $en,
				'language' => 'en',
			)
		);
		$this->assertSame( 'en', $set['language'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertNull( $this->ok( 'kuduy-lang-get', array( 'id' => $en ) )['language'], 'Undo takes the language away again.' );

		foreach ( array(
			$en => 'en',
			$de => 'de',
		) as $id => $lang ) {
			$this->ok(
				'kuduy-lang-set',
				array(
					'id'       => $id,
					'language' => $lang,
				)
			);
		}
		$this->fails(
			'kuduy-lang-link',
			array(
				'translations' => array(
					'en' => $en,
					'fr' => $de,
				),
			),
			'post is German, not French'
		);
		$link = $this->ok(
			'kuduy-lang-link',
			array(
				'translations' => array(
					'en' => $en,
					'de' => $de,
				),
			)
		);
		$this->assertSame( $de, $link['translations']['de']['id'] );
		$this->assertSame( array( 'de' => $de ), array_map( static fn( $t ) => $t['id'], array_intersect_key( (array) $this->ok( 'kuduy-lang-get', array( 'id' => $en ) )['translations'], array( 'de' => 1 ) ) ) );

		$missing = $this->ok( 'kuduy-lang-missing', array( 'language' => 'fr' ) )['items'];
		$row     = array_column( $missing, null, 'id' )[ $en ] ?? array_column( $missing, null, 'id' )[ $de ] ?? null;
		$this->assertNotNull( $row, 'The en/de group misses French.' );
		$this->assertSame( array( 'fr' ), $row['missing'] );
		$this->assertCount( 1, array_filter( $missing, static fn( $r ) => in_array( $r['id'], array( $en, $de ), true ) ), 'A group is listed once.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $link['_snapshot_id'] ) );
		$this->assertSame( array( 'en' ), array_keys( (array) $this->ok( 'kuduy-lang-get', array( 'id' => $en ) )['translations'] ), 'Undo unlinks.' );

		wp_set_current_user( self::make_user( 'administrator' ) );
		$fr = $this->ok(
			'kuduy-lang-translate',
			array(
				'id'       => $en,
				'language' => 'fr',
				'title'    => 'Kuduy WPML fr',
			)
		);
		$this->assertSame( 'fr', $fr['language'] );
		$this->assertSame( 'draft', get_post_status( $fr['id'] ) );
		$this->assertSame( $en, ( (array) $fr['translations'] )['en']['id'] );

		$cat  = wp_insert_term( 'Kuduy WPML cat', 'category' );
		$term = $this->ok(
			'kuduy-lang-set',
			array(
				'object'   => 'term',
				'id'       => (int) $cat['term_id'],
				'language' => 'de',
			)
		);
		$this->assertSame( 'de', $term['language'] );
		$this->assertArrayHasKey( 'tax_category|' . (int) $cat['term_taxonomy_id'], get_option( 'kuduy_fake_wpml' )['rows'], 'Terms go to WPML by term_taxonomy_id.' );

		$this->assertIsString( ( new \Kuduy\Mcp\Modules\Multilingual\LanguageTools( 'strings' ) )->is_available(), 'String translation here is Polylang\'s: the tool is not offered with WPML.' );
	}
}
