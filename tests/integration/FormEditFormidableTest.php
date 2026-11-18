<?php
/**
 * Form editing, Formidable Forms (test matrix FB4, phase B2, D57): field types from Formidable's ability helper; a
 * form created and fields added, changed, moved and removed through Formidable's ability controllers; title, submit
 * text and On Submit message; removal refused while an entry holds a value (Formidable would delete it); undo;
 * email action with confirm-first routing. Runs only when Formidable is active (KUDUY_PLUGINS=formidable@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Forms\Editing\FormidableEditor;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormEditFormidableTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'FrmForm' ) ) {
			self::markTestSkipped( 'Formidable is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
		wp_set_current_user( self::$admin );
		// Creates Formidable's tables when the plugin was activated without its admin screens.
		\FrmAppController::install();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Field labels in order.
	 *
	 * @param array<string, mixed> $form Form.
	 * @return list<string>
	 */
	private static function labels( array $form ): array {
		return array_column( $form['fields'], 'label' );
	}

	public function test_fb4_types_create_edit_undo(): void {
		$types = array_column( $this->ok( 'kuduy-formidable-field-types' )['types'], null, 'type' );
		$this->assertArrayHasKey( 'email', $types );
		$this->assertContains( 'options', $types['select']['properties'] );
		$this->assertArrayNotHasKey( 'dropdown', $types, 'Aliases are not listed.' );

		$made = $this->ok(
			'kuduy-formidable-form-create',
			array(
				'title'  => 'Kuduy Formidable edit',
				'fields' => array(
					array(
						'type'       => 'text',
						'properties' => array( 'name' => 'Name' ),
					),
					array(
						'type'       => 'email',
						'properties' => array(
							'name'     => 'Email',
							'required' => true,
						),
					),
				),
			)
		);
		$form = $made['form'];
		$id   = (int) $form['id'];
		$this->assertSame( 'Kuduy Formidable edit', $form['title'] );
		$this->assertSame( array( 'Name', 'Email' ), self::labels( $form ) );
		$this->assertTrue( $form['fields'][1]['required'] );
		list( $name, $email ) = array_column( $form['fields'], 'id' );

		$edited = $this->ok(
			'kuduy-formidable-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'select',
						'properties' => array(
							'name'    => 'Topic',
							'options' => array( 'Sales', 'Support' ),
						),
						'index'      => 0,
					),
					array(
						'op'         => 'update',
						'field'      => $email,
						'properties' => array(
							'required'    => false,
							'placeholder' => 'you@example.com',
						),
					),
					array(
						'op'    => 'move',
						'field' => $email,
						'index' => 0,
					),
					array(
						'op'    => 'remove',
						'field' => $name,
					),
				),
				'form'       => array(
					'title'                => 'Kuduy Formidable edited',
					'submit_text'          => 'Send it',
					'confirmation_message' => 'Thanks, we got it.',
				),
			)
		);
		$after  = $edited['form'];
		$this->assertSame( array( 'Email', 'Topic' ), self::labels( $after ) );
		$this->assertFalse( $after['fields'][0]['required'] );
		$this->assertSame( 'you@example.com', $after['fields'][0]['placeholder'] );
		$this->assertSame( array( 'Sales', 'Support' ), array_column( $after['fields'][1]['options'], 'label' ) );
		$this->assertSame( 'Kuduy Formidable edited', $after['title'] );
		$this->assertSame( 'Send it', $after['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $after['confirmation_message'] );

		$html = do_shortcode( '[formidable id=' . $id . ']' );
		$this->assertStringContainsString( 'Topic', $html, 'Formidable renders the new field.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$back = ( new FormidableEditor() )->form( $id );
		$this->assertSame( array( 'Name', 'Email' ), self::labels( $back ), 'Undo puts the fields back.' );
		$this->assertTrue( $back['fields'][1]['required'] );
		$this->assertSame( 'Kuduy Formidable edit', $back['title'] );

		$email_now = array_column( $back['fields'], 'id', 'label' )['Email'];
		\FrmEntry::create(
			array(
				'form_id'   => $id,
				'item_key'  => 'kuduy' . wp_rand(),
				'item_meta' => array( (int) $email_now => 'someone@example.test' ),
			)
		);
		$text = $this->fails(
			'kuduy-formidable-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => $email_now,
					),
				),
			),
			'an entry holds a value'
		);
		$this->assertStringContainsString( '1 entry holds', $text );
		$this->fails(
			'kuduy-formidable-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array( 'field_options' => array( 'x' => 1 ) ),
					),
				),
			),
			'raw field_options are not taken'
		);
		$this->assertSame( array( 'Name', 'Email' ), self::labels( ( new FormidableEditor() )->form( $id ) ), 'Refused calls change nothing.' );

		wp_set_current_user( self::make_user( 'author' ) );
		$this->fails(
			'kuduy-formidable-form-edit',
			array(
				'form_id' => $id,
				'form'    => array( 'title' => 'x' ),
			),
			'authors may not edit Formidable forms'
		);
	}

	public function test_fb4_email_action(): void {
		$id      = (int) $this->ok( 'kuduy-formidable-form-create', array( 'title' => 'Kuduy Formidable mail' ) )['form']['id'];
		$preview = $this->ok(
			'kuduy-formidable-notification-save',
			array(
				'form_id' => $id,
				'to'      => 'team@example.test',
				'subject' => 'New lead',
			)
		);
		$this->assertFalse( $preview['done'] );
		$this->assertCount( 0, ( new FormidableEditor() )->notifications( $id ) );

		$made = $this->ok(
			'kuduy-formidable-notification-save',
			array(
				'form_id'    => $id,
				'to'         => 'team@example.test',
				'from_name'  => 'Shop',
				'from_email' => 'shop@example.test',
				'subject'    => 'New lead',
				'confirm'    => true,
			)
		);
		$nid  = $made['notification']['id'];
		$this->assertSame( 'team@example.test', $made['notification']['to'] );
		$this->assertSame( 'Shop', $made['notification']['from_name'] );
		$this->assertSame( 'shop@example.test', $made['notification']['from_email'] );

		$off = $this->ok(
			'kuduy-formidable-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'enabled' => false,
				'confirm' => true,
			)
		);
		$this->assertFalse( $off['notification']['enabled'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$this->assertTrue( ( new FormidableEditor() )->notifications( $id )[0]['enabled'], 'Undo switches it back on.' );
	}
}
