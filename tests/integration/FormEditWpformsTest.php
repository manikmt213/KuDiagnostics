<?php
/**
 * Form editing, WPForms Lite (test matrix FB1, phase B2, D57): field types from WPForms' own registry; a form created
 * with fields; fields added, changed, moved and removed and the form settings changed in one save through WPForms'
 * form handler; the front end renders the change; undo; refusals; the notification (Lite: one, no Bcc) with
 * confirm-first routing. Runs only when WPForms is active (KUDUY_PLUGINS=wpforms-lite@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Forms\Editing\WpformsEditor;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormEditWpformsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wpforms' ) ) {
			self::markTestSkipped( 'WPForms is not active in this run (KUDUY_PLUGINS).' );
		}
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
	 * Field labels in order.
	 *
	 * @param array<string, mixed> $form Form.
	 * @return list<string>
	 */
	private static function labels( array $form ): array {
		return array_column( $form['fields'], 'label' );
	}

	public function test_fb1_types_create_edit_undo(): void {
		$types = array_column( $this->ok( 'kuduy-wpforms-field-types' )['types'], null, 'type' );
		$this->assertArrayHasKey( 'email', $types );
		$this->assertContains( 'choices', $types['select']['properties'] );
		$this->assertArrayNotHasKey( 'phone', $types, 'Pro-only types are not offered on Lite.' );

		$made = $this->ok(
			'kuduy-wpforms-form-create',
			array(
				'title'  => 'Kuduy WPForms edit',
				'fields' => array(
					array(
						'type'       => 'name',
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
		);
		$form = $made['form'];
		$id   = (int) $form['id'];
		$this->assertSame( 'Kuduy WPForms edit', $form['title'] );
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( $form ) );
		$this->assertTrue( $form['fields'][1]['required'] );
		$this->assertSame( 'Sales', $form['fields'][2]['choices'][0]['label'] );
		$this->assertCount(
			1,
			$this->ok(
				'kuduy-forms-get',
				array(
					'plugin'  => 'wpforms',
					'form_id' => $id,
				)
			)['notifications'],
			'WPForms\' own default notification.'
		);

		list( $name, $email, $topic ) = array_column( $form['fields'], 'id' );
		$edited                       = $this->ok(
			'kuduy-wpforms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'textarea',
						'properties' => array(
							'label'       => 'Message',
							'placeholder' => 'Tell us more',
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
					'title'                => 'Kuduy WPForms edited',
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
		$this->assertSame( 'Kuduy WPForms edited', $after['title'] );
		$this->assertSame( 'Send it', $after['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $after['confirmation_message'] );
		$this->assertNotContains( $name, array_column( $after['fields'], 'id' ) );
		$this->assertNotContains( $name, $edited['added'], 'A removed field\'s id is not reused.' );

		$html = do_shortcode( '[wpforms id="' . $id . '"]' );
		$this->assertStringContainsString( 'Company', $html, 'WPForms renders the new field.' );
		$this->assertStringContainsString( 'Send it', $html );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$back = ( new WpformsEditor() )->form( $id );
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( $back ), 'Undo puts the fields back.' );
		$this->assertTrue( $back['fields'][1]['required'] );

		$this->fails(
			'kuduy-wpforms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'phone',
					),
				),
			),
			'Pro type on Lite'
		);
		$this->fails(
			'kuduy-wpforms-form-edit',
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
			'kuduy-wpforms-form-edit',
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
		$this->fails(
			'kuduy-wpforms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => $name,
					),
					array(
						'op'         => 'update',
						'field'      => $name,
						'properties' => array( 'label' => 'x' ),
					),
				),
			),
			'removed earlier in the same call'
		);
		$this->assertSame( array( 'Your name', 'Your email', 'Topic' ), self::labels( ( new WpformsEditor() )->form( $id ) ), 'A refused call changes nothing.' );

		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-wpforms-form-edit',
			array(
				'form_id' => $id,
				'form'    => array( 'title' => 'x' ),
			),
			'authors may not edit WPForms forms'
		);
	}

	public function test_fb1_notification_routing_confirm_first(): void {
		$id   = (int) $this->ok( 'kuduy-wpforms-form-create', array( 'title' => 'Kuduy WPForms mail' ) )['form']['id'];
		$list = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'wpforms',
				'form_id' => $id,
			)
		)['notifications'];
		$nid  = (string) array_key_first( (array) wpforms()->obj( 'form' )->get( $id, array( 'content_only' => true ) )['settings']['notifications'] );

		$preview = $this->ok(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'to'      => 'team@example.test',
			)
		);
		$this->assertFalse( $preview['done'] );
		$this->assertSame( 'team@example.test', $preview['routing']['to']['new'] );
		$this->assertSame( '{admin_email}', wpforms()->obj( 'form' )->get( $id, array( 'content_only' => true ) )['settings']['notifications'][ $nid ]['email'], 'Nothing changed before confirm.' );

		$done = $this->ok(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'to'      => 'team@example.test',
				'subject' => 'New lead',
				'confirm' => true,
			)
		);
		$this->assertTrue( $done['done'] );
		$this->assertSame( 'team@example.test', $done['notification']['to'] );
		$this->assertSame( 'New lead', $done['notification']['subject'] );

		$subject = $this->ok(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'subject' => 'Changed subject',
			)
		);
		$this->assertTrue( $subject['done'], 'A subject change needs no confirmation.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $subject['_snapshot_id'] ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( '{admin_email}', wpforms()->obj( 'form' )->get( $id, array( 'content_only' => true ) )['settings']['notifications'][ $nid ]['email'], 'Undo puts the recipient back.' );

		$this->fails(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'bcc'     => 'x@example.test',
				'confirm' => true,
			),
			'WPForms has no Bcc'
		);
		$this->fails(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'to'      => 'x@example.test',
				'subject' => 's',
				'confirm' => true,
			),
			'Lite sends one notification'
		);
		$this->assertNotEmpty( $list );

		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails(
			'kuduy-wpforms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'subject' => 'x',
			),
			'routing needs a site manager'
		);
	}
}
