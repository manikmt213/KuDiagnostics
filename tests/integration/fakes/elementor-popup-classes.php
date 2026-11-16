<?php
/**
 * Classes of the popup stand-in (see elementor-popup.php); loaded once Elementor is up.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

namespace KuduyFake\Popup;

use Elementor\Controls_Manager;
use Elementor\Controls_Stack;
use Elementor\Modules\Library\Documents\Library_Document;

/**
 * Like Pro's DisplaySettings\Base: each group is a switcher named after the group, its settings are prefixed with it.
 */
abstract class Base extends Controls_Stack {

	private $group = '';

	protected function group( string $name ): void {
		$this->group = $name;
		$this->add_control( $name . '_heading', array( 'type' => Controls_Manager::HEADING, 'label' => $name ) );
	}

	protected function end_group(): void {
		$this->add_control( $this->group, array( 'type' => Controls_Manager::SWITCHER, 'frontend_available' => true ) );
		$this->group = '';
	}

	protected function setting( string $id, array $args ): void {
		$args['condition'] = array( $this->group => 'yes' );
		$this->add_control( $this->group . '_' . $id, $args );
	}
}

final class Triggers extends Base {

	public function get_name() {
		return 'popup_triggers';
	}

	protected function register_controls() {
		$this->start_controls_section( 'triggers' );
		$this->group( 'page_load' );
		$this->setting( 'delay', array( 'type' => Controls_Manager::NUMBER, 'default' => 0, 'min' => 0, 'step' => 0.1 ) );
		$this->end_group();
		$this->group( 'scrolling' );
		$this->setting( 'direction', array( 'type' => Controls_Manager::SELECT, 'default' => 'down', 'options' => array( 'down' => 'Down', 'up' => 'Up' ) ) );
		$this->setting( 'offset', array( 'type' => Controls_Manager::NUMBER, 'default' => 50, 'min' => 1, 'max' => 100 ) );
		$this->end_group();
		$this->group( 'scrolling_to' );
		$this->setting( 'selector', array( 'type' => Controls_Manager::TEXT ) );
		$this->end_group();
		$this->group( 'exit_intent' );
		$this->end_group();
		$this->end_controls_section();
	}
}

final class Timing extends Base {

	public function get_name() {
		return 'popup_timing';
	}

	protected function register_controls() {
		$this->start_controls_section( 'timing' );
		$this->group( 'times' );
		$this->setting( 'times', array( 'type' => Controls_Manager::NUMBER, 'default' => 3, 'min' => 1 ) );
		$this->setting( 'period', array( 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => array( '' => 'Persisting', 'session' => 'Session', 'day' => 'Day' ) ) );
		$this->end_group();
		$this->group( 'devices' );
		$this->setting( 'devices', array( 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'default' => array( 'desktop', 'tablet', 'mobile' ), 'options' => array( 'desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile' ) ) );
		$this->end_group();
		$this->group( 'schedule' );
		$this->setting( 'start_date', array( 'type' => Controls_Manager::DATE_TIME ) );
		$this->setting( 'server_datetime', array( 'type' => Controls_Manager::HIDDEN, 'default' => '2026-11-16 00:00:00' ) );
		$this->end_group();
		$this->end_controls_section();
	}
}

final class Document extends Library_Document {

	const DISPLAY_SETTINGS_META_KEY = '_elementor_popup_display_settings';

	private $display_settings;

	public static function get_type() {
		return 'popup';
	}

	public function get_name() {
		return 'popup';
	}

	public static function get_title() {
		return 'Popup';
	}

	public function get_display_settings() {
		if ( ! $this->display_settings ) {
			$settings = $this->get_display_settings_data();
			if ( ! $settings ) {
				$settings = array( 'triggers' => array(), 'timing' => array() );
			}
			$id                     = $this->get_main_id();
			$this->display_settings = array(
				'triggers' => new Triggers( array( 'id' => $id, 'settings' => $settings['triggers'] ) ),
				'timing'   => new Timing( array( 'id' => $id, 'settings' => $settings['timing'] ) ),
			);
		}
		return $this->display_settings;
	}

	public function get_display_settings_data() {
		return $this->get_main_meta( self::DISPLAY_SETTINGS_META_KEY );
	}

	public function save_display_settings_data( $display_settings_data ) {
		$this->update_main_meta( self::DISPLAY_SETTINGS_META_KEY, $display_settings_data );
	}
}
