<?php
/**
 * Partners strip (homepage).
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partners = nsw_theme_get_partners();
$locale   = nsw_theme_current_locale();
?>
<section class="section section--muted">
	<div class="container">
		<div class="section-heading" data-reveal>
			<h2 class="section-heading__title"><?php echo esc_html( get_query_var( 'nsw_theme_partners_title' ) ?: nsw_theme_t( 'partnersSection.title', 'Our Partners' ) ); ?></h2>
			<p class="section-heading__subtitle"><?php echo esc_html( get_query_var( 'nsw_theme_partners_subtitle' ) ?: nsw_theme_t( 'partnersSection.subtitle', 'NSW is supported by international partners and government institutions' ) ); ?></p>
		</div>

		<div style="margin-top:3rem; display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:2rem">
			<?php
			$i = 0;
			foreach ( $partners as $partner ) :
				$i++;
				$name    = nsw_theme_localized( $partner['name'] ?? '', $locale );
				$logo    = isset( $partner['logo'] ) ? nsw_theme_asset_url( (string) $partner['logo'] ) : '';
				$logo_bg = ! empty( $partner['logoBg'] );
				$color   = $partner['color'] ?? 'var(--muted)';
				$id      = (string) ( $partner['id'] ?? '' );
				?>
				<div class="partner-tile" data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>">
					<?php if ( $logo ) : ?>
						<div class="partner-tile__logo"<?php if ( $logo_bg ) : ?> style="background: <?php echo esc_attr( (string) $color ); ?>"<?php endif; ?>>
							<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( (string) $name ); ?>" />
						</div>
					<?php else :
						$fallback_text = 'world-bank' === $id ? 'WB' : ( 'cefta' === $id ? 'CEFTA' : ( 'ministry-finance' === $id ? 'MFE' : 'NAIS' ) ); ?>
						<div class="partner-tile__placeholder" style="background: <?php echo esc_attr( (string) $color ); ?>">
							<?php echo esc_html( $fallback_text ); ?>
						</div>
					<?php endif; ?>
					<span class="partner-tile__label"><?php echo esc_html( (string) $name ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
