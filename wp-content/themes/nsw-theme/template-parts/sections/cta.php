<?php
/**
 * Generic CTA section.
 *
 * Pass nsw_theme_cta_{title,description,button,href} via set_query_var(),
 * or rely on the homepage defaults.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title       = (string) get_query_var( 'nsw_theme_cta_title' );
$description = (string) get_query_var( 'nsw_theme_cta_description' );
$button      = (string) get_query_var( 'nsw_theme_cta_button' );
$href        = (string) get_query_var( 'nsw_theme_cta_href' );

if ( '' === $title )       $title       = nsw_theme_t( 'ctaSection.title', 'Have questions about NSW?' );
if ( '' === $description ) $description = nsw_theme_t( 'ctaSection.description', 'Our team is ready to help you with any question about the National Single Window.' );
if ( '' === $button )      $button      = nsw_theme_t( 'ctaSection.button', 'Contact Us' );
if ( '' === $href )        $href        = nsw_theme_path_url( 'contact' );
?>
<section class="cta">
	<div class="cta__inner">
		<h2 class="cta__title" data-reveal><?php echo esc_html( $title ); ?></h2>
		<p class="cta__description" data-reveal data-reveal-delay="1"><?php echo esc_html( $description ); ?></p>
		<div class="cta__action" data-reveal data-reveal-delay="2">
			<a class="btn btn--secondary btn--lg" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $button ); ?></a>
		</div>
	</div>
</section>
