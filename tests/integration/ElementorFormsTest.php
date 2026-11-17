<?php
/**
 * Elementor Pro forms (phase Q5, test matrix EF1–EF3): form widgets read from page data, submissions through Pro's
 * submissions store. Runs with the stand-in for that store (KUDUY_FAKES=elementor-forms); skips otherwise.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorFormsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $page;

	private static int $sub;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
			self::markTestSkipped( 'Elementor and the Elementor Pro forms stand-in are not loaded in this run (KUDUY_FAKES=elementor-forms).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$page   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy contact',
				'post_status' => 'publish',
				'post_author' => self::$admin,
			)
		);
		$data         = array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'f00d123',
						'elType'     => 'widget',
						'widgetType' => 'form',
						'settings'   => array(
							'form_name'      => 'Contact us',
							'form_fields'    => array(
								array(
									'custom_id'   => 'name',
									'field_type'  => 'text',
									'field_label' => 'Your name',
									'required'    => 'true',
								),
								array(
									'custom_id'   => 'email',
									'field_type'  => 'email',
									'field_label' => 'Email',
									'required'    => 'true',
								),
								array(
									'custom_id'     => 'topic',
									'field_type'    => 'select',
									'field_label'   => 'Topic',
									'field_options' => "Sales\nSupport",
								),
								array(
									'custom_id'   => 'message',
									'field_type'  => 'textarea',
									'field_label' => 'Message',
								),
							),
							'submit_actions' => array( 'save-to-database', 'email' ),
							'email_to'       => 'owner@example.com',
							'email_subject'  => 'New message',
						),
						'elements'   => array(),
					),
				),
			),
		);
		update_post_meta( self::$page, '_elementor_edit_mode', 'builder' );
		update_post_meta( self::$page, '_elementor_data', wp_slash( (string) wp_json_encode( $data ) ) );
		$fields    = array(
			array(
				'id'    => 'name',
				'type'  => 'text',
				'label' => 'Your name',
			),
			array(
				'id'    => 'email',
				'type'  => 'email',
				'label' => 'Email',
			),
			array(
				'id'    => 'message',
				'type'  => 'textarea',
				'label' => 'Message',
			),
		);
		$query     = '\ElementorPro\Modules\Forms\Submissions\Database\Query';
		self::$sub = $query::add(
			array(
				'post_id'    => self::$page,
				'element_id' => 'f00d123',
				'form_name'  => 'Contact us',
				'fields'     => $fields,
				'values'     => array(
					'name'    => 'Ada Visitor',
					'email'   => 'ada@example.com',
					'message' => 'Please call me back',
				),
				'ip'         => '203.0.113.5',
				'agent'      => 'Test browser',
			)
		);
		$query::add(
			array(
				'post_id'    => self::$page,
				'element_id' => 'f00d123',
				'form_name'  => 'Contact us',
				'fields'     => $fields,
				'values'     => array(
					'name'    => 'Bo Other',
					'email'   => 'bo@example.com',
					'message' => 'Second message',
				),
				'ip'         => '203.0.113.6',
				'agent'      => 'Test browser',
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_ef1_forms_listed_from_page_data(): void {
		$forms = array_column( $this->ok( 'kuduy-elementor-forms' )['forms'], null, 'form' );
		$key   = self::$page . '_f00d123';
		$this->assertArrayHasKey( $key, $forms );
		$form = $forms[ $key ];
		$this->assertSame( 'Contact us', $form['form_name'] );
		$this->assertSame( array( 'name', 'email', 'topic', 'message' ), array_column( $form['fields'], 'id' ) );
		$this->assertSame( array( 'Sales', 'Support' ), $form['fields'][2]['options'] );
		$this->assertTrue( $form['fields'][1]['required'] );
		$this->assertSame( array( 'save-to-database', 'email' ), $form['actions'] );
		$this->assertSame( 2, $form['submissions'] );
		$this->assertSame( array(), $this->ok( 'kuduy-elementor-forms', array( 'search' => 'no such form' ) )['forms'] );

		wp_set_current_user( self::$editor );
		$this->assertNull( array_column( $this->ok( 'kuduy-elementor-forms' )['forms'], null, 'form' )[ $key ]['submissions'], 'Editors see forms, not submission counts.' );
	}

	public function test_ef2_submissions_masked_and_read(): void {
		$key  = self::$page . '_f00d123';
		$list = $this->ok( 'kuduy-elementor-form-submissions', array( 'form' => $key ) );
		$this->assertSame( 2, $list['total'] );
		$first = $list['submissions'][1];
		$this->assertSame( self::$sub, $first['id'] );
		$byname = array_column( $first['fields'], null, 'name' );
		$this->assertSame( '[personal data]', $byname['email']['value'] );
		$this->assertSame( '[personal data]', $byname['name']['value'], 'Name fields are found by their id and label.' );
		$this->assertSame( 'Please call me back', $byname['message']['value'] );
		$this->assertSame( '[personal data]', $first['meta']['ip'] );
		$this->assertSame( 'Email', $byname['email']['label'] );

		$full = $this->ok(
			'kuduy-elementor-form-submission-get',
			array(
				'id'          => self::$sub,
				'include_pii' => true,
			)
		);
		$this->assertSame( 'ada@example.com', array_column( $full['fields'], 'value', 'name' )['email'] );
		$this->assertSame( 'email', $full['log'][0]['action'] );
		$this->assertSame( 1, $this->ok( 'kuduy-elementor-form-submissions', array( 'search' => 'call me' ) )['total'] );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-elementor-form-submissions', array(), 'submissions need a site manager, as in Pro' );
	}

	public function test_ef3_mark_read_trash_and_undo(): void {
		$read = $this->ok(
			'kuduy-elementor-form-submission-update',
			array(
				'id'   => self::$sub,
				'read' => true,
			)
		);
		$this->assertTrue( $read['read'] );
		$this->assertSame( 1, $this->ok( 'kuduy-elementor-form-submissions', array( 'status' => 'read' ) )['total'] );
		$trash = $this->ok(
			'kuduy-elementor-form-submission-update',
			array(
				'id'     => self::$sub,
				'status' => 'trash',
			)
		);
		$this->assertSame( 'trash', $trash['status'] );
		$this->assertSame( 1, $this->ok( 'kuduy-elementor-form-submissions', array( 'status' => 'trash' ) )['total'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$this->assertSame( 'active', $this->ok( 'kuduy-elementor-form-submission-get', array( 'id' => self::$sub ) )['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $read['_snapshot_id'] ) );
		$this->assertFalse( $this->ok( 'kuduy-elementor-form-submission-get', array( 'id' => self::$sub ) )['read'] );
		$this->fails( 'kuduy-elementor-form-submission-update', array( 'id' => self::$sub ), 'nothing to change' );
		$this->fails(
			'kuduy-elementor-form-submission-update',
			array(
				'id'   => 99999,
				'read' => true,
			),
			'unknown submission'
		);
	}
}
