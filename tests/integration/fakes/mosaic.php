<?php
/**
 * Test stand-in for Builder Mosaic 3.6.2 (paid Themify add-on): only the names KuDiagnostics uses, as read in its
 * source on the owner's site — class Builder_Mosaic, Builder_Mosaic_Provider::get_providers() returning the available
 * providers (id => class) and the tile preset post type tftp_template. The real module registers with Themify Builder
 * as "mosaic"; the tests write layouts containing such a module directly.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting -- a stand-in, not plugin code.

class Builder_Mosaic {
	public static function get_version(): string {
		return '3.6.2';
	}
}

class Builder_Mosaic_Provider {
	/**
	 * Providers available on this site (core list of 3.6.2 minus those whose plugin is missing).
	 *
	 * @param string $id Provider id or all.
	 * @return array<string, string>|null
	 */
	public static function get_providers( string $id = 'all' ) {
		$all = array(
			'posts'   => 'Builder_Mosaic_Posts',
			'terms'   => 'Builder_Mosaic_Terms',
			'text'    => 'Builder_Mosaic_Text',
			'gallery' => 'Builder_Mosaic_Gallery',
			'rss'     => 'Builder_Mosaic_RSS',
			'files'   => 'Builder_Mosaic_Files',
		);
		if ( 'all' === $id ) {
			return $all;
		}
		return isset( $all[ $id ] ) ? array( $id => $all[ $id ] ) : null;
	}
}

add_action(
	'init',
	static function (): void {
		register_post_type( 'tftp_template', array( 'public' => false ) );
	}
);
