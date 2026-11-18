<?php
/**
 * Form editing, Ninja Forms (test matrix FB3, phase B2, D57): field types from Ninja's registry; a form created with
 * Ninja's own create-form function (submit button and default actions); fields added, changed, moved and removed
 * through Ninja's field functions; title, submit text and success message; removal refused while a submission holds
 * a value; undo; email action with confirm-first routing. Runs only when Ninja Forms is active
 * (KUDUY_PLUGINS=ninja-forms@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Forms\Editing\NinjaEditor;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormEditNinjaTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			self::markTestSkipped( 'Ninja Forms is not active in this run (KUDUY_PLUGINS).' );
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
	 * Field labels in order.
	 *
	 * @param array<string, mixed> $form Form.
	 * @return list<string>
	 */
	private static function labels( array $form ): array {
		return array_column( $form['fields'], 'label' );
	}

	public function test_fb3_types_create_edit_undo(): void {
		$types = array_column( $this->ok( 'kuduy-ninja-forms-field-types' )['types'], null, 'type' );
		$this->assertArrayHasKey( 'email', $types );
		$this->assertContains( 'options', $types['listselect']['properties'] );

		$made = $this->ok(
			'kuduy-ninja-forms-form-create',
			array(
				'title'  => 'Kuduy Ninja edit',
				'fields' => array(
					array(
						'type'       => 'textbox',
						'properties' => array( 'label' => 'Name' ),
					),
					array(
						'type'       => 'email',
						'properties' => array(
							'label'    => 'Email',
							'required' => true,
						),
					),
				),
			)
		);
		$form = $made['form'];
		$id   = (int) $form['id'];
		$this->assertSame( 'Kuduy Ninja edit', $form['title'] );
		$this->assertSame( array( 'Name', 'Email', 'Submit' ), self::labels( $form ), 'Fields go before Ninja\'s submit button.' );
		$this->assertTrue( $form['fields'][1]['required'] );
		$this->assertNotNull( $form['confirmation_message'], 'Ninja\'s create-form adds a success message.' );
		list( $name, $email ) = array_column( $form['fields'], 'id' );

		$edited = $this->ok(
			'kuduy-ninja-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'listselect',
						'properties' => array(
							'label'   => 'Topic',
							'options' => array(
								array(
									'label' => 'Sales',
									'value' => 'sales',
								),
								array( 'label' => 'Support' ),
							),
						),
						'index'      => 0,
					),
					array(
						'op'         => 'update',
						'field'      => $email,
						'properties' => array(
							'required'  => false,
							'help_text' => 'We reply within a day',
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
					'title'                => 'Kuduy Ninja edited',
					'submit_text'          => 'Send it',
					'confirmation_message' => 'Thanks, we got it.',
				),
			)
		);
		$after  = $edited['form'];
		$this->assertSame( array( 'Email', 'Topic', 'Send it' ), self::labels( $after ) );
		$this->assertFalse( $after['fields'][0]['required'] );
		$this->assertSame( 'Sales', $after['fields'][1]['options'][0]['label'] );
		$this->assertSame( 'Kuduy Ninja edited', $after['title'] );
		$this->assertSame( 'Send it', $after['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $after['confirmation_message'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$back = ( new NinjaEditor() )->form( $id );
		$this->assertSame( array( 'Name', 'Email', 'Submit' ), self::labels( $back ), 'Undo puts the fields back.' );
		$this->assertTrue( $back['fields'][1]['required'] );
		$this->assertSame( 'Kuduy Ninja edit', $back['title'] );

		$sub = wp_insert_post(
			array(
				'post_type'   => 'nf_sub',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $sub, '_form_id', $id );
		$email_now = array_column( $back['fields'], 'id', 'label' )['Email'];
		update_post_meta( $sub, '_field_' . $email_now, 'someone@example.test' );
		$text = $this->fails(
			'kuduy-ninja-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => $email_now,
					),
				),
			),
			'a submission holds a value'
		);
		$this->assertStringContainsString( '1 entry holds', $text );
		$this->fails(
			'kuduy-ninja-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'textbox',
						'properties' => array( 'options' => array( array( 'label' => 'x' ) ) ),
					),
				),
			),
			'textbox takes no options'
		);
		$this->assertSame( array( 'Name', 'Email', 'Submit' ), self::labels( ( new NinjaEditor() )->form( $id ) ), 'Refused calls change nothing.' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			'kuduy-ninja-forms-form-edit',
			array(
				'form_id' => $id,
				'form'    => array( 'title' => 'x' ),
			),
			'editors may not change Ninja forms'
		);
	}

	public function test_fb3_email_action(): void {
		$id   = (int) $this->ok( 'kuduy-ninja-forms-form-create', array( 'title' => 'Kuduy Ninja mail' ) )['form']['id'];
		$list = ( new NinjaEditor() )->notifications( $id );
		$this->assertNotEmpty( $list, 'Ninja\'s create-form adds an admin email.' );
		$nid = $list[0]['id'];

		$preview = $this->ok(
			'kuduy-ninja-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'to'      => 'team@example.test',
			)
		);
		$this->assertFalse( $preview['done'] );
		$done = $this->ok(
			'kuduy-ninja-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'to'      => 'team@example.test',
				'cc'      => 'boss@example.test',
				'confirm' => true,
			)
		);
		$this->assertSame( 'team@example.test', $done['notification']['to'] );
		$this->assertSame( 'boss@example.test', $done['notification']['cc'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( $list[0]['to'], ( new NinjaEditor() )->notifications( $id )[0]['to'], 'Undo puts the recipient back.' );

		$made = $this->ok(
			'kuduy-ninja-forms-notification-save',
			array(
				'form_id' => $id,
				'name'    => 'Sales copy',
				'to'      => 'sales@example.test',
				'subject' => 'Lead',
				'message' => '{fields_table}',
				'confirm' => true,
			)
		);
		$this->assertSame( 'Sales copy', $made['notification']['name'] );
		$this->assertCount( count( $list ) + 1, ( new NinjaEditor() )->notifications( $id ) );
	}
}
