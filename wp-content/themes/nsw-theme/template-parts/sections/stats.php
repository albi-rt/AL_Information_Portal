<?php
/**
 * Stats counter strip (homepage).
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Each stat's value is an editable free-text field ("14", "72%", "140+").
 * We split the numeric part (for the count-up animation) from a trailing
 * suffix. Block overrides come via query vars; empty falls back to the
 * localized default.
 */
$stats_raw = array(
	array( 'value' => get_query_var( 'nsw_theme_stats_v1' ) ?: '14',   'label' => get_query_var( 'nsw_theme_stats_l1' ) ?: nsw_theme_t( 'stats.agencies', 'Participating Agencies' ) ),
	array( 'value' => get_query_var( 'nsw_theme_stats_v2' ) ?: '72%',  'label' => get_query_var( 'nsw_theme_stats_l2' ) ?: nsw_theme_t( 'stats.costReduction', 'Cost Reduction' ) ),
	array( 'value' => get_query_var( 'nsw_theme_stats_v3' ) ?: '100%', 'label' => get_query_var( 'nsw_theme_stats_l3' ) ?: nsw_theme_t( 'stats.euAlignment', 'EU Alignment' ) ),
	array( 'value' => get_query_var( 'nsw_theme_stats_v4' ) ?: '140+', 'label' => get_query_var( 'nsw_theme_stats_l4' ) ?: nsw_theme_t( 'stats.documents', 'Digitized Documents' ) ),
);

$stats = array();
foreach ( $stats_raw as $s ) {
	// Split "140+" -> number "140", suffix "+".  Non-numeric values render as-is.
	preg_match( '/^\s*([0-9]+(?:[.,][0-9]+)?)\s*(.*)$/u', (string) $s['value'], $m );
	$stats[] = array(
		'number' => $m[1] ?? '',
		'suffix' => isset( $m[2] ) ? trim( $m[2] ) : '',
		'raw'    => (string) $s['value'],
		'label'  => (string) $s['label'],
	);
}
?>
<section class="stats">
	<div class="stats__grid">
		<?php foreach ( $stats as $stat ) : ?>
			<div class="stats__item" data-reveal>
				<?php if ( '' !== $stat['number'] ) : ?>
					<div class="stats__value" data-stat-target="<?php echo esc_attr( $stat['number'] ); ?>" data-stat-suffix="<?php echo esc_attr( $stat['suffix'] ); ?>">0<?php echo esc_html( $stat['suffix'] ); ?></div>
				<?php else : ?>
					<div class="stats__value"><?php echo esc_html( $stat['raw'] ); ?></div>
				<?php endif; ?>
				<div class="stats__label"><?php echo esc_html( $stat['label'] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>
</section>
