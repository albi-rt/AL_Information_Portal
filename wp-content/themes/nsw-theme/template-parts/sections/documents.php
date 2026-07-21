<?php
/**
 * Documents page body — rendered by the nsw-theme/documents dynamic block.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$locale    = nsw_theme_current_locale();
$documents = nsw_theme_get_documents();

$category_meta = array(
	'legal'     => array(
		'label' => nsw_theme_t( 'documentsPage.legal', 'Legal Documents' ),
		// Lucide FileText
		'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
	),
	'guides'    => array(
		'label' => nsw_theme_t( 'documentsPage.guides', 'User Guides' ),
		// Lucide BookOpen
		'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
	),
	'technical' => array(
		'label' => nsw_theme_t( 'documentsPage.technical', 'Technical Documents' ),
		// Lucide Code
		'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
	),
	'templates' => array(
		'label' => nsw_theme_t( 'documentsPage.templates', 'Templates & Forms' ),
		// Lucide FileSpreadsheet
		'icon'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M8 17h2"/><path d="M14 17h2"/></svg>',
	),
);

$coming_soon_label = nsw_theme_t( 'common.comingSoon', 'Coming Soon' );
?>
<section style="padding-block: 2rem">
	<div class="container">
		<div class="doc-note" data-reveal>
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary); flex:none; margin-top:0.125rem" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
			<p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0"><?php echo esc_html( nsw_theme_t( 'documentsPage.note', 'More documents will be added as the NSW platform becomes operational.' ) ); ?></p>
		</div>
	</div>
</section>

<?php foreach ( $category_meta as $cat => $meta ) :
	$rows = array_values( array_filter( $documents, function ( $d ) use ( $cat ) { return ( $d['category'] ?? '' ) === $cat; } ) );
	if ( empty( $rows ) ) {
		continue;
	} ?>
	<section class="section--tight">
		<div class="container">
			<div class="icon-heading icon-heading--sm" data-reveal>
				<span class="icon-heading__icon"><?php echo $meta['icon']; // svg ?></span>
				<h2 class="icon-heading__title"><?php echo esc_html( (string) $meta['label'] ); ?></h2>
			</div>
			<div class="grid grid--lg-3">
				<?php
				$i = 0;
				foreach ( $rows as $doc ) :
					$i++;
					$title = nsw_theme_localized( $doc['title'] ?? '', $locale );
					$desc  = nsw_theme_localized( $doc['description'] ?? '', $locale );
					$type  = (string) ( $doc['fileType'] ?? '' );
					$size  = (string) ( $doc['size'] ?? '' );
					$url   = (string) ( $doc['url'] ?? '' );
					/* External URLs open in a new tab (third-party host may want to render
					   in-browser, and `download` only works same-origin anyway). Uploaded
					   files go through the same-origin `download` attribute so the browser
					   saves them directly. */
					$is_external = $url && ! preg_match( '#^/|wp-content/uploads/#', $url ) && false === strpos( $url, home_url() );
					?>
					<div class="card doc-card" data-reveal data-reveal-delay="<?php echo (int) min( $i, 3 ); ?>">
						<div class="doc-card__head">
							<h3 class="doc-card__title line-clamp-2"><?php echo esc_html( (string) $title ); ?></h3>
							<?php if ( $type ) : ?><span class="badge badge--outline doc-card__type"><?php echo esc_html( $type ); ?></span><?php endif; ?>
						</div>
						<p class="doc-card__description line-clamp-3"><?php echo esc_html( (string) $desc ); ?></p>
						<div class="doc-card__foot">
							<span class="doc-card__size"><?php echo esc_html( $size ); ?></span>
							<?php if ( $url ) : ?>
								<a class="btn btn--outline doc-card__download" href="<?php echo esc_url( $url ); ?>"<?php echo $is_external ? ' target="_blank" rel="noopener"' : ' download'; ?>>
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
									<?php echo esc_html( nsw_theme_t( 'common.download', 'Download' ) ); ?>
								</a>
							<?php else : ?>
								<button type="button" class="btn btn--outline doc-card__download" disabled aria-disabled="true">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
									<?php echo esc_html( $coming_soon_label ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endforeach; ?>
