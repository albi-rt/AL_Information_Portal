<?php
/**
 * Reusable rendering / URL helpers.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home URL for the current locale.
 */
function nsw_theme_home_url(): string {
	if ( nsw_theme_has_polylang() ) {
		$home = pll_home_url();
		if ( $home ) {
			return $home;
		}
	}
	return home_url( '/' );
}

/**
 * Map a logical path key (e.g. "about") to a permalink for the current locale.
 *
 * The site uses localized slugs (rreth-nsw / about, agjencite / agencies, …).
 * Cookie-mode swaps slugs based on locale; Polylang owns the mapping itself
 * once installed.
 */
function nsw_theme_path_url( string $key ): string {
	$slug = nsw_theme_path_slug( $key );

	if ( null === $slug ) {
		return home_url( '/' . ltrim( $key, '/' ) );
	}

	if ( nsw_theme_has_polylang() ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$permalink = get_permalink( $page );
			if ( $permalink ) {
				return $permalink;
			}
		}
	}

	return home_url( '/' . trim( $slug, '/' ) . '/' );
}

/**
 * The slug for a logical path key in a locale, or null when the key is unknown.
 *
 * A locale with no entry in the map falls back to the ENGLISH entry, so adding
 * a language never 404s and never needs a code change — only map entries.
 */
function nsw_theme_path_slug( string $key, ?string $locale = null ): ?string {
	$slugs = nsw_theme_path_slugs();
	if ( ! isset( $slugs[ $key ] ) ) {
		return null;
	}
	$locale = $locale ?: nsw_theme_current_locale();
	$map    = (array) $slugs[ $key ];

	if ( isset( $map[ $locale ] ) ) {
		return (string) $map[ $locale ];
	}
	return isset( $map['en'] ) ? (string) $map['en'] : null;
}

/**
 * Slugs per logical key, keyed by locale slug. Mirrors i18n/routing.ts in the
 * Next.js source.
 *
 * Not every locale needs an entry: nsw_theme_path_slug() falls back to 'en'.
 * To localize the paths for a new language, add its slug to each row here.
 */
function nsw_theme_path_slugs(): array {
	return array(
		'home'          => array( 'sq' => '', 'en' => '' ),
		'about'         => array( 'sq' => 'rreth-nsw', 'en' => 'about' ),
		'how-it-works'  => array( 'sq' => 'si-funksionon', 'en' => 'how-it-works' ),
		'agencies'      => array( 'sq' => 'agjencite', 'en' => 'agencies' ),
		'partners'      => array( 'sq' => 'partneret', 'en' => 'partners' ),
		'faq'           => array( 'sq' => 'pyetjet-e-shpeshta', 'en' => 'faq' ),
		'documents'     => array( 'sq' => 'dokumenta', 'en' => 'documents' ),
		'services'      => array( 'sq' => 'sherbime', 'en' => 'services' ),
		'news'          => array( 'sq' => 'lajme', 'en' => 'news' ),
		'events'        => array( 'sq' => 'ngjarje', 'en' => 'events' ),
		'contact'       => array( 'sq' => 'kontakt', 'en' => 'contact' ),
		'support'       => array( 'sq' => 'suporti', 'en' => 'support' ),
	);
}

/**
 * Friendly nav label that prefers the translation map but falls back to
 * a hardcoded English string.
 */
function nsw_theme_nav_label( string $key ): string {
	$defaults = array(
		'home'              => 'Home',
		'about'             => 'About NSW',
		'howItWorks'        => 'How It Works',
		'agencies'          => 'Agencies',
		'partners'          => 'Partners',
		'faq'               => 'FAQ',
		'documents'         => 'Documents',
		'services'          => 'Services',
		'news'              => 'News',
		'events'            => 'Events',
		'contact'           => 'Contact',
		'support'           => 'Support',
		'aboutDropdown'     => 'About Us',
		'resourcesDropdown' => 'Resources',
	);

	$default = $defaults[ $key ] ?? $key;
	return nsw_theme_t( 'nav.' . $key, $default );
}

/**
 * Renders a hero section.
 *
 * @param array{
 *   variant?: string,
 *   title: string,
 *   subtitle?: string,
 *   children?: string
 * } $args
 */
function nsw_theme_render_hero( array $args ): void {
	$variant  = $args['variant'] ?? 'compact';
	$title    = $args['title'] ?? '';
	$subtitle = $args['subtitle'] ?? '';
	$children = $args['children'] ?? '';
	$bg_url   = $args['bg_url'] ?? '';
	$bg_mime  = $args['bg_mime'] ?? '';

	set_query_var( 'nsw_theme_hero_variant', $variant );
	set_query_var( 'nsw_theme_hero_title', $title );
	set_query_var( 'nsw_theme_hero_subtitle', $subtitle );
	set_query_var( 'nsw_theme_hero_children', $children );
	set_query_var( 'nsw_theme_hero_bg_url', $bg_url );
	set_query_var( 'nsw_theme_hero_bg_mime', $bg_mime );

	if ( 'large' === $variant ) {
		get_template_part( 'template-parts/sections/hero', 'large' );
	} else {
		get_template_part( 'template-parts/sections/hero', 'compact' );
	}
}

/**
 * Render a single news card from a query post (called inside the loop).
 */
function nsw_theme_render_news_card( WP_Post $post, string $locale ): void {
	set_query_var( 'nsw_theme_card_post', $post );
	set_query_var( 'nsw_theme_card_locale', $locale );
	get_template_part( 'template-parts/cards/news', 'card' );
}

/**
 * Render an event card from a JSON record.
 *
 * @param array  $event JSON event record.
 * @param string $locale Current locale slug.
 */
function nsw_theme_render_event_card( array $event, string $locale ): void {
	set_query_var( 'nsw_theme_card_event', $event );
	set_query_var( 'nsw_theme_card_locale', $locale );
	get_template_part( 'template-parts/cards/event', 'card' );
}

/**
 * Render a shadcn-style custom dropdown built on a hidden input + ARIA listbox.
 * Looks like Radix Select, fully keyboard-accessible, no native <select> chrome.
 *
 * @param array{
 *   id:          string,
 *   name:        string,
 *   options:     array<string,string>,
 *   placeholder: string,
 *   required?:   bool,
 * } $args
 */
function nsw_theme_render_select( array $args ): void {
	$id          = (string) ( $args['id'] ?? '' );
	$name        = (string) ( $args['name'] ?? '' );
	$options     = (array)  ( $args['options'] ?? array() );
	$placeholder = (string) ( $args['placeholder'] ?? '' );
	$required    = ! empty( $args['required'] );

	if ( '' === $id || '' === $name ) {
		return;
	}
	?>
	<div class="select" data-select>
		<button
			type="button"
			class="select__trigger"
			id="<?php echo esc_attr( $id ); ?>"
			data-select-trigger
			aria-haspopup="listbox"
			aria-expanded="false"
		>
			<span class="select__value" data-select-value data-placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_html( $placeholder ); ?></span>
			<svg class="select__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
		</button>
		<ul class="select__panel" data-select-panel role="listbox" tabindex="-1" aria-label="<?php echo esc_attr( $placeholder ); ?>" hidden>
			<?php foreach ( $options as $value => $label ) : ?>
				<li class="select__option" data-select-option data-value="<?php echo esc_attr( (string) $value ); ?>" role="option" aria-selected="false"><?php echo esc_html( (string) $label ); ?></li>
			<?php endforeach; ?>
		</ul>
		<input
			type="hidden"
			name="<?php echo esc_attr( $name ); ?>"
			data-select-input
			value=""
			<?php echo $required ? ' data-required="true"' : ''; ?>
		/>
	</div>
	<?php
}
