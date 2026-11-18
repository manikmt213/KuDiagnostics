<?php
/**
 * A stand-in for WPML in tests (licensed copy not in Playground): the documented hooks KuDiagnostics calls, with the
 * names, arguments and return shapes read on WPML 5.3.0 (sitepress.class.php): wpml_active_languages,
 * wpml_default_language, wpml_is_translated_post_type / _taxonomy, wpml_element_trid, wpml_get_element_translations
 * (language => object with element_id), the action wpml_set_element_language_details (a null trid starts a new
 * group) and SitePress::delete_element_translation( $trid, $element_type, $language_code ). Element types
 * "post_{type}" / "tax_{taxonomy}". Languages en (default), de, fr; posts, pages and categories are translated.
 * Backed by one option. Loaded with KUDUY_FAKES=wpml; checks KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, WordPress.NamingConventions -- test fake.

define( 'ICL_SITEPRESS_VERSION', '5.3.0-fake' );

final class Kuduy_Fake_Wpml {

	public const OPTION = 'kuduy_fake_wpml';

	public static function data(): array {
		$data = get_option( self::OPTION, array() );
		return ( is_array( $data ) ? $data : array() ) + array(
			'rows' => array(),
			'next' => 1,
		);
	}

	public static function save( array $data ): void {
		update_option( self::OPTION, $data, false );
	}

	public function delete_element_translation( $trid, $element_type, $language_code = false, $orphan_translation_only = false ) {
		$data = self::data();
		foreach ( $data['rows'] as $key => $row ) {
			if ( (int) $row['trid'] === (int) $trid && str_starts_with( $key, $element_type . '|' ) && ( ! $language_code || $row['lang'] === $language_code ) ) {
				unset( $data['rows'][ $key ] );
			}
		}
		self::save( $data );
		return true;
	}
}

$GLOBALS['sitepress'] = new Kuduy_Fake_Wpml();

add_filter(
	'wpml_active_languages',
	static function () {
		$out = array();
		foreach ( array(
			'en' => array( 'English', 'en_US' ),
			'de' => array( 'Deutsch', 'de_DE' ),
			'fr' => array( 'Français', 'fr_FR' ),
		) as $code => [ $name, $locale ] ) {
			$out[ $code ] = array(
				'code'            => $code,
				'native_name'     => $name,
				'translated_name' => $name,
				'default_locale'  => $locale,
			);
		}
		return $out;
	},
	10,
	2
);
add_filter( 'wpml_default_language', static fn() => 'en' );
add_filter( 'wpml_is_translated_post_type', static fn( $value, $type ) => in_array( $type, array( 'post', 'page' ), true ), 10, 2 );
add_filter( 'wpml_is_translated_taxonomy', static fn( $value, $tax ) => 'category' === $tax, 10, 2 );
add_filter(
	'wpml_element_trid',
	static function ( $empty, $element_id, $element_type = 'post_post' ) {
		$row = Kuduy_Fake_Wpml::data()['rows'][ $element_type . '|' . (int) $element_id ] ?? null;
		return $row ? (int) $row['trid'] : null;
	},
	10,
	3
);
add_filter(
	'wpml_get_element_translations',
	static function ( $value, $trid, $el_type = 'post_post' ) {
		$out = array();
		foreach ( Kuduy_Fake_Wpml::data()['rows'] as $key => $row ) {
			[ $type, $id ] = explode( '|', $key );
			if ( $type === $el_type && (int) $row['trid'] === (int) $trid ) {
				$out[ $row['lang'] ] = (object) array(
					'element_id'           => (int) $id,
					'trid'                 => (int) $row['trid'],
					'language_code'        => $row['lang'],
					'source_language_code' => $row['source'],
					'original'             => null === $row['source'],
				);
			}
		}
		return $out;
	},
	10,
	6
);
add_action(
	'wpml_set_element_language_details',
	static function ( $args ) {
		$data = Kuduy_Fake_Wpml::data();
		$trid = $args['trid'] ? (int) $args['trid'] : $data['next']++;
		$data['rows'][ ( $args['element_type'] ?? 'post_post' ) . '|' . (int) $args['element_id'] ] = array(
			'trid'   => $trid,
			'lang'   => (string) $args['language_code'],
			'source' => $args['source_language_code'] ?? null,
		);
		Kuduy_Fake_Wpml::save( $data );
	}
);
