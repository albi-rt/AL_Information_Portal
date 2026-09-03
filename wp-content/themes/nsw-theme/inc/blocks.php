<?php
/**
 * Dynamic blocks that wrap the theme's existing PHP rendering so a block theme
 * (FSE) can compose the site out of blocks without rewriting the bespoke design.
 *
 * Each block's render_callback reuses the existing template-parts / helpers, so
 * there is a single source of truth for the markup.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Buffer a template part and return it. */
function nsw_theme_capture_part( string $slug, ?string $name = null, array $args = array() ): string {
	ob_start();
	get_template_part( $slug, $name, $args );
	return (string) ob_get_clean();
}

/**
 * Editable-field config for the custom NSW blocks.
 *
 * Single source of truth: used to (a) register block attributes, (b) resolve
 * "use the editor value, else the localized default" at render time, and (c)
 * build the editor sidebar (localized to JS). `default` is the current
 * localized value (from the Polylang string table) so the block looks identical
 * until an editor overrides a field, and each language's page keeps its own
 * overrides.
 *
 * Field types: text | textarea | url | image (image stores {attr} url + {attr}Id).
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function nsw_theme_block_fields(): array {
	return array(
		'nsw-theme/hero-home' => array(
			array( 'attr' => 'title',    'label' => 'Title',           'type' => 'text',     'default' => nsw_theme_t( 'hero.title', 'Transforming Albanian Trade' ) ),
			array( 'attr' => 'subtitle', 'label' => 'Subtitle',        'type' => 'textarea', 'default' => nsw_theme_t( 'hero.subtitle', '' ) ),
			array( 'attr' => 'cta1Text', 'label' => 'Button 1 label',  'type' => 'text',     'default' => nsw_theme_t( 'hero.cta1', 'Learn More' ) ),
			array( 'attr' => 'cta1Url',  'label' => 'Button 1 link',   'type' => 'url',      'default' => nsw_theme_path_url( 'about' ) ),
			array( 'attr' => 'cta2Text', 'label' => 'Button 2 label',  'type' => 'text',     'default' => nsw_theme_t( 'hero.cta2', 'Contact Us' ) ),
			array( 'attr' => 'cta2Url',  'label' => 'Button 2 link',   'type' => 'url',      'default' => nsw_theme_path_url( 'contact' ) ),
			array(
				'attr'        => 'bgMedia',
				'label'       => 'Background media (image or video)',
				'type'        => 'media',
				'default'     => nsw_theme_hero_media_default()['url'],
				'defaultId'   => nsw_theme_hero_media_default()['id'],
				'defaultMime' => nsw_theme_hero_media_default()['mime'],
			),
		),
		'nsw-theme/stats' => array(
			array( 'attr' => 'v1', 'label' => 'Stat 1 value',  'type' => 'text', 'default' => '14' ),
			array( 'attr' => 'l1', 'label' => 'Stat 1 label',  'type' => 'text', 'default' => nsw_theme_t( 'stats.agencies', 'Participating Agencies' ) ),
			array( 'attr' => 'v2', 'label' => 'Stat 2 value',  'type' => 'text', 'default' => '72%' ),
			array( 'attr' => 'l2', 'label' => 'Stat 2 label',  'type' => 'text', 'default' => nsw_theme_t( 'stats.costReduction', 'Cost Reduction' ) ),
			array( 'attr' => 'v3', 'label' => 'Stat 3 value',  'type' => 'text', 'default' => '100%' ),
			array( 'attr' => 'l3', 'label' => 'Stat 3 label',  'type' => 'text', 'default' => nsw_theme_t( 'stats.euAlignment', 'EU Alignment' ) ),
			array( 'attr' => 'v4', 'label' => 'Stat 4 value',  'type' => 'text', 'default' => '140+' ),
			array( 'attr' => 'l4', 'label' => 'Stat 4 label',  'type' => 'text', 'default' => nsw_theme_t( 'stats.documents', 'Digitized Documents' ) ),
		),
		'nsw-theme/what-is-nsw' => array(
			array( 'attr' => 'title',  'label' => 'Title',            'type' => 'text',     'default' => nsw_theme_t( 'whatIsNsw.title', 'What is the National Single Window?' ) ),
			array( 'attr' => 'desc1',  'label' => 'Description line 1','type' => 'textarea', 'default' => nsw_theme_t( 'whatIsNsw.description', '' ) ),
			array( 'attr' => 'desc2',  'label' => 'Description line 2','type' => 'textarea', 'default' => nsw_theme_t( 'whatIsNsw.description2', '' ) ),
			array( 'attr' => 'f1Title','label' => 'Feature 1 title',  'type' => 'text',     'default' => nsw_theme_t( 'whatIsNsw.feature1Title', 'Single Submission' ) ),
			array( 'attr' => 'f1Desc', 'label' => 'Feature 1 text',   'type' => 'textarea', 'default' => nsw_theme_t( 'whatIsNsw.feature1Desc', '' ) ),
			array( 'attr' => 'f2Title','label' => 'Feature 2 title',  'type' => 'text',     'default' => nsw_theme_t( 'whatIsNsw.feature2Title', 'Transparency' ) ),
			array( 'attr' => 'f2Desc', 'label' => 'Feature 2 text',   'type' => 'textarea', 'default' => nsw_theme_t( 'whatIsNsw.feature2Desc', '' ) ),
			array( 'attr' => 'f3Title','label' => 'Feature 3 title',  'type' => 'text',     'default' => nsw_theme_t( 'whatIsNsw.feature3Title', 'Speed' ) ),
			array( 'attr' => 'f3Desc', 'label' => 'Feature 3 text',   'type' => 'textarea', 'default' => nsw_theme_t( 'whatIsNsw.feature3Desc', '' ) ),
			array( 'attr' => 'btnText','label' => 'Button label',     'type' => 'text',     'default' => nsw_theme_t( 'common.learnMore', 'Learn more' ) ),
			array( 'attr' => 'btnUrl', 'label' => 'Button link',      'type' => 'url',      'default' => nsw_theme_path_url( 'how-it-works' ) ),
		),
		'nsw-theme/agencies-preview' => array(
			array( 'attr' => 'title',      'label' => 'Title',        'type' => 'text', 'default' => nsw_theme_t( 'agenciesSection.title', 'Participating Agencies' ) ),
			array( 'attr' => 'subtitle',   'label' => 'Subtitle',     'type' => 'text', 'default' => nsw_theme_t( 'agenciesSection.subtitle', '' ) ),
			array( 'attr' => 'seeAllText', 'label' => 'Button label', 'type' => 'text', 'default' => nsw_theme_t( 'agenciesSection.seeAll', 'View all agencies' ) ),
			array( 'attr' => 'seeAllUrl',  'label' => 'Button link',  'type' => 'url',  'default' => nsw_theme_path_url( 'agencies' ) ),
		),
		'nsw-theme/news-latest' => array(
			array( 'attr' => 'title',      'label' => 'Title',        'type' => 'text', 'default' => nsw_theme_t( 'newsSection.title', 'Latest News' ) ),
			array( 'attr' => 'subtitle',   'label' => 'Subtitle',     'type' => 'text', 'default' => nsw_theme_t( 'newsSection.subtitle', '' ) ),
			array( 'attr' => 'seeAllText', 'label' => 'Button label', 'type' => 'text', 'default' => nsw_theme_t( 'newsSection.seeAll', 'View all news' ) ),
			array( 'attr' => 'seeAllUrl',  'label' => 'Button link',  'type' => 'url',  'default' => nsw_theme_path_url( 'news' ) ),
		),
		'nsw-theme/partners' => array(
			array( 'attr' => 'title',    'label' => 'Title',    'type' => 'text', 'default' => nsw_theme_t( 'partnersSection.title', 'Our Partners' ) ),
			array( 'attr' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'default' => nsw_theme_t( 'partnersSection.subtitle', '' ) ),
		),
		'nsw-theme/cta' => array(
			array( 'attr' => 'title',      'label' => 'Title',        'type' => 'text',     'default' => nsw_theme_t( 'ctaSection.title', 'Have questions about NSW?' ) ),
			array( 'attr' => 'description','label' => 'Description',   'type' => 'textarea', 'default' => nsw_theme_t( 'ctaSection.description', '' ) ),
			array( 'attr' => 'buttonText','label' => 'Button label',  'type' => 'text',     'default' => nsw_theme_t( 'ctaSection.button', 'Contact Us' ) ),
			array( 'attr' => 'buttonUrl', 'label' => 'Button link',   'type' => 'url',      'default' => nsw_theme_path_url( 'contact' ) ),
		),
		// Partners page: the International/Government columns come from the
		// nsw_partner CPTs; only the "Private Sector" block is editorial copy.
		'nsw-theme/partners-page' => array(
			array( 'attr' => 'privateTitle',    'label' => 'Private Sector — title',                'type' => 'text',     'default' => nsw_theme_t( 'partnersPage.privateSectorTitle', 'Private Sector' ) ),
			array( 'attr' => 'privateDesc',     'label' => 'Private Sector — description',          'type' => 'textarea', 'default' => nsw_theme_t( 'partnersPage.privateSectorDesc', '' ) ),
			array( 'attr' => 'privateActors',   'label' => 'Private Sector — actors (one per line)','type' => 'textarea', 'default' => implode( "\n", (array) nsw_theme_dot_get( nsw_theme_get_content(), 'partnersPage.privateSectorActors', array() ) ) ),
			array( 'attr' => 'privateBenefits', 'label' => 'Private Sector — benefits',             'type' => 'textarea', 'default' => nsw_theme_t( 'partnersPage.privateSectorBenefits', '' ) ),
		),
	);
}

/**
 * Build the WordPress attribute schema for a block from its field config, so
 * the server- and client-side registrations match.
 *
 * @return array<string, array<string, mixed>>
 */
function nsw_theme_block_attributes( string $block ): array {
	$attrs = array();
	foreach ( nsw_theme_block_fields()[ $block ] ?? array() as $f ) {
		if ( 'image' === $f['type'] ) {
			$attrs[ $f['attr'] ]         = array( 'type' => 'string', 'default' => '' );
			$attrs[ $f['attr'] . 'Id' ]  = array( 'type' => 'number', 'default' => 0 );
		} elseif ( 'media' === $f['type'] ) {
			// Image-or-video: url + attachment id + mime, defaulting to the
			// current hero background so the picker shows it as selected.
			$attrs[ $f['attr'] ]           = array( 'type' => 'string', 'default' => (string) ( $f['default'] ?? '' ) );
			$attrs[ $f['attr'] . 'Id' ]    = array( 'type' => 'number', 'default' => (int) ( $f['defaultId'] ?? 0 ) );
			$attrs[ $f['attr'] . 'Mime' ]  = array( 'type' => 'string', 'default' => (string) ( $f['defaultMime'] ?? '' ) );
		} else {
			$attrs[ $f['attr'] ] = array( 'type' => 'string', 'default' => '' );
		}
	}
	return $attrs;
}

/**
 * Resolve a field: the editor-supplied attribute if non-empty, else the
 * localized default (Polylang string). Keeps the block identical until edited.
 */
function nsw_theme_field( array $attributes, string $key, string $fallback = '' ): string {
	$v = isset( $attributes[ $key ] ) ? trim( (string) $attributes[ $key ] ) : '';
	return '' !== $v ? $v : $fallback;
}

/* ---- Footer pieces (dynamic; bilingual text comes from Polylang Strings,
 *      editable under Languages → Strings). Composed as blocks in
 *      parts/footer.html so the footer columns are rearrangeable. ---- */

/**
 * Brand-logo SVG filenames per locale, straight from the theme (no Media
 * Library). Each row has the 'dark' (on light backgrounds) and 'light'
 * (reversed, for dark backgrounds) lockup. A locale with no row of its own uses
 * 'default', so adding a language's logo is a map entry, not a code change.
 *
 * @return array<string, array{dark: string, light: string}>
 */
function nsw_theme_brand_logo_files(): array {
	$en = array( 'dark' => 'nsw-logo.svg', 'light' => 'nsw-logo-en-light.svg' );
	$sq = array( 'dark' => 'nsw-logo-alb.svg', 'light' => 'nsw-logo-alb-light.svg' );
	return array(
		'en'      => $en,
		'sq'      => $sq,
		'default' => $sq,
	);
}

/**
 * Pick the brand-logo SVG filename for the current locale.
 *
 * @param bool $light Return the reversed (white) variant for dark backgrounds.
 */
function nsw_theme_brand_logo_file( bool $light = false ): string {
	$files = nsw_theme_brand_logo_files();
	$row   = $files[ nsw_theme_current_locale() ] ?? $files['default'];
	return $row[ $light ? 'light' : 'dark' ];
}

/** Header brand logo — language-aware, read from the theme (replaces the core Site Logo). */
function nsw_theme_block_brand_logo(): string {
	$file = nsw_theme_brand_logo_file( false );
	return '<a class="custom-logo-link site-header__logo" href="' . esc_url( nsw_theme_home_url() ) . '" rel="home">'
		. '<img class="custom-logo" src="' . esc_url( NSW_THEME_URI . 'assets/images/logos/' . $file ) . '" alt="'
		. esc_attr( get_bloginfo( 'name' ) ) . '" width="185" /></a>';
}

function nsw_theme_block_footer_logo(): string {
	$file = nsw_theme_brand_logo_file( true );
	return '<a class="site-footer__logo" href="' . esc_url( nsw_theme_home_url() ) . '">'
		. '<img src="' . esc_url( NSW_THEME_URI . 'assets/images/logos/' . $file ) . '" alt="'
		. esc_attr__( 'NSW Albania — National Single Window', 'nsw-theme' ) . '" width="185" height="60" /></a>';
}

/** Bilingual text piece. Attributes: tkey (string key), tag, cls, year (bool). */
function nsw_theme_block_footer_text( $attributes = array() ): string {
	$key = (string) ( $attributes['tkey'] ?? '' );
	if ( '' === $key ) {
		return '';
	}
	$tag = strtolower( (string) ( $attributes['tag'] ?? 'p' ) );
	if ( ! in_array( $tag, array( 'p', 'h2', 'h3', 'h4', 'span', 'div' ), true ) ) {
		$tag = 'p';
	}
	$cls  = (string) ( $attributes['cls'] ?? '' );
	$text = nsw_theme_t( $key, '' );
	if ( ! empty( $attributes['year'] ) ) {
		$text = str_replace( '{year}', date_i18n( 'Y' ), $text );
	}
	return '<' . $tag . ( '' !== $cls ? ' class="' . esc_attr( $cls ) . '"' : '' ) . '>' . esc_html( $text ) . '</' . $tag . '>';
}

function nsw_theme_block_footer_contact(): string {
	ob_start();
	?>
	<ul class="site-footer__contact">
		<li>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>
			<span><?php echo esc_html( nsw_theme_t( 'footer.address', 'Rruga "Dëshmorët e 4 Shkurtit", Tiranë, Shqipëri' ) ); ?></span>
		</li>
		<li>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 7L2 7"/></svg>
			<span><?php echo esc_html( nsw_theme_t( 'footer.email', 'info@nsw.al' ) ); ?></span>
		</li>
		<li>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
			<span><?php echo esc_html( nsw_theme_t( 'footer.hours', 'Mon – Fri, 08:00–16:00' ) ); ?></span>
		</li>
	</ul>
	<?php
	return (string) ob_get_clean();
}

/** Generic per-language block-nav renderer. Attribute: menu (base slug). */
function nsw_theme_block_nav( $attributes = array() ): string {
	$menu = (string) ( $attributes['menu'] ?? '' );
	return '' === $menu ? '' : nsw_theme_render_block_nav( $menu, 'never' );
}

/* ---- Compact hero for interior pages (driven by the page title + excerpt) ---- */

function nsw_theme_block_page_hero(): string {
	// On the posts page (news listing) the loop is posts, so read the title
	// from the assigned "Posts page" instead of the first post.
	if ( is_home() && ! is_front_page() ) {
		$pid      = (int) get_option( 'page_for_posts' );
		$title    = $pid ? get_the_title( $pid ) : get_the_title();
		$subtitle = ( $pid && has_excerpt( $pid ) ) ? get_the_excerpt( $pid ) : '';
	} else {
		$qo       = get_queried_object();
		$title    = $qo instanceof WP_Post ? get_the_title( $qo ) : get_the_title();
		$subtitle = ( $qo instanceof WP_Post && has_excerpt( $qo ) ) ? get_the_excerpt( $qo ) : '';
	}
	ob_start();
	nsw_theme_render_hero(
		array(
			'variant'  => 'compact',
			'title'    => $title,
			'subtitle' => $subtitle,
		)
	);
	return (string) ob_get_clean();
}

/* ---- Homepage sections (each a rearrangeable block in the Site Editor) ---- */

function nsw_theme_block_hero_home( $attributes = array() ): string {
	$cta1_text = nsw_theme_field( $attributes, 'cta1Text', nsw_theme_t( 'hero.cta1', 'Learn More' ) );
	$cta1_url  = nsw_theme_field( $attributes, 'cta1Url', nsw_theme_path_url( 'about' ) );
	$cta2_text = nsw_theme_field( $attributes, 'cta2Text', nsw_theme_t( 'hero.cta2', 'Contact Us' ) );
	$cta2_url  = nsw_theme_field( $attributes, 'cta2Url', nsw_theme_path_url( 'contact' ) );

	$hero_cta = sprintf(
		'<a class="btn btn--lg" href="%1$s">%2$s</a><a class="btn btn--lg btn--secondary" href="%3$s">%4$s</a>',
		esc_url( $cta1_url ),
		esc_html( $cta1_text ),
		esc_url( $cta2_url ),
		esc_html( $cta2_text )
	);
	ob_start();
	nsw_theme_render_hero(
		array(
			'variant'  => 'large',
			'title'    => nsw_theme_field( $attributes, 'title', nsw_theme_t( 'hero.title', 'Transforming Albanian Trade' ) ),
			'subtitle' => nsw_theme_field( $attributes, 'subtitle', nsw_theme_t( 'hero.subtitle', 'The National Single Window — the single electronic entry point for all cross-border trade regulatory requirements in Albania.' ) ),
			'children' => $hero_cta,
			'bg_url'   => nsw_theme_field( $attributes, 'bgMedia', '' ),
			'bg_mime'  => isset( $attributes['bgMediaMime'] ) ? (string) $attributes['bgMediaMime'] : '',
		)
	);
	return (string) ob_get_clean();
}

function nsw_theme_block_stats( $attributes = array() ): string {
	// Pass per-item overrides to the part; empty attrs fall back to the strings.
	foreach ( array( 'v1', 'l1', 'v2', 'l2', 'v3', 'l3', 'v4', 'l4' ) as $k ) {
		set_query_var( 'nsw_theme_stats_' . $k, isset( $attributes[ $k ] ) ? trim( (string) $attributes[ $k ] ) : '' );
	}
	return nsw_theme_capture_part( 'template-parts/sections/stats' );
}

function nsw_theme_block_what_is_nsw( $attributes = array() ): string {
	foreach ( array( 'title', 'desc1', 'desc2', 'f1Title', 'f1Desc', 'f2Title', 'f2Desc', 'f3Title', 'f3Desc', 'btnText', 'btnUrl' ) as $k ) {
		set_query_var( 'nsw_theme_wisnsw_' . $k, isset( $attributes[ $k ] ) ? trim( (string) $attributes[ $k ] ) : '' );
	}
	return nsw_theme_capture_part( 'template-parts/sections/what-is-nsw' );
}

function nsw_theme_block_agencies_preview( $attributes = array() ): string {
	$title    = nsw_theme_field( $attributes, 'title', nsw_theme_t( 'agenciesSection.title', 'Participating Agencies' ) );
	$subtitle = nsw_theme_field( $attributes, 'subtitle', nsw_theme_t( 'agenciesSection.subtitle', '14 cross-border regulatory agencies connected to NSW' ) );
	$see_text = nsw_theme_field( $attributes, 'seeAllText', nsw_theme_t( 'agenciesSection.seeAll', 'View all agencies' ) );
	$see_url  = nsw_theme_field( $attributes, 'seeAllUrl', nsw_theme_path_url( 'agencies' ) );
	ob_start();
	?>
	<section class="section section--muted">
		<div class="container">
			<div class="section-heading" data-reveal>
				<h2 class="section-heading__title"><?php echo esc_html( $title ); ?></h2>
				<p class="section-heading__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			</div>
			<div style="margin-top:3rem">
				<?php
				set_query_var( 'nsw_theme_agencies_list', array() );
				set_query_var( 'nsw_theme_agencies_limit', 8 );
				set_query_var( 'nsw_theme_agencies_show_documents', false );
				get_template_part( 'template-parts/sections/agencies-grid' );
				?>
			</div>
			<div class="section-actions" data-reveal data-reveal-delay="3">
				<a class="btn btn--outline" href="<?php echo esc_url( $see_url ); ?>">
					<?php echo esc_html( $see_text ); ?>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
				</a>
			</div>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

function nsw_theme_block_news_latest( $attributes = array() ): string {
	foreach ( array( 'title', 'subtitle', 'seeAllText', 'seeAllUrl' ) as $k ) {
		set_query_var( 'nsw_theme_news_' . $k, isset( $attributes[ $k ] ) ? trim( (string) $attributes[ $k ] ) : '' );
	}
	return nsw_theme_capture_part( 'template-parts/sections/news-latest' );
}

function nsw_theme_block_partners( $attributes = array() ): string {
	foreach ( array( 'title', 'subtitle' ) as $k ) {
		set_query_var( 'nsw_theme_partners_' . $k, isset( $attributes[ $k ] ) ? trim( (string) $attributes[ $k ] ) : '' );
	}
	return nsw_theme_capture_part( 'template-parts/sections/partners' );
}

function nsw_theme_block_cta( $attributes = array() ): string {
	set_query_var( 'nsw_theme_cta_title', nsw_theme_field( $attributes, 'title', '' ) );
	set_query_var( 'nsw_theme_cta_description', nsw_theme_field( $attributes, 'description', '' ) );
	set_query_var( 'nsw_theme_cta_button', nsw_theme_field( $attributes, 'buttonText', '' ) );
	set_query_var( 'nsw_theme_cta_href', nsw_theme_field( $attributes, 'buttonUrl', '' ) );
	return nsw_theme_capture_part( 'template-parts/sections/cta' );
}

/* ---- News listing (posts page / archive) — reuses the news card ---- */

function nsw_theme_block_news_list(): string {
	global $wp_query;
	$locale = nsw_theme_current_locale();
	ob_start();
	if ( $wp_query->have_posts() ) {
		echo '<section class="section"><div class="container"><div class="grid grid--lg-3">';
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			set_query_var( 'nsw_theme_card_post', get_post() );
			set_query_var( 'nsw_theme_card_data', array() );
			set_query_var( 'nsw_theme_card_locale', $locale );
			get_template_part( 'template-parts/cards/news', 'card' );
		}
		wp_reset_postdata();
		echo '</div>';
		the_posts_pagination(
			array(
				'mid_size'  => 1,
				'prev_text' => esc_html( nsw_theme_t( 'common.previous', 'Previous' ) ),
				'next_text' => esc_html( nsw_theme_t( 'common.next', 'Next' ) ),
			)
		);
		echo '</div></section>';
	}
	return (string) ob_get_clean();
}

/* ---- Primary navigation (per-language) ----
 * Polylang (free) doesn't swap the core Navigation block's menu per language,
 * so this thin wrapper renders the correct-language block menu. The menus are
 * still real, Site-Editor-editable core Navigation menus (Appearance → Editor →
 * Navigation).
 */
/**
 * Render a block Navigation menu for the current language. Menus are stored as
 * wp_navigation posts: "$base_slug" for the site's DEFAULT language and
 * "$base_slug-{locale}" for every other one (e.g. "nsw-primary-en"). Adding a
 * language therefore only means adding a menu with the matching slug in the
 * Site Editor. Falls back to the default-language menu when a translated menu
 * doesn't exist yet.
 */
function nsw_theme_render_block_nav( string $base_slug, string $overlay = 'never' ): string {
	$locale = nsw_theme_current_locale();
	$slug   = ( $locale === nsw_theme_default_locale() ) ? $base_slug : $base_slug . '-' . $locale;
	$nav    = get_page_by_path( $slug, OBJECT, 'wp_navigation' );
	if ( ! $nav ) {
		$nav = get_page_by_path( $base_slug, OBJECT, 'wp_navigation' );
	}
	if ( ! $nav ) {
		return '';
	}
	return do_blocks( '<!-- wp:navigation {"ref":' . (int) $nav->ID . ',"overlayMenu":"' . esc_attr( $overlay ) . '"} /-->' );
}

function nsw_theme_block_primary_nav(): string {
	return nsw_theme_render_block_nav( 'nsw-primary', 'mobile' );
}

/* ---- Language switcher (one pill per Polylang language, active = primary) ---- */

function nsw_theme_block_language_switcher(): string {
	if ( ! function_exists( 'pll_the_languages' ) ) {
		return '';
	}
	$langs = pll_the_languages(
		array(
			'raw'                    => 1,
			'hide_if_no_translation' => 0,
			'hide_current'           => 0,
			// Every configured language always gets a pill, including one that
			// has no published content yet (Polylang hides those by default) —
			// otherwise a newly added locale is unreachable from the front end.
			'hide_if_empty'          => 0,
		)
	);
	if ( empty( $langs ) || ! is_array( $langs ) ) {
		return '';
	}
	$out = '<div class="lang-switcher">';
	$i   = 0;
	foreach ( $langs as $l ) {
		if ( $i++ > 0 ) {
			$out .= '<span class="lang-switcher__sep" aria-hidden="true">|</span>';
		}
		$active = ! empty( $l['current_lang'] );
		$out   .= sprintf(
			'<a class="lang-switcher__link%s" href="%s" lang="%s"%s>%s</a>',
			$active ? ' is-active' : '',
			esc_url( (string) ( $l['url'] ?? '#' ) ),
			esc_attr( (string) ( $l['slug'] ?? '' ) ),
			$active ? ' aria-current="true"' : '',
			esc_html( strtoupper( (string) ( $l['slug'] ?? '' ) ) )
		);
	}
	$out .= '</div>';
	return $out;
}

/* ---- Single news article (bespoke design) ---- */

function nsw_theme_block_single_post(): string {
	$queried = get_queried_object();
	if ( ! $queried instanceof WP_Post ) {
		return '';
	}
	global $post;
	$post = $queried;
	setup_postdata( $post );
	$out = nsw_theme_capture_part( 'template-parts/single-news' );
	wp_reset_postdata();
	return $out;
}

/* ---- Single service guide (public CPT) ---- */

function nsw_theme_block_single_service(): string {
	$queried = get_queried_object();
	if ( ! $queried instanceof WP_Post ) {
		return '';
	}
	global $post;
	$post = $queried;
	setup_postdata( $post );
	$out = nsw_theme_capture_part( 'template-parts/single-service' );
	wp_reset_postdata();
	return $out;
}

/* ---- 404 (translatable via nsw_theme_t) ---- */

function nsw_theme_block_not_found(): string {
	ob_start();
	?>
	<section class="section">
		<div class="container" style="max-width:42rem;text-align:center">
			<div data-reveal>
				<div style="font-family:var(--font-serif);font-weight:800;line-height:1;color:var(--primary);font-size:clamp(4rem,12vw,8rem)">404</div>
				<h1 style="margin-top:1rem"><?php echo esc_html( nsw_theme_t( 'notFound.title', 'Page Not Found' ) ); ?></h1>
				<p style="margin-top:1rem;color:var(--muted-foreground)"><?php echo esc_html( nsw_theme_t( 'notFound.description', 'The page you are looking for does not exist or has been moved.' ) ); ?></p>
				<p style="margin-top:2rem">
					<a class="btn btn--lg" href="<?php echo esc_url( nsw_theme_home_url() ); ?>"><?php echo esc_html( nsw_theme_t( 'notFound.backHome', 'Back to Homepage' ) ); ?></a>
				</p>
			</div>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/* ---- Registration ---- */

// Group the theme's blocks under their own inserter category.
add_filter(
	'block_categories_all',
	function ( $categories ) {
		array_unshift( $categories, array( 'slug' => 'nsw-theme', 'title' => __( 'NSW Theme', 'nsw-theme' ) ) );
		return $categories;
	}
);

add_action(
	'init',
	function () {
		$blocks = array(
			'nsw-theme/brand-logo'       => array( 'nsw_theme_block_brand_logo', 'NSW Brand Logo (header)' ),
			'nsw-theme/footer-logo'      => array( 'nsw_theme_block_footer_logo', 'NSW Footer Logo' ),
			'nsw-theme/footer-contact'   => array( 'nsw_theme_block_footer_contact', 'NSW Footer Contact' ),
			'nsw-theme/page-hero'        => array( 'nsw_theme_block_page_hero', 'NSW Page Hero' ),
			'nsw-theme/primary-nav'      => array( 'nsw_theme_block_primary_nav', 'NSW Primary Navigation' ),
			'nsw-theme/language-switcher' => array( 'nsw_theme_block_language_switcher', 'NSW Language Switcher' ),
			'nsw-theme/news-list'        => array( 'nsw_theme_block_news_list', 'NSW News List' ),
			'nsw-theme/single-post'      => array( 'nsw_theme_block_single_post', 'NSW Single Article' ),
			'nsw-theme/single-service'   => array( 'nsw_theme_block_single_service', 'NSW Single Service' ),
			'nsw-theme/not-found'        => array( 'nsw_theme_block_not_found', 'NSW Not Found (404)' ),
			'nsw-theme/hero-home'        => array( 'nsw_theme_block_hero_home', 'NSW Home Hero' ),
			'nsw-theme/stats'            => array( 'nsw_theme_block_stats', 'NSW Stats' ),
			'nsw-theme/what-is-nsw'      => array( 'nsw_theme_block_what_is_nsw', 'NSW What is NSW' ),
			'nsw-theme/agencies-preview' => array( 'nsw_theme_block_agencies_preview', 'NSW Agencies Preview' ),
			'nsw-theme/news-latest'      => array( 'nsw_theme_block_news_latest', 'NSW Latest News' ),
			'nsw-theme/partners'         => array( 'nsw_theme_block_partners', 'NSW Partners' ),
			'nsw-theme/cta'              => array( 'nsw_theme_block_cta', 'NSW Call to Action' ),
		);
		$field_config = nsw_theme_block_fields();
		foreach ( $blocks as $name => $def ) {
			// The editorial "section" blocks (those with editable fields) also get
			// Dimensions (margin/padding) controls.
			$is_section = isset( $field_config[ $name ] );

			$supports = array( 'html' => false, 'reusable' => false );
			if ( $is_section ) {
				$supports['spacing'] = array(
					'margin'  => array( 'top', 'bottom' ),
					'padding' => true,
				);
			}

			$render = $def[0];
			if ( $is_section ) {
				// Wrap the section output so the spacing (and the block class) that
				// WordPress generates from the Dimensions controls is applied to the
				// real front-end markup. get_block_wrapper_attributes() reads the
				// current block being rendered.
				$cb     = $def[0];
				$render = function ( $attributes, $content, $block ) use ( $cb ) {
					$html = (string) call_user_func( $cb, $attributes );
					if ( '' === trim( $html ) ) {
						return $html;
					}
					return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
				};
			}

			$args = array(
				'api_version'     => 3,
				'title'           => $def[1],
				'category'        => 'nsw-theme',
				'render_callback' => $render,
				'supports'        => $supports,
			);
			// Editorial blocks expose editable fields (sidebar) via attributes.
			$attributes = nsw_theme_block_attributes( $name );
			if ( ! empty( $attributes ) ) {
				$args['attributes'] = $attributes;
			}
			register_block_type( $name, $args );
		}

		// Attribute-driven footer blocks.
		register_block_type(
			'nsw-theme/footer-text',
			array(
				'api_version'     => 3,
				'title'           => 'NSW Footer Text',
				'category'        => 'nsw-theme',
				'render_callback' => 'nsw_theme_block_footer_text',
				'attributes'      => array(
					'tkey' => array( 'type' => 'string', 'default' => '' ),
					'tag'  => array( 'type' => 'string', 'default' => 'p' ),
					'cls'  => array( 'type' => 'string', 'default' => '' ),
					'year' => array( 'type' => 'boolean', 'default' => false ),
				),
				'supports'        => array( 'html' => false, 'reusable' => false ),
			)
		);
		register_block_type(
			'nsw-theme/nav',
			array(
				'api_version'     => 3,
				'title'           => 'NSW Navigation Menu',
				'category'        => 'nsw-theme',
				'render_callback' => 'nsw_theme_block_nav',
				'attributes'      => array(
					'menu' => array( 'type' => 'string', 'default' => '' ),
				),
				'supports'        => array( 'html' => false, 'reusable' => false ),
			)
		);
	}
);

/* ---- "Service guide" block pattern ----
 * A starter skeleton for authoring a Trade Service guide (intro, Steps,
 * Required documents, Fees, Processing time, Contact) so every guide keeps the
 * same structure. Registered here — not as a patterns/ file — because the
 * section headings come from the theme's Polylang string layer (nsw_theme_t),
 * not gettext, matching every other bilingual string in this file.
 *
 * NOTE on language: the pattern content is a static string captured ONCE per
 * request, at init, in the CURRENT locale — so the headings land in the
 * language of the wp-admin editor session that inserts the pattern (officers
 * editing in the Albanian admin get "Hapat", an English admin gets "Steps").
 * That is the intended behavior: the inserted headings are plain content owned
 * by the post's language from then on.
 */
add_action(
	'init',
	function () {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		register_block_pattern_category( 'nsw-theme', array( 'label' => __( 'NSW Theme', 'nsw-theme' ) ) );

		$steps      = esc_html( nsw_theme_t( 'servicesPage.stepsTitle', 'Steps' ) );
		$documents  = esc_html( nsw_theme_t( 'servicesPage.documentsTitle', 'Required documents' ) );
		$fees       = esc_html( nsw_theme_t( 'servicesPage.feesTitle', 'Fees' ) );
		$processing = esc_html( nsw_theme_t( 'servicesPage.processingTitle', 'Processing time' ) );
		$contact    = esc_html( nsw_theme_t( 'servicesPage.contactTitle', 'Contact' ) );

		$content = <<<HTML
<!-- wp:paragraph -->
<p>[Replace: one short paragraph — what this procedure is and who needs it.]</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">{$steps}</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>[Replace: step 1 — e.g. register on the NSW portal.]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: step 2 — e.g. submit the application with the documents below.]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: step 3 — e.g. pay the service fee.]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: step 4 — e.g. border inspection of the consignment.]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: step 5 — e.g. receive the electronic permit.]</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">{$documents}</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>[Replace: document 1]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: document 2]</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>[Replace: document 3]</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">{$fees}</h2>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>[Service]</th><th>[LEK]</th></tr></thead><tbody><tr><td>[Replace: fee item 1]</td><td>[amount]</td></tr><tr><td>[Replace: fee item 2]</td><td>[amount]</td></tr><tr><td>[Replace: fee item 3]</td><td>[amount]</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:heading -->
<h2 class="wp-block-heading">{$processing}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[Replace: how long the procedure takes — e.g. 5 working days from payment confirmation.]</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">{$contact}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[Replace: the responsible agency's email, phone and office hours.]</p>
<!-- /wp:paragraph -->
HTML;

		register_block_pattern(
			'nsw-theme/service-guide',
			array(
				'title'       => __( 'Service guide', 'nsw-theme' ),
				'description' => __( 'Starter structure for a Trade Service guide: intro, steps, required documents, fees, processing time and contact.', 'nsw-theme' ),
				'categories'  => array( 'nsw-theme' ),
				'postTypes'   => array( NSW_THEME_CPT_SERVICE ),
				'blockTypes'  => array( 'core/post-content' ),
				'content'     => $content,
			)
		);
	}
);
