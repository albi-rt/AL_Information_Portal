<?php
/**
 * Partners page body — rendered by the nsw-theme/partners-page dynamic block.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$partners = nsw_theme_get_partners();
$locale   = nsw_theme_current_locale();

$international = array_values( array_filter( $partners, function ( $p ) { return ( $p['type'] ?? '' ) === 'international'; } ) );
$government    = array_values( array_filter( $partners, function ( $p ) { return ( $p['type'] ?? '' ) === 'government'; } ) );

// Private-sector copy comes from the partners-page block's editable fields
// (set as query vars by the render callback). Actors is a newline list.
$private_title    = (string) get_query_var( 'nsw_pp_private_title' );
$private_desc     = (string) get_query_var( 'nsw_pp_private_desc' );
$private_benefits = (string) get_query_var( 'nsw_pp_private_benefits' );
$private_actors   = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) get_query_var( 'nsw_pp_private_actors' ) ) ) ) );

$render_partner_card = static function ( array $partner, string $locale ) {
	$name    = nsw_theme_localized( $partner['name'] ?? '', $locale );
	$desc    = nsw_theme_localized( $partner['description'] ?? '', $locale );
	$logo    = isset( $partner['logo'] ) ? nsw_theme_asset_url( (string) $partner['logo'] ) : '';
	$logo_bg = ! empty( $partner['logoBg'] );
	$color   = $partner['color'] ?? 'var(--muted)';
	$id      = (string) ( $partner['id'] ?? '' );
	?>
	<div class="card partner-card">
		<?php if ( $logo ) : ?>
			<div class="partner-card__logo<?php echo $logo_bg ? ' partner-card__logo--bg' : ''; ?>"<?php if ( $logo_bg ) : ?> style="background: <?php echo esc_attr( (string) $color ); ?>"<?php endif; ?>>
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( (string) $name ); ?>" width="172" height="34" />
			</div>
		<?php else :
			$placeholder = 'world-bank' === $id ? 'WB' : ( 'cefta' === $id ? 'CEFTA' : ( 'ministry-finance' === $id ? 'MFE' : 'NAIS' ) ); ?>
			<div class="partner-card__placeholder" style="background: <?php echo esc_attr( (string) $color ); ?>">
				<?php echo esc_html( $placeholder ); ?>
			</div>
		<?php endif; ?>
		<h3 class="partner-card__name"><?php echo esc_html( (string) $name ); ?></h3>
		<p class="partner-card__description"><?php echo esc_html( (string) $desc ); ?></p>
	</div>
	<?php
};
?>
<section class="section">
	<div class="container">
		<div data-reveal style="display:flex; align-items:center; gap:0.75rem; margin-bottom:2rem">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary)" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/></svg>
			<h2 class="section-heading__title" style="text-align:left"><?php echo esc_html( nsw_theme_t( 'partnersPage.internationalTitle', 'International Partners' ) ); ?></h2>
		</div>
		<div class="grid grid--sm-2">
			<?php
			$i = 0;
			foreach ( $international as $partner ) : $i++; ?>
				<div data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>"><?php $render_partner_card( $partner, $locale ); ?></div>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="section section--muted">
	<div class="container">
		<div data-reveal style="display:flex; align-items:center; gap:0.75rem; margin-bottom:2rem">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary)" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			<h2 class="section-heading__title" style="text-align:left"><?php echo esc_html( nsw_theme_t( 'partnersPage.governmentTitle', 'Government Partners' ) ); ?></h2>
		</div>
		<div class="grid grid--sm-2">
			<?php
			$i = 0;
			foreach ( $government as $partner ) : $i++; ?>
				<div data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>"><?php $render_partner_card( $partner, $locale ); ?></div>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="section">
	<div class="container" data-reveal>
		<div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.5rem">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary)" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
			<h2 class="section-heading__title" style="text-align:left"><?php echo esc_html( $private_title ); ?></h2>
		</div>
		<p class="section-heading__lede" style="text-align:left; max-width: none; margin-bottom: 1rem"><?php echo esc_html( $private_desc ); ?></p>
		<?php if ( ! empty( $private_actors ) ) : ?>
			<ul style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem">
				<?php foreach ( $private_actors as $actor ) : ?>
					<li style="display:flex; align-items:flex-start; gap:0.75rem">
						<span style="display:inline-block; width:0.5rem; height:0.5rem; border-radius:9999px; background: var(--primary); margin-top:0.625rem; flex:none"></span>
						<span style="color: var(--muted-foreground)"><?php echo esc_html( (string) $actor ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p style="color: var(--muted-foreground)"><?php echo esc_html( $private_benefits ); ?></p>
	</div>
</section>
