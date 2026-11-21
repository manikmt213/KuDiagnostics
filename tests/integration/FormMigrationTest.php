<?php
/**
 * Form migration (test matrix FM1–FM5, phase B3, D83): canonical export, the plan that changes nothing, migration to a
 * new form through the target plugin's own save path, the check of the saved form, permissions. Runs in the forms rows:
 * Contact Form 7 + Fluent Forms + WPForms Lite (FM1–FM4), Ninja Forms + Formidable (FM5). Each test skips when its
 * plugins are not active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormMigrationTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	private const CF7_MARKUP = '<label> Your name [text* your-name placeholder "Jane Doe"] </label>
<label> Your email [email* your-email] </label>
<label> Website [url your-site] </label>
<label> Topic [select* topic "Sales" "Support|support"] </label>
[acceptance terms] I agree to the terms. [/acceptance]
<label> Question [quiz human "1+1?|2"] </label>
[submit "Send now"]';

	public static function setUpBeforeClass(): void {
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
	 * Skip unless these plugins are active.
	 *
	 * @param list<string> $plugins cf7 | fluent | wpforms | ninja | formidable.
	 */
	private function needs( array $plugins ): void {
		$active = array(
			'cf7'        => class_exists( 'WPCF7_ContactForm' ),
			'fluent'     => function_exists( 'fluentFormApi' ),
			'wpforms'    => function_exists( 'wpforms' ),
			'ninja'      => function_exists( 'Ninja_Forms' ),
			'formidable' => class_exists( 'FrmForm' ),
		);
		foreach ( $plugins as $plugin ) {
			if ( empty( $active[ $plugin ] ) ) {
				$this->markTestSkipped( $plugin . ' is not active in this run (KUDUY_PLUGINS).' );
			}
		}
	}

	/**
	 * A CF7 form with the test markup.
	 */
	private function cf7_form(): int {
		return (int) $this->ok(
			'kuduy-forms-cf7-save',
			array(
				'title'    => 'Kuduy migration source',
				'form'     => self::CF7_MARKUP,
				'messages' => array( 'mail_sent_ok' => 'Thanks, we got it.' ),
			)
		)['id'];
	}

	public function test_fm1_cf7_export(): void {
		$this->needs( array( 'cf7' ) );
		$id     = $this->cf7_form();
		$model  = $this->ok(
			'kuduy-forms-export',
			array(
				'plugin'  => 'cf7',
				'form_id' => $id,
			)
		);
		$fields = array_column( $model['fields'], null, 'key' );
		$this->assertSame( array( 'your-name', 'your-email', 'your-site', 'topic', 'terms' ), array_keys( $fields ) );
		$this->assertSame( 'text', $fields['your-name']['type'] );
		$this->assertSame( 'Your name', $fields['your-name']['label'] );
		$this->assertTrue( $fields['your-name']['required'] );
		$this->assertSame( 'Jane Doe', $fields['your-name']['placeholder'] );
		$this->assertSame( 'url', $fields['your-site']['type'] );
		$this->assertFalse( $fields['your-site']['required'] );
		$this->assertSame(
			array(
				array(
					'label' => 'Sales',
					'value' => 'Sales',
				),
				array(
					'label' => 'Support',
					'value' => 'support',
				),
			),
			$fields['topic']['choices']
		);
		$this->assertSame( 'acceptance', $fields['terms']['type'] );
		$this->assertSame( 'I agree to the terms.', $fields['terms']['label'] );
		$this->assertTrue( $fields['terms']['required'] );
		$this->assertSame( array( 'human' ), array_column( $model['skipped'], 'id' ), 'the quiz has no common type' );
		$this->assertSame( 'Send now', $model['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $model['confirmation_message'] );
		$this->assertSame( 1, $model['notifications'] );
	}

	public function test_fm2_cf7_to_fluent_plan_then_migrate_and_verify(): void {
		$this->needs( array( 'cf7', 'fluent' ) );
		$id     = $this->cf7_form();
		$before = (string) wpcf7_contact_form( $id )->prop( 'form' );
		$args   = array(
			'plugin'  => 'cf7',
			'form_id' => $id,
			'to'      => 'fluent-forms',
		);
		$count  = static fn(): int => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}fluentform_forms" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test count of Fluent's forms.
		$forms  = $count();

		$plan = $this->ok( 'kuduy-forms-migrate', $args );
		$this->assertTrue( $plan['confirmation_required'] );
		$this->assertSame( $forms, $count(), 'the plan creates nothing' );
		$rows = array_column( $plan['plan']['fields'], null, 'field' );
		$this->assertSame( 'input_text', $rows['your-name']['to'] );
		$this->assertSame( 'input_email', $rows['your-email']['to'] );
		$this->assertSame( 'input_url', $rows['your-site']['to'] );
		$this->assertSame( 'select', $rows['topic']['to'] );
		$this->assertSame( 'gdpr_agreement', $rows['terms']['to'] );
		$this->assertContains( 'human', array_column( $plan['plan']['not_carried'], 'id' ) );

		$done = $this->ok( 'kuduy-forms-migrate', $args + array( 'confirm' => true ) );
		$this->assertSame( $forms + 1, $count() );
		$this->assertTrue( $done['verify']['ok'], (string) wp_json_encode( $done['verify'] ) );
		$new  = (int) $done['created']['form_id'];
		$form = $this->ok(
			'kuduy-forms-export',
			array(
				'plugin'  => 'fluent-forms',
				'form_id' => $new,
			)
		);
		$this->assertSame( 'Kuduy migration source', $form['title'] );
		$keys = array_column( $form['fields'], 'key' );
		$this->assertSame( array( 'your_name', 'your_email', 'your_site', 'topic', 'terms' ), $keys, 'CF7 names kept as Fluent names' );
		$this->assertSame( 'Jane Doe', $form['fields'][0]['placeholder'] );
		$this->assertSame( 'Send now', $form['submit_text'] );
		$this->assertSame( 'Thanks, we got it.', $form['confirmation_message'] );
		$this->assertSame( $before, (string) wpcf7_contact_form( $id )->prop( 'form' ), 'the old form is unchanged' );
		$this->assertStringContainsString( 'not changed or moved', $done['report']['left_behind'] );
	}

	public function test_fm3_fluent_to_wpforms_lite_reports_fallbacks(): void {
		$this->needs( array( 'cf7', 'fluent', 'wpforms' ) );
		$fluent = (int) $this->ok(
			'kuduy-forms-migrate',
			array(
				'plugin'  => 'cf7',
				'form_id' => $this->cf7_form(),
				'to'      => 'fluent-forms',
				'confirm' => true,
			)
		)['created']['form_id'];
		$args   = array(
			'plugin'  => 'fluent-forms',
			'form_id' => $fluent,
			'to'      => 'wpforms',
		);
		$rows   = array_column( $this->ok( 'kuduy-forms-migrate', $args )['plan']['fields'], null, 'field' );
		$this->assertSame( 'text', $rows['your_site']['to'], 'WPForms Lite has no URL field' );
		$this->assertStringContainsString( 'no URL check', implode( ' ', $rows['your_site']['notes'] ) );
		$this->assertSame( 'checkbox', $rows['terms']['to'], 'no agreement field in WPForms Lite' );

		$done = $this->ok( 'kuduy-forms-migrate', $args + array( 'confirm' => true ) );
		$this->assertTrue( $done['verify']['ok'], (string) wp_json_encode( $done['verify'] ) );
		$this->assertSame( 'wpforms', get_post_type( (int) $done['created']['form_id'] ) );
	}

	public function test_fm4_permissions_and_unknown_forms(): void {
		$this->needs( array( 'cf7', 'fluent' ) );
		$id = $this->cf7_form();
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-forms-export',
			array(
				'plugin'  => 'cf7',
				'form_id' => $id,
			),
			'an author may not edit CF7 forms'
		);
		$this->fails(
			'kuduy-forms-migrate',
			array(
				'plugin'  => 'cf7',
				'form_id' => $id,
				'to'      => 'fluent-forms',
				'confirm' => true,
			),
			'an author may not copy it'
		);
		wp_set_current_user( self::$admin );
		$this->fails(
			'kuduy-forms-export',
			array(
				'plugin'  => 'fluent-forms',
				'form_id' => 999999,
			),
			'unknown form'
		);
		$this->fails(
			'kuduy-forms-migrate',
			array(
				'plugin'  => 'cf7',
				'form_id' => $id,
				'to'      => 'ninja-forms',
			),
			'a target that is not active'
		);
	}

	public function test_fm5_ninja_to_formidable_and_back(): void {
		$this->needs( array( 'ninja', 'formidable' ) );
		// Creates Formidable's tables when the plugin was activated without its admin screens (as FormEditFormidableTest).
		\FrmAppController::install();
		$ninja  = (int) $this->ok(
			'kuduy-ninja-forms-form-create',
			array(
				'title'  => 'Kuduy Ninja source',
				'fields' => array(
					array(
						'type'       => 'textbox',
						'properties' => array(
							'label'       => 'Full name',
							'required'    => true,
							'placeholder' => 'Jane',
						),
					),
					array(
						'type'       => 'email',
						'properties' => array( 'label' => 'Email' ),
					),
					array(
						'type'       => 'listradio',
						'properties' => array(
							'label'   => 'Plan',
							'options' => array(
								array(
									'label' => 'Basic',
									'value' => 'basic',
								),
								array(
									'label' => 'Pro',
									'value' => 'pro',
								),
							),
						),
					),
				),
			)
		)['form']['id'];
		$source = $this->ok(
			'kuduy-forms-export',
			array(
				'plugin'  => 'ninja-forms',
				'form_id' => $ninja,
			)
		);
		$this->assertSame( 'Kuduy Ninja source', $source['title'], (string) wp_json_encode( $source ) );
		$done = $this->ok(
			'kuduy-forms-migrate',
			array(
				'plugin'  => 'ninja-forms',
				'form_id' => $ninja,
				'to'      => 'formidable',
				'confirm' => true,
			)
		);
		$this->assertTrue( $done['verify']['ok'], (string) wp_json_encode( $done['verify'] ) );
		$model = $this->ok(
			'kuduy-forms-export',
			array(
				'plugin'  => 'formidable',
				'form_id' => (int) $done['created']['form_id'],
			)
		);
		$this->assertSame( array( 'text', 'email', 'radio' ), array_column( $model['fields'], 'type' ) );
		$this->assertSame( array( 'Full name', 'Email', 'Plan' ), array_column( $model['fields'], 'label' ) );
		$this->assertSame( array( 'Basic', 'Pro' ), array_column( $model['fields'][2]['choices'], 'label' ) );

		$back = $this->ok(
			'kuduy-forms-migrate',
			array(
				'plugin'  => 'formidable',
				'form_id' => (int) $done['created']['form_id'],
				'to'      => 'ninja-forms',
				'confirm' => true,
			)
		);
		$this->assertTrue( $back['verify']['ok'], (string) wp_json_encode( $back['verify'] ) );
	}
}
