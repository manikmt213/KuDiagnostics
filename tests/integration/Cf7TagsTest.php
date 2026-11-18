<?php
/**
 * Contact Form 7 form-tags (test matrix FB5, phase B2, D57): tag types and a form's tags read through CF7's own
 * scanner; tags added (with a label, before the submit button), changed, moved with their label and removed, saved
 * with wpcf7_save_contact_form(); invalid tags refused; CF7's configuration check on unsaved markup; undo. Runs only
 * when Contact Form 7 is active (KUDUY_PLUGINS=contact-form-7@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class Cf7TagsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			self::markTestSkipped( 'Contact Form 7 is not active in this run (KUDUY_PLUGINS).' );
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

	public function test_fb5_tags_edit_validate_undo(): void {
		$types = array_column( $this->ok( 'kuduy-cf7-form-tags' )['types'], null, 'type' );
		$this->assertTrue( $types['email']['takes_name'] );
		$this->assertTrue( $types['email']['can_require'] );
		$this->assertFalse( $types['submit']['takes_name'] );

		$id   = (int) $this->ok( 'kuduy-forms-cf7-save', array( 'title' => 'Kuduy CF7 tags' ) )['id'];
		$tags = array_column( $this->ok( 'kuduy-cf7-form-tags', array( 'form_id' => $id ) )['tags'], null, 'id' );
		$this->assertArrayHasKey( 'your-email', $tags, 'CF7\'s default template.' );
		$this->assertTrue( $tags['your-email']['required'] );
		$this->assertArrayHasKey( 'submit#1', $tags );
		$this->assertNotEmpty( $tags['your-name']['label'] );
		$before = (string) wpcf7_contact_form( $id )->prop( 'form' );

		$edited = $this->ok(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'       => 'add',
						'type'     => 'select',
						'name'     => 'topic',
						'required' => true,
						'options'  => array( 'include_blank' ),
						'values'   => array( 'Sales', 'Support' ),
						'label'    => 'Topic',
					),
					array(
						'op'      => 'update',
						'tag'     => 'your-subject',
						'options' => array( 'placeholder' ),
						'values'  => array( 'What is it about?' ),
					),
					array(
						'op'    => 'move',
						'tag'   => 'your-email',
						'after' => 'topic',
					),
					array(
						'op'  => 'remove',
						'tag' => 'your-message',
					),
				),
			)
		);
		$order  = array_column( $edited['tags'], 'id' );
		$this->assertSame( array( 'your-name', 'your-subject', 'topic', 'your-email', 'submit#1' ), $order );
		$after = array_column( $edited['tags'], null, 'id' );
		$this->assertSame( array( 'Sales', 'Support' ), $after['topic']['values'] );
		$this->assertTrue( $after['topic']['required'] );
		$this->assertSame( 'Topic', $after['topic']['label'] );
		$this->assertSame( array( 'placeholder' ), $after['your-subject']['options'] );
		$this->assertNotEmpty( $after['your-email']['label'], 'The tag moved with its label.' );
		$markup = (string) wpcf7_contact_form( $id )->prop( 'form' );
		$this->assertStringContainsString( '[select* topic include_blank "Sales" "Support"]', $markup );
		$this->assertStringNotContainsString( 'your-message', $markup );

		$html = do_shortcode( '[contact-form-7 id="' . $id . '"]' );
		$this->assertStringContainsString( 'name="topic"', $html, 'CF7 renders the new tag.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$this->assertSame( $before, (string) wpcf7_contact_form( $id )->prop( 'form' ), 'Undo puts the markup back exactly.' );

		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'text',
						'name' => 'your-name',
					),
				),
			),
			'name taken'
		);
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'nosuchtag',
						'name' => 'x',
					),
				),
			),
			'unknown type'
		);
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'text',
						'name' => '1bad',
					),
				),
			),
			'bad name'
		);
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'     => 'add',
						'type'   => 'text',
						'name'   => 'x',
						'values' => array( 'a"b' ),
					),
				),
			),
			'quote in a value'
		);
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'update',
						'tag'  => 'your-name',
						'name' => 'renamed',
					),
				),
			),
			'names stay'
		);
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'  => 'move',
						'tag' => 'your-name',
					),
				),
			),
			'move needs a place'
		);
		$this->assertSame( $before, (string) wpcf7_contact_form( $id )->prop( 'form' ), 'Refused calls change nothing.' );

		$check = $this->ok(
			'kuduy-cf7-validate',
			array(
				'form_id' => $id,
				'form'    => '[text* your.name] [submit "Go"]',
			)
		);
		$this->assertFalse( $check['valid'], 'CF7 warns about dots in form-tag names.' );
		$this->assertSame( $before, (string) wpcf7_contact_form( $id )->prop( 'form' ), 'validate saves nothing.' );
		$this->assertTrue( $this->ok( 'kuduy-cf7-validate', array( 'form_id' => $id ) )['valid'] );

		wp_set_current_user( self::make_user( 'author' ) );
		$this->fails(
			'kuduy-cf7-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'  => 'remove',
						'tag' => 'your-name',
					),
				),
			),
			'authors may not edit CF7 forms'
		);
	}
}
