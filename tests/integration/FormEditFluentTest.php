<?php
/**
 * Form editing, Fluent Forms (test matrix FB2, phase B2, D57): field types from Fluent's default elements; a form
 * created with fields built by Fluent's FormCreator; fields added, changed, moved and removed and the form settings
 * changed through FormService::update() and SettingsService; Fluent's duplicate-name check; removal refused while an
 * entry holds a value; undo; notifications through SettingsService with confirm-first routing. Runs only when Fluent
 * Forms is active (KUDUY_PLUGINS=fluentform@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Forms\Editing\FluentEditor;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormEditFluentTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'fluentFormApi' ) ) {
			self::markTestSkipped( 'Fluent Forms is not active in this run (KUDUY_PLUGINS).' );
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

	/**
	 * Store an entry value the way Fluent's entry details table holds it.
	 *
	 * @param int    $form_id Form id.
	 * @param string $name    Field name.
	 * @param string $value   Value.
	 */
	private static function entry( int $form_id, string $name, string $value ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test data in Fluent's own tables, as a submission would leave it.
			$wpdb->prefix . 'fluentform_submissions',
			array(
				'form_id'    => $form_id,
				'response'   => wp_json_encode( array( $name => $value ) ),
				'status'     => 'unread',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test data in Fluent's own tables, as a submission would leave it.
			$wpdb->prefix . 'fluentform_entry_details',
			array(
				'form_id'       => $form_id,
				'submission_id' => (int) $wpdb->insert_id,
				'field_name'    => $name,
				'field_value'   => $value,
			)
		);
	}

	public function test_fb2_types_create_edit_undo(): void {
		$types = array_column( $this->ok( 'kuduy-fluent-forms-field-types' )['types'], null, 'type' );
		$this->assertArrayHasKey( 'input_email', $types );
		$this->assertContains( 'choices', $types['select']['properties'] );
		$this->assertContains( 'required', $types['input_text']['properties'] );

		$made = $this->ok(
			'kuduy-fluent-forms-form-create',
			array(
				'title'  => 'Kuduy Fluent edit',
				'fields' => array(
					array(
						'type'       => 'input_email',
						'properties' => array(
							'label'    => 'Your email',
							'required' => true,
						),
					),
					array(
						'type'       => 'input_text',
						'properties' => array( 'label' => 'Company' ),
					),
					array(
						'type'       => 'select',
						'properties' => array(
							'label'   => 'Topic',
							'choices' => array(
								'Sales',
								array(
									'label' => 'Support',
									'value' => 'support',
								),
							),
						),
					),
				),
			)
		);
		$form = $made['form'];
		$id   = (int) $form['id'];
		$this->assertSame( 'Kuduy Fluent edit', $form['title'] );
		$this->assertSame( array( 'Your email', 'Company', 'Topic' ), self::labels( $form ) );
		$this->assertTrue( $form['fields'][0]['required'] );
		$this->assertSame( 'support', $form['fields'][2]['choices'][1]['value'] );
		list( $email, $company, $topic ) = array_column( $form['fields'], 'id' );
		$this->assertSame( 'company', $company, 'Storage name from the label.' );

		$edited = $this->ok(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'add',
						'type'       => 'input_text',
						'properties' => array( 'label' => 'Company' ),
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
						'field' => $topic,
						'index' => 0,
					),
					array(
						'op'    => 'remove',
						'field' => $company,
					),
				),
				'form'       => array(
					'title'                => 'Kuduy Fluent edited',
					'submit_text'          => 'Send it',
					'confirmation_message' => 'Thanks, we got it.',
				),
			)
		);
		$after  = $edited['form'];
		$this->assertSame( array( 'company_1' ), $edited['added'], 'A second Company field gets its own storage name.' );
		$this->assertSame( array( 'Topic', 'Company', 'Your email' ), self::labels( $after ) );
		$this->assertFalse( $after['fields'][2]['required'] );
		$this->assertSame( 'you@example.com', $after['fields'][2]['placeholder'] );
		$this->assertSame( 'Kuduy Fluent edited', $after['title'] );
		$this->assertSame( 'Send it', $after['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $after['confirmation_message'] );

		$html = do_shortcode( '[fluentform id="' . $id . '"]' );
		$this->assertStringContainsString( 'company_1', $html, 'Fluent renders the new field.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $edited['_snapshot_id'] ) );
		$back = ( new FluentEditor() )->form( $id );
		$this->assertSame( array( 'Your email', 'Company', 'Topic' ), self::labels( $back ), 'Undo puts the fields back.' );
		$this->assertSame( 'Kuduy Fluent edit', $back['title'] );

		self::entry( $id, $email, 'someone@example.test' );
		$text = $this->fails(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'    => 'remove',
						'field' => $email,
					),
				),
			),
			'an entry holds a value'
		);
		$this->assertStringContainsString( '1 entry holds', $text );
		$this->fails(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'update',
						'field'      => $topic,
						'properties' => array( 'name' => $email ),
					),
				),
			),
			'Fluent refuses duplicate names'
		);
		$this->fails(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'   => 'add',
						'type' => 'no_such_element',
					),
				),
			),
			'unknown type'
		);
		$this->fails(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id'    => $id,
				'operations' => array(
					array(
						'op'         => 'update',
						'field'      => $email,
						'properties' => array( 'onclick' => 'x' ),
					),
				),
			),
			'unknown property'
		);
		$this->assertSame( array( 'Your email', 'Company', 'Topic' ), self::labels( ( new FluentEditor() )->form( $id ) ), 'Refused calls change nothing.' );

		wp_set_current_user( self::make_user( 'author' ) );
		$this->fails(
			'kuduy-fluent-forms-form-edit',
			array(
				'form_id' => $id,
				'form'    => array( 'title' => 'x' ),
			),
			'authors may not manage Fluent forms'
		);
	}

	public function test_fb2_notifications(): void {
		$id      = (int) $this->ok(
			'kuduy-fluent-forms-form-create',
			array(
				'title'  => 'Kuduy Fluent mail',
				'fields' => array( array( 'type' => 'input_email' ) ),
			)
		)['form']['id'];
		$preview = $this->ok(
			'kuduy-fluent-forms-notification-save',
			array(
				'form_id' => $id,
				'to'      => 'team@example.test',
				'subject' => 'New lead',
				'message' => '{all_data}',
			)
		);
		$this->assertFalse( $preview['done'], 'A new notification routes mail: confirm first.' );
		$this->assertSame( array(), array_filter( ( new FluentEditor() )->notifications( $id ), static fn( $n ) => 'team@example.test' === $n['to'] ) );

		$made = $this->ok(
			'kuduy-fluent-forms-notification-save',
			array(
				'form_id' => $id,
				'to'      => 'team@example.test',
				'subject' => 'New lead',
				'message' => '{all_data}',
				'confirm' => true,
			)
		);
		$this->assertTrue( $made['done'] );
		$nid = $made['notification']['id'];
		$this->assertSame( 'team@example.test', $made['notification']['to'] );

		$changed = $this->ok(
			'kuduy-fluent-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'bcc'     => 'archive@example.test',
				'confirm' => true,
			)
		);
		$this->assertSame( 'archive@example.test', $changed['notification']['bcc'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( '', array_column( ( new FluentEditor() )->notifications( $id ), null, 'id' )[ $nid ]['bcc'], 'Undo removes the Bcc.' );

		$this->fails(
			'kuduy-fluent-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => $nid,
				'subject' => '',
				'confirm' => true,
			),
			'Fluent requires a subject'
		);
		$this->fails(
			'kuduy-fluent-forms-notification-save',
			array(
				'form_id' => $id,
				'id'      => '999999',
				'subject' => 'x',
			),
			'no such notification'
		);
	}
}
