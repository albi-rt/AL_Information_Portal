<?php
/**
 * Title: NSW — Feature Cards (3 columns)
 * Slug: nsw-theme/feature-cards
 * Categories: columns, featured
 * Description: A full-width muted band with three on-brand feature cards.
 *
 * @package NSW_Theme
 */
?>
<!-- wp:group {"align":"full","backgroundColor":"muted","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-muted-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Section title', 'nsw-theme' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Feature one', 'nsw-theme' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Describe the feature in a short, clear sentence.', 'nsw-theme' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Feature two', 'nsw-theme' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Describe the feature in a short, clear sentence.', 'nsw-theme' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Feature three', 'nsw-theme' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Describe the feature in a short, clear sentence.', 'nsw-theme' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
