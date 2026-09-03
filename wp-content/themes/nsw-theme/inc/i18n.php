<?php
/**
 * Multilingual support — Polylang owns all translations.
 *
 * English is the single source (languages/content-en.php). Every UI string is
 * registered with Polylang (Languages → String translations, group "NSW Theme")
 * and translated there — there is no per-language catalog file in the theme.
 * nsw_theme_t() resolves the English source for a key and asks Polylang for the
 * translation in the target locale via pll_translate_string(), which is
 * locale-explicit (correct on the front end and in editor previews alike).
 *
 * The locale set and the default locale are NOT hardcoded: they come from
 * Polylang at runtime (pll_languages_list / pll_current_language /
 * pll_default_language), so the theme works with any set of languages. When
 * Polylang is inactive we fall back to the WordPress site locale.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function nsw_theme_has_polylang(): bool {
	return function_exists( 'pll_current_language' );
}

/**
 * Every locale slug configured in Polylang, in Polylang's own order.
 * Empty array when Polylang is inactive.
 *
 * @return string[]
 */
function nsw_theme_locales(): array {
	if ( ! function_exists( 'pll_languages_list' ) ) {
		return array();
	}
	$list = (array) pll_languages_list( array( 'fields' => 'slug' ) );
	return array_values( array_filter( array_map( 'strval', $list ) ) );
}

/**
 * The WordPress site locale reduced to a language slug (sq_AL → sq).
 * Only used when Polylang can't answer.
 */
function nsw_theme_site_locale_slug(): string {
	$slug = substr( strtolower( (string) determine_locale() ), 0, 2 );
	return '' !== $slug ? $slug : 'en';
}

/**
 * The site's default locale slug, per Polylang.
 */
function nsw_theme_default_locale(): string {
	if ( function_exists( 'pll_default_language' ) ) {
		$slug = (string) pll_default_language( 'slug' );
		if ( '' !== $slug ) {
			return $slug;
		}
	}
	return nsw_theme_site_locale_slug();
}

/**
 * Editor-preview locale override.
 *
 * The block-editor's ServerSideRender previews hit /wp/v2/block-renderer, which
 * Polylang (free) doesn't language-filter — so its previews would render in the
 * default language. We derive the language from the edited post (post_id) and
 * pin it here for that render. Front-end is unaffected (this is only ever set on
 * a block-renderer REST request).
 *
 * Accepts any locale Polylang knows about (plus 'en', which is always valid as
 * the source language — callers pin it to read untranslated source strings).
 */
function nsw_theme_set_preview_locale( ?string $locale ): void {
	$slug  = null !== $locale ? sanitize_key( $locale ) : '';
	$known = nsw_theme_locales();
	$valid = '' !== $slug && ( 'en' === $slug || empty( $known ) || in_array( $slug, $known, true ) );

	$GLOBALS['nsw_theme_preview_locale'] = $valid ? $slug : null;
}
function nsw_theme_get_preview_locale(): ?string {
	return $GLOBALS['nsw_theme_preview_locale'] ?? null;
}

/**
 * The active theme locale — the current Polylang language slug.
 *
 * Order: pinned editor-preview locale → Polylang's current language → the
 * site's default language → the WordPress site locale (no Polylang).
 */
function nsw_theme_current_locale(): string {
	$preview = nsw_theme_get_preview_locale();
	if ( null !== $preview ) {
		return $preview;
	}
	if ( nsw_theme_has_polylang() ) {
		$slug = (string) pll_current_language( 'slug' );
		if ( '' !== $slug ) {
			return $slug;
		}
		// Polylang is active but has no current language (some admin/CLI
		// contexts): the site default is the closest correct answer.
		return nsw_theme_default_locale();
	}
	return nsw_theme_site_locale_slug();
}

/**
 * The declared locale of a single post.
 *
 * Polylang owns per-post language, so read it from there; fall back to the
 * active theme locale when Polylang can't resolve it (e.g. an untranslated
 * legacy post).
 */
function nsw_theme_post_locale( int $post_id ): string {
	if ( function_exists( 'pll_get_post_language' ) ) {
		$slug = (string) pll_get_post_language( $post_id, 'slug' );
		if ( '' !== $slug ) {
			return $slug;
		}
	}
	return nsw_theme_current_locale();
}

/**
 * Get a nested value from a content map using dotted-key syntax.
 */
function nsw_theme_dot_get( array $data, string $path, $default = null ) {
	$cursor = $data;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
			$cursor = $cursor[ $segment ];
		} else {
			return $default;
		}
	}
	return $cursor;
}

/**
 * Resolve a translatable string.
 *
 * $key is either a dotted content key (e.g. "hero.title", resolved to the
 * English source from content-en.php) or a literal; $fallback is the English
 * source when the key isn't in the catalog. The English source is then
 * translated to the current locale by Polylang.
 */
function nsw_theme_t( string $key, string $fallback = '' ): string {
	$en = '';
	if ( false !== strpos( $key, '.' ) ) {
		$v = nsw_theme_dot_get( nsw_theme_get_content( 'en' ), $key, null );
		if ( is_string( $v ) ) {
			$en = $v;
		}
	}
	if ( '' === $en ) {
		$en = '' !== $fallback ? $fallback : $key;
	}
	if ( function_exists( 'pll_translate_string' ) ) {
		return (string) pll_translate_string( $en, nsw_theme_current_locale() );
	}
	return $en;
}

/**
 * Flatten a nested content array into dotted keys, e.g.
 *   [ 'footer' => [ 'quickLinks' => 'Quick Links' ] ] → [ 'footer.quickLinks' => 'Quick Links' ]
 */
function nsw_theme_flatten_strings( array $data, string $prefix = '' ): array {
	$out = array();
	foreach ( $data as $k => $v ) {
		$dot = '' === $prefix ? (string) $k : $prefix . '.' . $k;
		if ( is_array( $v ) ) {
			$out += nsw_theme_flatten_strings( $v, $dot );
		} elseif ( is_string( $v ) && '' !== $v ) {
			$out[ $dot ] = $v;
		}
	}
	return $out;
}

/**
 * Register the theme's UI microcopy with Polylang so every interface string is
 * editable in wp-admin under Languages → String translations (group "NSW
 * Theme"). English source values come from languages/content-en.php.
 */
add_action(
	'init',
	function () {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}
		foreach ( nsw_theme_flatten_strings( (array) nsw_theme_get_content( 'en' ) ) as $name => $value ) {
			pll_register_string( $name, $value, 'NSW Theme', strlen( $value ) > 80 );
		}
	}
);

/**
 * Bridge the theme's gettext strings to Polylang: translate __('…','nsw-theme')
 * through pll_translate_string() so template gettext strings share the same
 * DB-backed translations as the rest of the UI. Theme text domain only,
 * non-English locales only.
 */
add_filter(
	'gettext',
	function ( $translation, $text, $domain ) {
		if ( 'nsw-theme' !== $domain || ! function_exists( 'pll_translate_string' ) ) {
			return $translation;
		}
		$locale = nsw_theme_current_locale();
		if ( 'en' === $locale ) {
			return $translation;
		}
		$t = (string) pll_translate_string( $text, $locale );
		return '' !== $t ? $t : $translation;
	},
	20,
	3
);

/**
 * Editor previews: force the theme locale to match the edited post's language.
 *
 * Runs right before the block-renderer callback (the ServerSideRender request).
 * The request carries the edited post's id; we read its Polylang language and
 * pin the theme locale to it so previews render in the correct language.
 */
add_filter(
	'rest_request_before_callbacks',
	function ( $response, $handler, $request ) {
		if ( ! is_wp_error( $response ) && $request instanceof WP_REST_Request ) {
			$route = (string) $request->get_route();
			if ( 0 === strpos( $route, '/wp/v2/block-renderer/' ) && function_exists( 'pll_get_post_language' ) ) {
				$post_id = (int) $request->get_param( 'post_id' );
				if ( $post_id > 0 ) {
					$slug = (string) pll_get_post_language( $post_id, 'slug' );
					if ( '' !== $slug ) {
						nsw_theme_set_preview_locale( $slug );
					}
				}
			}
		}
		return $response;
	},
	10,
	3
);
