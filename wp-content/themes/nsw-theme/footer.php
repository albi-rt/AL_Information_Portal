<?php
/**
 * Site footer (classic template, used by the data pages' PHP page templates).
 * Renders the block footer part (parts/footer.html) via block_template_part()
 * so classic and block-template pages share one footer.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<?php block_template_part( 'footer' ); // Renders parts/footer.html — same block footer used by the block templates. ?>

<?php wp_footer(); ?>
</body>
</html>
