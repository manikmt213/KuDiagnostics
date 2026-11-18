<?php
/**
 * ACF definitions (test matrix FE1, phase E1, D58): field groups created and changed through ACF's own import (fields
 * added, changed, moved, removed; location rules checked against ACF's rule types), a removal refused while stored
 * values use the field, PHP-registered groups refused, trash and undo; ACF post types and taxonomies created and
 * changed, keys taken by others refused; editors refused. Runs only when ACF is active (KUDUY_PLUGINS=advanced-custom-fields@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AcfDefinitionsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'acf_import_field_group' ) ) {
			self::markTestSkipped( 'ACF is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Drop ACF's request caches, as a new request would start without them.
	 */
	private static function forget(): void {
		foreach ( array( 'fields', 'field-groups' ) as $name ) {
			$store = acf_get_store( $name );
			if ( is_object( $store ) && method_exists( $store, 'reset' ) ) {
				$store->reset();
			}
		}
		wp_cache_flush_group( 'acf' );
	}

	/**
	 * Field names of a group as stored.
	 *
	 * @param string $key Group key.
	 * @return list<string>
	 */
	private static function names( string $key ): array {
		self::forget();
		return array_column( acf_get_fields( $key ), 'name' );
	}

	/**
	 * A group as stored.
	 *
	 * @param string $key Group key.
	 * @return array<string, mixed>
	 */
	private static function fresh_group( string $key ): array {
		self::forget();
		return (array) acf_get_field_group( $key );
	}

	public function test_fe1_field_groups(): void {
		$made = $this->ok(
			'kuduy-acf-field-group-save',
			array(
				'title'      => 'Kuduy book details',
				'location'   => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array(
							'label'    => 'Subtitle',
							'required' => 1,
						),
					),
					array(
						'op'         => 'add',
						'type'       => 'select',
						'properties' => array(
							'label'   => 'Format',
							'name'    => 'book_format',
							'choices' => array(
								'paper' => 'Paperback',
								'ebook' => 'E-book',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $made['created'] );
		$group = $made['field_group'];
		$key   = $group['key'];
		$this->assertSame( array( 'subtitle', 'book_format' ), array_column( $group['fields'], 'name' ) );
		$this->assertTrue( $group['fields'][0]['required'] );
		$this->assertSame( 'E-book', $group['fields'][1]['choices']['ebook'] );
		$this->assertSame( 'post_type', $group['location'][0][0]['param'] );
		$this->assertNotEmpty( acf_get_field( 'subtitle' ), 'ACF finds the new field.' );

		$changed = $this->ok(
			'kuduy-acf-field-group-save',
			array(
				'key'        => $key,
				'title'      => 'Kuduy book',
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'number',
						'properties' => array(
							'label' => 'Pages',
							'min'   => 1,
						),
						'index'      => 0,
					),
					array(
						'op'         => 'update',
						'field'      => 'subtitle',
						'properties' => array( 'required' => 0 ),
					),
					array(
						'op'    => 'move',
						'field' => 'book_format',
						'index' => 0,
					),
					array(
						'op'    => 'remove',
						'field' => 'subtitle',
					),
				),
			)
		);
		$this->assertFalse( $changed['created'] );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'acf-field-group',
					'name'        => $key,
					'post_status' => 'any',
				)
			),
			'A change keeps one group with the key (found: the import made a copy without the post id).'
		);
		$this->assertSame( 'Kuduy book', $changed['field_group']['title'] );
		$this->assertSame( array( 'book_format', 'pages' ), array_column( $changed['field_group']['fields'], 'name' ) );

		$undo = $this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_status, post_parent, post_excerpt FROM {$wpdb->posts} WHERE post_type IN ('acf-field-group','acf-field') AND ( post_name = %s OR post_parent = %d )", $key, (int) $group['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'subtitle', 'book_format' ), self::names( $key ), 'Undo puts the fields back: ' . wp_json_encode( array( $undo, $rows ) ) );
		$this->assertSame( 'Kuduy book details', self::fresh_group( $key )['title'] );

		$post = (int) wp_insert_post(
			array(
				'post_title'  => 'A book',
				'post_status' => 'publish',
			)
		);
		update_field( 'subtitle', 'Volume one', $post );
		$text = $this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'        => $key,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => 'subtitle',
					),
				),
			),
			'a stored value uses it'
		);
		$this->assertStringContainsString( '1 stored value', $text );
		$this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'        => $key,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array(
							'label'             => 'X',
							'conditional_logic' => 1,
						),
					),
				),
			),
			'conditional logic is not written'
		);
		$this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'        => $key,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'no_such_type',
						'properties' => array( 'label' => 'X' ),
					),
				),
			),
			'unknown type'
		);
		$this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'        => $key,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array( 'label' => 'Subtitle' ),
					),
				),
			),
			'name taken'
		);
		$this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'      => $key,
				'location' => array(
					array(
						array(
							'param'    => 'no_such_rule',
							'operator' => '==',
							'value'    => 'x',
						),
					),
				),
			),
			'unknown location rule'
		);
		$this->assertSame( array( 'subtitle', 'book_format' ), self::names( $key ), 'Refused calls change nothing.' );

		acf_add_local_field_group(
			array(
				'key'      => 'group_kuduy_code',
				'title'    => 'In code',
				'fields'   => array(),
				'location' => array(),
			)
		);
		$this->assertStringContainsString(
			'PHP code',
			$this->fails(
				'kuduy-acf-field-group-save',
				array(
					'key'   => 'group_kuduy_code',
					'title' => 'x',
				),
				'PHP group'
			)
		);

		$trash = $this->ok( 'kuduy-acf-field-group-trash', array( 'key' => $key ) );
		$this->assertSame( 'trash', get_post_status( (int) $group['id'] ) );
		$untrash = $this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		clean_post_cache( (int) $group['id'] );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type = 'acf-field-group' AND post_name LIKE %s", $key . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'publish', get_post_status( (int) $group['id'] ), 'Undo takes it out of the trash: ' . wp_json_encode( array( $group['id'], $untrash, $rows ) ) );

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'acf-field-group',
					'name'        => $key,
					'post_status' => array( 'publish', 'trash' ),
				)
			),
			'Undo restores the same group, not a copy.'
		);

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			'kuduy-acf-field-group-save',
			array(
				'key'   => $key,
				'title' => 'x',
			),
			'editors may not change field groups'
		);
		acf_delete_field_group( (int) $group['id'] );
	}

	public function test_fe1_post_types_and_taxonomies(): void {
		$type = $this->ok(
			'kuduy-acf-post-type-save',
			array(
				'post_type'    => 'kuduy_acf_book',
				'singular'     => 'Book',
				'plural'       => 'Books',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'menu_icon'    => 'dashicons-book',
			)
		);
		$this->assertTrue( $type['created'] );
		$this->assertSame( 'Book', $type['item']['singular'] );

		$tax = $this->ok(
			'kuduy-acf-taxonomy-save',
			array(
				'taxonomy'   => 'kuduy_acf_genre',
				'singular'   => 'Genre',
				'plural'     => 'Genres',
				'post_types' => array( 'kuduy_acf_book' ),
			)
		);
		$this->assertTrue( $tax['created'] );

		$renamed = $this->ok(
			'kuduy-acf-post-type-save',
			array(
				'post_type' => 'kuduy_acf_book',
				'plural'    => 'Novels',
			)
		);
		$this->assertFalse( $renamed['created'] );
		$this->assertSame( 'Novels', $renamed['item']['plural'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $renamed['_snapshot_id'] ) );
		$saved = null;
		foreach ( acf_get_internal_post_type_posts( 'acf-post-type' ) as $item ) {
			if ( 'kuduy_acf_book' === $item['post_type'] ) {
				$saved = $item;
			}
		}
		$this->assertSame( 'Books', $saved['labels']['name'] ?? null, 'Undo puts the label back.' );

		$this->fails(
			'kuduy-acf-post-type-save',
			array(
				'post_type' => 'page',
				'singular'  => 'Page',
				'plural'    => 'Pages',
			),
			'core post type'
		);
		$this->fails(
			'kuduy-acf-taxonomy-save',
			array(
				'taxonomy' => 'category',
				'singular' => 'C',
				'plural'   => 'Cs',
			),
			'core taxonomy'
		);
		$this->fails( 'kuduy-acf-post-type-save', array( 'post_type' => 'kuduy_film' ), 'new type needs labels' );
	}
}
