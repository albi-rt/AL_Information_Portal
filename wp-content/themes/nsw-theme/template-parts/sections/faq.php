<?php
/**
 * FAQ page body — rendered by the nsw-theme/faq dynamic block.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$locale = nsw_theme_current_locale();
$faqs   = nsw_theme_get_faq( $locale );

$categories_map = nsw_theme_dot_get( nsw_theme_get_content(), 'faqPage.categories' );
$categories     = is_array( $categories_map ) ? $categories_map : array();

// Stable category order matching the Next.js source.
$cat_order = array( 'general', 'registration', 'lpco', 'payments', 'technical', 'agencies' );

$grouped = array();
foreach ( $cat_order as $cat ) {
	$rows = array_values( array_filter( $faqs, function ( $item ) use ( $cat ) { return ( $item['category'] ?? '' ) === $cat; } ) );
	if ( ! empty( $rows ) ) {
		$grouped[ $cat ] = $rows;
	}
}
?>
<section class="section">
	<div class="container" data-faq style="max-width: 48rem">
		<div class="faq-search" data-reveal>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
			<input type="search" data-faq-search placeholder="<?php echo esc_attr( nsw_theme_t( 'faqPage.searchPlaceholder', 'Search for a question…' ) ); ?>" aria-label="<?php echo esc_attr( nsw_theme_t( 'common.search', 'Search' ) ); ?>" />
		</div>

		<div class="pill-row" role="group" aria-label="<?php echo esc_attr( nsw_theme_t( 'common.filterBy', 'Filter by' ) ); ?>" style="margin-bottom: 2.5rem">
			<button type="button" class="pill is-active" data-faq-pill="all" aria-pressed="true"><?php echo esc_html( nsw_theme_t( 'common.all', 'All' ) ); ?></button>
			<?php foreach ( $grouped as $cat => $_ ) :
				$label = $categories[ $cat ] ?? ucfirst( (string) $cat ); ?>
				<button type="button" class="pill" data-faq-pill="<?php echo esc_attr( (string) $cat ); ?>" aria-pressed="false"><?php echo esc_html( (string) $label ); ?></button>
			<?php endforeach; ?>
		</div>

		<div data-faq-empty class="faq-empty" hidden role="status">
			<?php echo esc_html( nsw_theme_t( 'faqPage.noResults', 'No results. Try a different search term or category.' ) ); ?>
		</div>

		<div class="faq-groups">
			<?php foreach ( $grouped as $cat => $rows ) :
				$label = $categories[ $cat ] ?? ucfirst( (string) $cat ); ?>
				<div class="faq-group" data-faq-group="<?php echo esc_attr( (string) $cat ); ?>" data-reveal>
					<h3 class="faq-group__heading"><?php echo esc_html( (string) $label ); ?></h3>
					<div class="accordion">
						<?php foreach ( $rows as $faq ) : ?>
							<div class="accordion__item" data-faq-item data-faq-cat="<?php echo esc_attr( (string) $cat ); ?>">
								<details>
									<summary>
										<span><?php echo esc_html( (string) ( $faq['question'] ?? '' ) ); ?></span>
										<svg class="accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
									</summary>
									<div class="accordion__body"><div class="accordion__body__inner"><?php echo esc_html( (string) ( $faq['answer'] ?? '' ) ); ?></div></div>
								</details>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
