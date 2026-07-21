<?php
/**
 * Front-end asset enqueue.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style(
			'nsw-theme-main',
			NSW_THEME_URI . 'assets/css/main.css',
			array(),
			NSW_THEME_VERSION
		);

		wp_enqueue_script(
			'nsw-theme-main',
			NSW_THEME_URI . 'assets/js/main.js',
			array(),
			NSW_THEME_VERSION,
			true
		);

		wp_localize_script(
			'nsw-theme-main',
			'NSW_THEME',
			array(
				'restUrl' => esc_url_raw( rest_url( 'nsw-theme/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => nsw_theme_current_locale(),
				'i18n'    => array(
					'submitting' => __( 'Submitting…', 'nsw-theme' ),
					'sent'       => __( 'Message sent. We will get back to you shortly.', 'nsw-theme' ),
					'error'      => __( 'Something went wrong. Please try again.', 'nsw-theme' ),
					'required'   => __( 'Please fill out all required fields.', 'nsw-theme' ),
				),
			)
		);
	}
);

/**
 * Editor: give the theme's dynamic blocks a live ServerSideRender preview.
 */
add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_enqueue_script(
			'nsw-theme-editor-preview',
			NSW_THEME_URI . 'assets/js/editor-preview.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-dom-ready' ),
			NSW_THEME_VERSION,
			true
		);

		// Localize the editable-field config so the editor can build the sidebar
		// controls. Titles mirror the block registrations in inc/blocks.php.
		$titles = array(
			'nsw-theme/hero-home'        => 'NSW Home Hero',
			'nsw-theme/stats'            => 'NSW Stats',
			'nsw-theme/what-is-nsw'      => 'NSW What is NSW',
			'nsw-theme/agencies-preview' => 'NSW Agencies Preview',
			'nsw-theme/news-latest'      => 'NSW Latest News',
			'nsw-theme/partners'         => 'NSW Partners',
			'nsw-theme/cta'              => 'NSW Call to Action',
		);
		// Localize the field placeholders (defaults) to the language of the post
		// being edited, so the sidebar hints match the block preview (which
		// renders in that language). Falls back to the site's default language
		// (e.g. in the Site Editor, where there's no single post).
		$edit_lang = null;
		if ( function_exists( 'pll_get_post_language' ) ) {
			$pid = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
			if ( $pid ) {
				$slug = pll_get_post_language( $pid, 'slug' );
				if ( $slug ) {
					$edit_lang = ( 'en' === $slug ) ? 'en' : 'sq';
				}
			}
		}
		if ( null === $edit_lang ) {
			$edit_lang = ( function_exists( 'pll_default_language' ) && 'en' === pll_default_language( 'slug' ) ) ? 'en' : 'sq';
		}

		// Fetch the field config with English-source defaults (force locale to en
		// so nsw_theme_t() returns the source), then translate each placeholder
		// to the edited post's language.
		$prev_preview = nsw_theme_get_preview_locale();
		nsw_theme_set_preview_locale( 'en' );
		$all_fields = nsw_theme_block_fields();
		nsw_theme_set_preview_locale( $prev_preview );

		$config = array();
		foreach ( $all_fields as $name => $fields ) {
			foreach ( $fields as $i => $f ) {
				if ( ! empty( $f['default'] ) && is_string( $f['default'] ) && function_exists( 'pll_translate_string' ) ) {
					$fields[ $i ]['default'] = (string) pll_translate_string( $f['default'], $edit_lang );
				}
			}
			$config[ $name ] = array(
				'title'  => $titles[ $name ] ?? $name,
				'fields' => $fields,
			);
		}
		wp_add_inline_script(
			'nsw-theme-editor-preview',
			'window.NSW_BLOCKS = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
);
