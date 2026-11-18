<?php
/**
 * A stand-in for Yoast SEO Premium's redirects in tests (licensed copy not in Playground): the classes KuDiagnostics
 * calls, with the signatures read on Yoast SEO Premium 28.8 (classes/redirect/): WPSEO_Redirect, WPSEO_Redirect_Formats,
 * WPSEO_Redirect_Manager, WPSEO_Redirect_Validator, backed by one option. Validation as Yoast's: presence and
 * uniqueness errors, a self-redirect error, an unreachable-target warning for targets on example.invalid.
 * Administrators get wpseo_manage_redirects. Loaded with KUDUY_FAKES=yoast-premium; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting -- test fake.

define( 'WPSEO_PREMIUM_VERSION', '28.8-fake' );

class WPSEO_Redirect_Formats {
	public const PLAIN = 'plain';
	public const REGEX = 'regex';
}

class WPSEO_Redirect {

	private $origin;
	private $target;
	private $type;
	private $format;

	public function __construct( $origin, $target = '', $type = 301, $format = WPSEO_Redirect_Formats::PLAIN ) {
		$origin       = (string) $origin;
		$this->origin = WPSEO_Redirect_Formats::PLAIN === $format ? ltrim( (string) preg_replace( '~^' . preg_quote( home_url(), '~' ) . '~', '', $origin ), '/' ) : $origin;
		$this->target = (string) $target;
		$this->type   = (int) $type;
		$this->format = $format;
	}

	public function get_origin() {
		return $this->origin;
	}

	public function get_target() {
		return $this->target;
	}

	public function get_type() {
		return $this->type;
	}

	public function get_format() {
		return $this->format;
	}
}

class WPSEO_Validation_Result {

	public function __construct( private string $message, private string $type ) {}

	public function to_array() {
		return array(
			'type'    => $this->type,
			'message' => $this->message,
			'fields'  => array(),
		);
	}
}

class WPSEO_Redirect_Manager {

	private const OPTION = 'wpseo-premium-redirects-base';

	public function __construct( private $format = WPSEO_Redirect_Formats::PLAIN ) {}

	private static function read(): array {
		return (array) get_option( self::OPTION, array() );
	}

	private static function write( array $rows ): void {
		update_option( self::OPTION, $rows, false );
	}

	private static function key( WPSEO_Redirect $r ): string {
		return $r->get_format() . '|' . $r->get_origin();
	}

	public function get_all_redirects() {
		$out = array();
		foreach ( self::read() as $row ) {
			$out[] = new WPSEO_Redirect( $row['origin'], $row['url'], $row['type'], $row['format'] );
		}
		return $out;
	}

	public function create_redirect( WPSEO_Redirect $redirect ) {
		$rows = self::read();
		if ( isset( $rows[ self::key( $redirect ) ] ) ) {
			return false;
		}
		$rows[ self::key( $redirect ) ] = array(
			'origin' => $redirect->get_origin(),
			'url'    => $redirect->get_target(),
			'type'   => $redirect->get_type(),
			'format' => $redirect->get_format(),
		);
		self::write( $rows );
		return true;
	}

	public function update_redirect( WPSEO_Redirect $current_redirect, WPSEO_Redirect $redirect ) {
		$rows = self::read();
		if ( ! isset( $rows[ self::key( $current_redirect ) ] ) ) {
			return false;
		}
		unset( $rows[ self::key( $current_redirect ) ] );
		self::write( $rows );
		return $this->create_redirect( $redirect );
	}

	public function delete_redirects( $delete_redirects ) {
		$rows = self::read();
		$done = false;
		foreach ( $delete_redirects as $r ) {
			if ( isset( $rows[ self::key( $r ) ] ) ) {
				unset( $rows[ self::key( $r ) ] );
				$done = true;
			}
		}
		self::write( $rows );
		return $done;
	}
}

class WPSEO_Redirect_Validator {

	private $error = '';

	public function validate( WPSEO_Redirect $redirect, ?WPSEO_Redirect $current_redirect = null ) {
		$this->error = '';
		if ( '' === $redirect->get_origin() || ( ! in_array( $redirect->get_type(), array( 410, 451 ), true ) && '' === $redirect->get_target() ) ) {
			$this->error = new WPSEO_Validation_Result( 'Not all the required fields are filled.', 'error' );
			return false;
		}
		foreach ( ( new WPSEO_Redirect_Manager() )->get_all_redirects() as $other ) {
			$same = $other->get_origin() === $redirect->get_origin() && $other->get_format() === $redirect->get_format();
			if ( $same && ( null === $current_redirect || $current_redirect->get_origin() !== $other->get_origin() ) ) {
				$this->error = new WPSEO_Validation_Result( 'The old URL already exists as a redirect.', 'error' );
				return false;
			}
		}
		if ( trim( $redirect->get_target(), '/' ) === $redirect->get_origin() ) {
			$this->error = new WPSEO_Validation_Result( 'You are attempting to redirect to the same URL.', 'error' );
			return false;
		}
		if ( false !== strpos( $redirect->get_target(), 'example.invalid' ) ) {
			$this->error = new WPSEO_Validation_Result( 'The URL you are redirecting to seems to return an error.', 'warning' );
			return false;
		}
		return true;
	}

	public function get_error() {
		return $this->error;
	}
}

add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['wpseo_manage_redirects'] = true;
		}
		return $allcaps;
	}
);
