<?php
/**
 * NSW Albania theme bootstrap.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NSW_THEME_VERSION' ) ) {
	define( 'NSW_THEME_VERSION', '0.9.0' );
}
if ( ! defined( 'NSW_THEME_DIR' ) ) {
	define( 'NSW_THEME_DIR', trailingslashit( get_template_directory() ) );
}
if ( ! defined( 'NSW_THEME_URI' ) ) {
	define( 'NSW_THEME_URI', trailingslashit( get_template_directory_uri() ) );
}

require NSW_THEME_DIR . 'inc/setup.php';
require NSW_THEME_DIR . 'inc/enqueue.php';
require NSW_THEME_DIR . 'inc/hero-media.php';
require NSW_THEME_DIR . 'inc/i18n.php';
require NSW_THEME_DIR . 'inc/data.php';
require NSW_THEME_DIR . 'inc/template-tags.php';
require NSW_THEME_DIR . 'inc/blocks.php';
require NSW_THEME_DIR . 'inc/data-blocks.php';
require NSW_THEME_DIR . 'inc/post-types.php';
require NSW_THEME_DIR . 'inc/taxonomies.php';
require NSW_THEME_DIR . 'inc/meta-boxes.php';
require NSW_THEME_DIR . 'inc/contact-mantis.php';
require NSW_THEME_DIR . 'inc/contact-form.php';
require NSW_THEME_DIR . 'inc/agency-news.php';
require NSW_THEME_DIR . 'inc/migrations.php';
require NSW_THEME_DIR . 'inc/admin.php';
