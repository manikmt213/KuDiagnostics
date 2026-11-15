<?php
/**
 * Stand-in for the part of Brizy's page model KuDiagnostics reads (Brizy 2.8.26: Brizy_Editor, Brizy_Editor_Entity,
 * Brizy_Editor_Post, Brizy_Editor_User). The real plugin cannot boot on the SQLite test site (its migrations call
 * mysqli_query() on the database handle), so the Brizy tools are tested against this and are not verified against
 * Brizy itself. Loaded as a must-use plugin with KUDUY_FAKES=brizy.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

// Classes in this file exist as soon as it is compiled, so the real plugin is detected by its constant.
if ( defined( 'BRIZY_VERSION' ) ) {
	return;
}

define( 'BRIZY_VERSION', '2.8.26' );
define( 'BRIZY_EDITOR_VERSION', '383-wp' );

class Brizy_Editor {
	public static function get() {
		static $instance = null;
		return $instance ?? ( $instance = new self() );
	}
	public function supported_post_types() {
		return apply_filters( 'brizy_supported_post_types', array( 'page', 'post' ) );
	}
}

class Brizy_Editor_User {
	public static function is_user_allowed() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}
}

class Brizy_Editor_Entity {
	protected $post_id;
	public function __construct( $post_id ) {
		$this->post_id = (int) $post_id;
	}
	public static function isBrizyEnabled( $post ) {
		if ( $post instanceof WP_Post ) {
			$post = $post->ID;
		}
		return (bool) get_post_meta( $post, 'brizy_enabled', true );
	}
}

class Brizy_Editor_Post extends Brizy_Editor_Entity {
	private $editor_data = '';
	private $editor_version = '';
	public static function get( $apost, $uid = null ) {
		$post = new self( $apost instanceof WP_Post ? $apost->ID : $apost );
		$stored = get_post_meta( $post->post_id, 'brizy', true );
		if ( is_array( $stored ) ) {
			$post->editor_data    = (string) ( $stored['brizy-post']['editor_data'] ?? '' );
			$post->editor_version = (string) ( $stored['brizy-post']['editor_version'] ?? '' );
		}
		return $post;
	}
	public function enable_editor() {
		update_post_meta( $this->post_id, 'brizy_enabled', 1 );
		return $this;
	}
	public function set_editor_data( $content ) {
		$this->editor_data = false !== base64_decode( (string) $content, true ) ? (string) $content : base64_encode( (string) $content );
		return $this;
	}
	public function get_editor_data( $decode = false ) {
		$decoded = $decode ? base64_decode( $this->editor_data, true ) : false;
		return false !== $decoded ? $decoded : $this->editor_data;
	}
	public function set_editor_version( $editor_version ) {
		$this->editor_version = (string) $editor_version;
		return $this;
	}
	public function save( $autosave = 0 ) {
		update_post_meta(
			$this->post_id,
			'brizy',
			array(
				'brizy-post' => array(
					'editor_data'    => $this->editor_data,
					'editor_version' => $this->editor_version,
				),
			)
		);
		return true;
	}
	public function isCompiledWithCurrentVersion() {
		return false;
	}
	public function edit_url() {
		return admin_url( 'admin-post.php?action=_brizy_admin_editor_enable&post=' . $this->post_id );
	}
}
