<?php
/**
 * A stand-in for All in One SEO Pro's redirects in tests (licensed copy not in Playground): aioseo() with a redirects
 * module, the Models\Redirect lookup and the REST routes KuDiagnostics calls, with the paths, bodies and responses
 * read on AIOSEO Pro 5.0.5 (app/Pro/Api/Api.php, Api/Redirects.php): duplicate sources answer 409 with "failed";
 * the handler resets group and query parameter when they are not sent; enabling a redirect that lacks its target
 * leaves it off. Backed by one option. Access: manage_options (AIOSEO lets administrators in always). Loaded with
 * KUDUY_FAKES=aioseo-pro; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Files.OneDeclarationPerFile -- test fake.

namespace AIOSEO\Plugin\Pro\Redirects\Models {

	class Redirect {

		public const OPTION = 'kuduy_fake_aioseo_redirects';

		public $id = 0;
		public $source_url = '';
		public $target_url = '';
		public $type = 301;
		public $regex = false;
		public $enabled = true;
		public $group = 'manual';
		public $query_param = 'exact';
		public $post_id = null;
		public $hits = 0;

		public function __construct( array $row = array() ) {
			foreach ( $row as $k => $v ) {
				$this->$k = $v;
			}
		}

		public static function rows(): array {
			$rows = get_option( self::OPTION, array() );
			return is_array( $rows ) ? $rows : array();
		}

		public static function getRedirectById( $redirectId ) {
			$rows = self::rows();
			return new self( $rows[ (int) $redirectId ] ?? array() );
		}

		public static function getRedirectBySourceUrl( $url, $excludeId = null ) {
			foreach ( self::rows() as $id => $row ) {
				if ( $row['source_url'] === $url && (int) $id !== (int) $excludeId ) {
					return new self( $row );
				}
			}
			return new self();
		}

		public function exists() {
			return (int) $this->id > 0;
		}

		public function save() {
			$rows = self::rows();
			if ( ! $this->exists() ) {
				$this->id = $rows ? max( array_keys( $rows ) ) + 1 : 1;
			}
			$rows[ (int) $this->id ] = get_object_vars( $this );
			update_option( self::OPTION, $rows, false );
		}

		public function delete() {
			$rows = self::rows();
			unset( $rows[ (int) $this->id ] );
			update_option( self::OPTION, $rows, false );
		}
	}
}

namespace {

	use AIOSEO\Plugin\Pro\Redirects\Models\Redirect;

	function aioseo() {
		static $main = null;
		if ( null === $main ) {
			$main            = new stdClass();
			$main->redirects = new stdClass();
		}
		return $main;
	}

	function kuduy_fake_aioseo_row( Redirect $r ): array {
		return array(
			'id'          => (int) $r->id,
			'source_url'  => $r->source_url,
			'target_url'  => $r->target_url,
			'type'        => (int) $r->type,
			'query_param' => $r->query_param,
			'regex'       => (bool) $r->regex,
			'enabled'     => (bool) $r->enabled,
			'hits'        => (int) $r->hits,
			'groupName'   => $r->group,
		);
	}

	function kuduy_fake_aioseo_save( WP_REST_Request $request, $redirectId = null ) {
		$body   = (array) $request->get_json_params();
		$urls   = ! empty( $body['sourceUrls'] ) ? $body['sourceUrls'] : array();
		$target = ! empty( $body['targetUrl'] ) ? esc_url_raw( $body['targetUrl'] ) : '';
		$type   = isset( $body['redirectType'] ) ? (int) $body['redirectType'] : 301;
		$needs  = isset( $body['redirectTypeHasTarget'] ) ? (bool) $body['redirectTypeHasTarget'] : true;
		if ( empty( $urls ) || ( $needs && '' === $target ) ) {
			return new WP_REST_Response( array( 'success' => false ), 400 );
		}
		$redirect = null;
		$failed   = array();
		foreach ( $urls as $u ) {
			$url      = empty( $u['regex'] ) ? '/' . ltrim( (string) $u['url'], '/' ) : (string) $u['url'];
			$redirect = $redirectId ? Redirect::getRedirectById( $redirectId ) : Redirect::getRedirectBySourceUrl( $url );
			if ( $redirectId && ! $redirect->exists() ) {
				return new WP_REST_Response( array( 'success' => false ), 404 );
			}
			if ( ( $redirectId && Redirect::getRedirectBySourceUrl( $url, $redirectId )->exists() ) || ( ! $redirectId && $redirect->exists() ) ) {
				$failed[] = $url;
				continue;
			}
			$redirect->source_url  = $url;
			$redirect->target_url  = $target;
			$redirect->type        = $type;
			$redirect->regex       = ! empty( $u['regex'] );
			$redirect->group       = ! empty( $body['group'] ) ? sanitize_text_field( $body['group'] ) : 'manual';
			$redirect->query_param = ! empty( $body['queryParam'] ) ? sanitize_text_field( $body['queryParam'] ) : 'exact';
			$redirect->post_id     = ! empty( $body['postId'] ) ? (int) $body['postId'] : null;
			$redirect->save();
		}
		if ( $failed || null === $redirect ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'failed'  => $failed,
				),
				409
			);
		}
		return new WP_REST_Response(
			array(
				'success'  => true,
				'redirect' => $redirect,
			),
			200
		);
	}

	add_action(
		'rest_api_init',
		static function (): void {
			$can = static fn() => current_user_can( 'manage_options' );
			register_rest_route(
				'aioseo/v1',
				'/redirects/(?P<filter>enabled|disabled|all)',
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) {
						$body = (array) $r->get_json_params();
						$rows = array_map( static fn( $row ) => new Redirect( $row ), array_reverse( Redirect::rows(), true ) );
						$term = (string) ( $body['searchTerm'] ?? '' );
						if ( '' !== $term ) {
							$rows = array_filter( $rows, static fn( $x ) => false !== stripos( $x->source_url . ' ' . $x->target_url, $term ) );
						}
						$total = count( $rows );
						$rows  = array_slice( array_values( $rows ), (int) ( $body['offset'] ?? 0 ), (int) ( $body['limit'] ?? 20 ) );
						return new WP_REST_Response(
							array(
								'rows'   => array_map( 'kuduy_fake_aioseo_row', $rows ),
								'totals' => array( 'total' => $total ),
							)
						);
					},
				)
			);
			register_rest_route(
				'aioseo/v1',
				'/redirects',
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static fn( WP_REST_Request $r ) => kuduy_fake_aioseo_save( $r ),
				)
			);
			register_rest_route(
				'aioseo/v1',
				'/redirects/(?P<id>[\d]+)',
				array(
					array(
						'methods'             => 'POST',
						'permission_callback' => $can,
						'callback'            => static fn( WP_REST_Request $r ) => kuduy_fake_aioseo_save( $r, (int) $r['id'] ),
					),
					array(
						'methods'             => 'DELETE',
						'permission_callback' => $can,
						'callback'            => static function ( WP_REST_Request $r ) {
							$redirect = Redirect::getRedirectById( (int) $r['id'] );
							if ( $redirect->exists() ) {
								$redirect->delete();
							}
							return new WP_REST_Response( array( 'success' => true ), 200 );
						},
					),
				)
			);
			register_rest_route(
				'aioseo/v1',
				'/redirects/bulk/(?P<action>enable|disable|reset-hits)',
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( WP_REST_Request $r ) {
						$body = (array) $r->get_json_params();
						if ( empty( $body['rowIds'] ) ) {
							return new WP_REST_Response( array( 'success' => false ), 400 );
						}
						foreach ( (array) $body['rowIds'] as $id ) {
							$redirect = Redirect::getRedirectById( (int) $id );
							if ( ! $redirect->exists() || ( 'enable' === $r['action'] && ! in_array( (int) $redirect->type, array( 410, 451 ), true ) && '' === $redirect->target_url ) ) {
								continue;
							}
							if ( 'reset-hits' !== $r['action'] ) {
								$redirect->enabled = 'enable' === $r['action'];
								$redirect->save();
							}
						}
						return new WP_REST_Response( array( 'success' => true ), 200 );
					},
				)
			);
		}
	);
}
