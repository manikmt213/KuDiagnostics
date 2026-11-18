<?php
/**
 * Form editing, Gravity Forms (test matrix FB6, phase L1, D57): field types from Gravity's field registry; a form
 * created with fields; fields added, changed, moved and removed and the form settings changed in one save; undo;
 * removal refused while an entry holds a value; refusals; a notification with confirm-first routing. Gravity Forms
 * is paid: this runs against the stand-in (KUDUY_FAKES=gravity), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Forms\Editing\GravityEditor;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormEditGravityTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'GFAPI' ) || ! in_array( 'add_test_form', get_class_methods( 'GFAPI' ), true ) ) {
			self::markTestSkipped( 'The Gravity Forms stand-in is not loaded in this run (KUDUY_FAKES=gravity).' );
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

	public function test_fb6_types_create_edit_undo(): void {
		$types = array_column( $this->ok( 'kuduy-gravity-forms-field-types' )['types'], null, 'type' );
		$this->assertArrayHasKey( 'email', $types );
		$this->assertContains( 'choices', $types['select']['properties'] );
		$this->assertNotContains( 'choices', $types['text']['properties'] );
		$this->assertArrayNotHasKey( 'page', $types, 'Page breaks are not offered.' );

		$form = $this->ok(
			'kuduy-gravity-forms-form-create',
			array(
				'title'  => 'Kuduy Gravity edit',
				'fields' => array(
					array(
						'type'       => 'text',
						'properties' => array( 'label' => 'Your name' ),
					),
					array(
						'type'       => 'email',
						'properties' => array(
							'label'    => 'Your email',
							'required' => true,
						),
					),
					array(
						'type'       => 'select',
						'properties' => array(
							'label'   => 'Topic',
							'choices' => array(
								array(
									'label' => 'Sales',
									'value' => 'sales',
								),
								array( 'label' => 'Support' ),
							),
						),
					),
				),
			)
		)['form'];
		$id   = (int) $form['id'];
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( $form ) );
		$this->assertTrue( $form['fields'][1]['required'] );
		$this->assertSame( 'Support', $form['fields'][2]['choices'][1]['value'], 'A choice without a value takes its label.' );
		$stored = \GFAPI::get_form( $id );
		$this->assertTrue( $stored['fields'][1]->isRequired, 'Stored under Gravity\'s own name.' );
		$this->assertSame( 'Sales', $stored['fields'][2]->choices[0]['text'] );

		list( $name, $email, $topic ) = array_column( $form['fields'], 'id' );
		$edited                       = $this->ok(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'textarea',
						'properties' => array(
							'label'       => 'Message',
							'placeholder' => 'Tell us more',
							'maxLength'   => 500,
						),
					),
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array( 'label' => 'Company' ),
						'index'      => 1,
					),
					array(
						'op'         => 'update',
						'field'      => $email,
						'properties' => array( 'required' => false ),
					),
					array(
						'op'    => 'move',
						'field' => $topic,
						'index' => 0,
					),
					array(
						'op'    => 'remove',
						'field' => $name,
					),
				),
				'form'       => array(
					'title'                => 'Kuduy Gravity edited',
					'submit_text'          => 'Send it',
					'confirmation_message' => 'Thanks, we got it.',
				),
			)
		);
		$after                        = $edited['form'];
		$this->assertCount( 2, $edited['added'] );
		$this->assertSame( array( 'Topic', 'Company', 'Your email', 'Message' ), self::labels( $after ) );
		$this->assertFalse( $after['fields'][2]['required'] );
		$this->assertSame( 'Tell us more', $after['fields'][3]['placeholder'] );
		$this->assertSame( 'Kuduy Gravity edited', $after['title'] );
		$this->assertSame( 'Send it', $after['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $after['confirmation_message'] );
		$this->assertNotContains( $name, $edited['added'], 'A removed field\'s id is not reused.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$back = ( new GravityEditor() )->form( $id );
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( $back ), 'Undo puts the fields back.' );
		$this->assertSame( 'Kuduy Gravity edit', $back['title'] );

		\GFAPI::add_test_entry(
			array(
				'form_id' => $id,
				$email    => 'someone@example.test',
			)
		);
		$this->fails(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => $email,
					),
				),
			),
			'an entry holds a value for the field'
		);
		$this->fails(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'text',
						'properties' => array( 'choices' => array( array( 'label' => 'x' ) ) ),
					),
				),
			),
			'text takes no choices'
		);
		$this->fails(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'page',
					),
				),
			),
			'page breaks are not offered'
		);
		$this->fails(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'update',
						'field'      => '999',
						'properties' => array( 'label' => 'x' ),
					),
				),
			),
			'no such field'
		);
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( ( new GravityEditor() )->form( $id ) ), 'A refused call changes nothing.' );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			'kuduy-gravity-forms-form-edit',
			array(
				'form_id' => $id,
				'form'    => array( 'title' => 'x' ),
			),
			'editors have no Gravity Forms capability'
		);
	}

	public function test_fb6_notification_routing_confirm_first(): void {
		$id = (int) $this->ok( 'kuduy-gravity-forms-form-create', array( 'title' => 'Kuduy Gravity mail' ) )['form']['id'];

		$preview = $this->ok(
			'kuduy-gravity-forms-notification-save',
			array(
				'form_id' => $id,
				'name'    => 'Team',
				'to'      => 'team@example.test',
				'subject' => 'New lead',
			)
		);
		$this->assertFalse( $preview['done'] );
		$this->assertEmpty( \GFAPI::get_form( $id )['notifications'] ?? array(), 'Nothing saved before confirm.' );

		$done = $this->ok(
			'kuduy-gravity-forms-notification-save',
			array(
				'form_id' => $id,
				'name'    => 'Team',
				'to'      => 'team@example.test',
				'subject' => 'New lead',
				'bcc'     => 'archive@example.test',
				'confirm' => true,
			)
		);
		$this->assertTrue( $done['done'] );
		$nid    = (string) $done['notification']['id'];
		$stored = \GFAPI::get_form( $id )['notifications'][ $nid ];
		$this->assertSame( 'team@example.test', $stored['to'] );
		$this->assertSame( 'email', $stored['toType'] );
		$this->assertSame( 'archive@example.test', $stored['bcc'] );
		$this->assertSame( 'form_submission', $stored['event'] );

		$subject = $this->ok(
			'kuduy-gravity-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'subject' => 'Changed subject',
			)
		);
		$this->assertTrue( $subject['done'], 'A subject change needs no confirmation.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $subject['_snapshot_id'] ) );
		$this->assertSame( 'New lead', \GFAPI::get_form( $id )['notifications'][ $nid ]['subject'], 'Undo puts the subject back.' );

		$off = $this->ok(
			'kuduy-gravity-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'enabled' => false,
				'confirm' => true,
			)
		);
		$this->assertFalse( $off['notification']['enabled'] );
		$this->assertFalse( \GFAPI::get_form( $id )['notifications'][ $nid ]['isActive'] );

		$this->fails(
			'kuduy-gravity-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => 'nope',
				'subject' => 'x',
			),
			'no such notification'
		);
	}
}
