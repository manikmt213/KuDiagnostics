<?php
/**
 * Not a stand-in: switches on the real Spectra Blocks' abilities for the test site, as a site owner does in
 * Spectra Blocks > Settings (option `spectra_blocks_enable_abilities` = "enabled", read in Spectra Blocks 1.0.15
 * includes/class-abilities-manager.php before it registers them). Loaded with KUDUY_FAKES=spectra-blocks-abilities.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

add_filter( 'pre_option_spectra_blocks_enable_abilities', static fn() => 'enabled' );
