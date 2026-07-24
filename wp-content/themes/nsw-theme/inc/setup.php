<?php
/**
 * Theme setup: supports, menus, image sizes.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output the theme's favicon in the document <head>. Block (FSE) themes build
 * their <head> via wp_head() and never load header.php, so the favicon has to
 * be hooked here. If a WordPress Site Icon is set, WordPress emits its own
 * tags and we skip ours.
 */
add_action(
	'wp_head',
	function () {
		if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
			return;
		}
		echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( NSW_THEME_URI . 'assets/images/logos/favicon.svg' ) . '" />' . "\n";
	}
);

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'nsw-theme', NSW_THEME_DIR . 'languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'custom-logo', array(
			'height'      => 60,
			'width'       => 185,
			'flex-height' => true,
			'flex-width'  => true,
		) );
		/*
		 * Load the SAME front-end stylesheet into the block/site editor, plus a
		 * small editor-only override file. WordPress reads these files, rewrites
		 * their relative url() (fonts) to absolute, and scopes their selectors to
		 * .editor-styles-wrapper (rewriting :root/html/body to the wrapper) — so
		 * the editor renders from one source of truth and matches the front end.
		 * NOTE: do not @import main.css from editor.css — @import is dropped from
		 * the editor's injected inline styles, which is what broke parity before.
		 */
		add_editor_style(
			array(
				'assets/css/main.css',
				'assets/css/editor.css',
			)
		);


		add_image_size( 'nsw-theme-card', 800, 540, true );
		add_image_size( 'nsw-theme-hero', 1920, 1080, true );
	}
);

add_filter(
	'body_class',
	function ( $classes ) {
		$classes[] = 'nsw-theme';
		$classes[] = 'lang-' . nsw_theme_current_locale();
		return $classes;
	}
);
