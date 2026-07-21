<?php
/**
 * Site header (classic template, used by the data pages' PHP page templates).
 * Renders the block header part (parts/header.html) via block_template_part()
 * so classic and block-template pages share one header.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="profile" href="https://gmpg.org/xfn/11" />
	<?php if ( ! has_site_icon() ) : ?>
		<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( NSW_THEME_URI . 'assets/images/logos/favicon.svg' ); ?>" />
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php block_template_part( 'header' ); // Renders parts/header.html — same block header used by the block templates. ?>

<main id="site-main" class="site-main">
