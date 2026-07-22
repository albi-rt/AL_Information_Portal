<?php
/**
 * Contact page body — rendered by the nsw-theme/contact-form dynamic block.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cats = nsw_theme_dot_get( nsw_theme_get_content(), 'contactPage.form.categoryOptions' );
$cats = is_array( $cats ) ? $cats : array();

// Agency dropdown is built from the nsw_agency CPTs (Polylang scopes the query
// to the current language, so names come out localized), wrapped by a General
// default and an Other fallback. No hardcoded agency list.
$ags = array( 'general' => __( 'General', 'nsw-theme' ) );
foreach ( get_posts( array(
	'post_type'      => 'nsw_agency',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'menu_order title',
	'order'          => 'ASC',
	'no_found_rows'  => true,
) ) as $nsw_agency_post ) {
	$slug = get_post_meta( $nsw_agency_post->ID, '_nsw_theme_agency_id', true );
	$slug = '' !== $slug ? $slug : $nsw_agency_post->post_name;
	$name = get_post_meta( $nsw_agency_post->ID, '_nsw_theme_agency_name', true );
	$ags[ (string) $slug ] = '' !== $name ? $name : get_the_title( $nsw_agency_post );
}
$ags['other'] = __( 'Other', 'nsw-theme' );
?>
<section class="section">
	<div class="container">
		<div class="contact-grid">
			<div data-reveal>
				<h2 class="contact__heading"><?php echo esc_html( nsw_theme_t( 'contactPage.formTitle', 'Send a message' ) ); ?></h2>

				<form class="form" data-contact-form novalidate>
					<div class="form__honeypot" aria-hidden="true" tabindex="-1">
						<label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" /></label>
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-fullName"><?php echo esc_html( nsw_theme_t( 'contactPage.form.fullName', 'Full Name' ) ); ?> *</label>
						<input id="contact-fullName" type="text" name="fullName" required minlength="2" maxlength="120" autocomplete="name" />
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-email"><?php echo esc_html( nsw_theme_t( 'contactPage.form.email', 'Email Address' ) ); ?> *</label>
						<input id="contact-email" type="email" name="email" required maxlength="200" autocomplete="email" inputmode="email" />
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-organization"><?php echo esc_html( nsw_theme_t( 'contactPage.form.organization', 'Organization / Company' ) ); ?></label>
						<input id="contact-organization" type="text" name="organization" maxlength="150" autocomplete="organization" />
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-category"><?php echo esc_html( nsw_theme_t( 'contactPage.form.category', 'Category' ) ); ?> *</label>
						<?php
						nsw_theme_render_select( array(
							'id'          => 'contact-category',
							'name'        => 'category',
							'options'     => $cats,
							'placeholder' => nsw_theme_t( 'contactPage.form.category', 'Category' ),
							'required'    => true,
						) );
						?>
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-agency"><?php echo esc_html( nsw_theme_t( 'contactPage.form.agency', 'Relevant Agency' ) ); ?></label>
						<?php
						nsw_theme_render_select( array(
							'id'          => 'contact-agency',
							'name'        => 'agency',
							'options'     => $ags,
							'placeholder' => nsw_theme_t( 'contactPage.form.agency', 'Relevant Agency' ),
						) );
						?>
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-subject"><?php echo esc_html( nsw_theme_t( 'contactPage.form.subject', 'Subject' ) ); ?> *</label>
						<input id="contact-subject" type="text" name="subject" required minlength="3" maxlength="200" autocomplete="off" />
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-message"><?php echo esc_html( nsw_theme_t( 'contactPage.form.message', 'Message' ) ); ?> *</label>
						<textarea id="contact-message" name="message" required minlength="10" maxlength="5000" rows="6"></textarea>
					</div>

					<div class="form__field">
						<label class="form__label" for="contact-attachment"><?php echo esc_html( nsw_theme_t( 'contactPage.form.attachment', 'Attachment (optional, max 10MB)' ) ); ?></label>
						<input id="contact-attachment" type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" />
					</div>

					<label class="form__check">
						<input type="checkbox" id="contact-privacy" name="privacy" required />
						<span><?php echo esc_html( nsw_theme_t( 'contactPage.form.privacy', 'I accept the privacy policy and processing of my data' ) ); ?> *</span>
					</label>

					<div class="form__feedback" data-contact-feedback role="status" aria-live="polite"></div>

					<button type="submit" class="btn btn--lg btn--block">
						<svg class="btn__icon btn__icon--send" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4Z"/></svg>
						<svg class="btn__icon btn__icon--loader" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
						<span data-submit-label><?php echo esc_html( nsw_theme_t( 'contactPage.form.submit', 'Send Message' ) ); ?></span>
					</button>
				</form>
			</div>

			<aside data-reveal data-reveal-delay="1">
				<h2 class="contact__heading"><?php echo esc_html( nsw_theme_t( 'contactPage.infoTitle', 'Contact Information' ) ); ?></h2>

				<ul class="contact-info-list">
					<li class="contact-info__row">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
						<span><?php echo esc_html( nsw_theme_t( 'contactPage.info.address', '' ) ); ?></span>
					</li>
					<li class="contact-info__row">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
						<a href="mailto:<?php echo esc_attr( nsw_theme_t( 'contactPage.info.email', 'info@nsw.al' ) ); ?>"><?php echo esc_html( nsw_theme_t( 'contactPage.info.email', 'info@nsw.al' ) ); ?></a>
					</li>
					<li class="contact-info__row">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<div class="contact-info__hours">
							<div class="contact-info__hours-label"><?php echo esc_html( nsw_theme_t( 'contactPage.info.hoursLabel', 'Working Hours' ) ); ?></div>
							<div><?php echo esc_html( nsw_theme_t( 'contactPage.info.hours', 'Mon – Fri, 08:00–16:00' ) ); ?></div>
						</div>
					</li>
				</ul>

				<div class="contact-map">
					<iframe
						src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1753.1284235072433!2d19.81713309557829!3d41.32572489349983!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x135031001c9c66e9%3A0x894cb8df07a169a9!2sMinistria%20e%20Ekonomis%C3%AB%2C%20Kultur%C3%ABs%20dhe%20Inovacionit!5e1!3m2!1sen!2s!4v1771969638317!5m2!1sen!2s"
						width="600"
						height="256"
						style="border:0; width:100%; height:16rem"
						loading="lazy"
						referrerpolicy="no-referrer-when-downgrade"
						allowfullscreen
						title="<?php esc_attr_e( 'NSW office location', 'nsw-theme' ); ?>"
					></iframe>
				</div>
			</aside>
		</div>
	</div>
</section>
