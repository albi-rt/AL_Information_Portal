<?php
/**
 * Per-post meta:
 *  - Event: date / time / location / type (+ Description = post excerpt).
 *  - Document: file attachment / type / size (+ Description = post excerpt).
 *  - Agency: per-language name / abbreviation / description / documents,
 *    plus shared id, brand colour and website (logo = featured image).
 *  - Partner: per-language name / description, plus shared id, type, colour,
 *    website and logo-background flag (logo = featured image).
 *
 * Agencies and Partners are Polylang-translated (one post per language); the
 * shared fields are kept in sync by the pll_copy_post_metas filter in
 * inc/post-types.php.
 *
 * Per-post language is owned entirely by Polylang (Languages panel); the theme
 * reads it via nsw_theme_post_locale(). There is no custom "Content locale"
 * control.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --------------------------------------------------------------------------
 * Description (Documents + Events)
 *
 * Documents and Events carry no body content — their public description is the
 * post excerpt. With 'editor' support removed they use the classic edit screen,
 * so we surface the excerpt as a plainly-labelled "Description" box in the main
 * column (the core Excerpt box is hidden by default and removed below). The
 * textarea is named `excerpt`, so WordPress core saves it to post_excerpt with
 * no custom handler.
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		foreach ( array( NSW_THEME_CPT_DOCUMENT, NSW_THEME_CPT_EVENT ) as $pt ) {
			add_meta_box(
				'nsw_theme_description',
				__( 'Description', 'nsw-theme' ),
				'nsw_theme_render_description_meta_box',
				$pt,
				'normal',
				'high'
			);
		}
	}
);

function nsw_theme_render_description_meta_box( WP_Post $post ): void {
	?>
	<p class="description" style="margin-top:0">
		<?php esc_html_e( 'Shown on the public listing. Stored as the post excerpt.', 'nsw-theme' ); ?>
	</p>
	<textarea name="excerpt" id="excerpt" class="widefat" rows="4"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
	<?php
}

/**
 * Adding 'custom-fields' support (for REST meta access) and dropping the block
 * editor makes WordPress render its generic "Custom Fields" and "Excerpt" boxes.
 * Our own boxes cover this UI, so remove the defaults to keep the screens clean.
 * Runs after core registers the default boxes.
 */
add_action(
	'add_meta_boxes',
	function () {
		foreach ( array( NSW_THEME_CPT_AGENCY, NSW_THEME_CPT_PARTNER, NSW_THEME_CPT_DOCUMENT, NSW_THEME_CPT_EVENT ) as $pt ) {
			remove_meta_box( 'postcustom', $pt, 'normal' );
		}
		remove_meta_box( 'postexcerpt', NSW_THEME_CPT_DOCUMENT, 'normal' );
		remove_meta_box( 'postexcerpt', NSW_THEME_CPT_EVENT, 'normal' );
	},
	20
);

/* --------------------------------------------------------------------------
 * Event details
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'nsw_theme_event_details',
			__( 'Event details', 'nsw-theme' ),
			'nsw_theme_render_event_meta_box',
			NSW_THEME_CPT_EVENT,
			'side',
			'high'
		);
	}
);

function nsw_theme_render_event_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'nsw_theme_event_meta', 'nsw_theme_event_meta_nonce' );
	$date     = get_post_meta( $post->ID, '_nsw_theme_event_date',     true );
	$time     = get_post_meta( $post->ID, '_nsw_theme_event_time',     true );
	$location = get_post_meta( $post->ID, '_nsw_theme_event_location', true );
	$type     = get_post_meta( $post->ID, '_nsw_theme_event_type',     true );
	?>
	<p>
		<label for="nsw_theme_event_date"><strong><?php esc_html_e( 'Date', 'nsw-theme' ); ?></strong></label><br>
		<input type="date" id="nsw_theme_event_date" name="nsw_theme_event_date" value="<?php echo esc_attr( $date ); ?>" class="widefat">
	</p>
	<p>
		<label for="nsw_theme_event_time"><strong><?php esc_html_e( 'Time', 'nsw-theme' ); ?></strong></label><br>
		<input type="time" id="nsw_theme_event_time" name="nsw_theme_event_time" value="<?php echo esc_attr( $time ); ?>" class="widefat">
	</p>
	<p>
		<label for="nsw_theme_event_location"><strong><?php esc_html_e( 'Location', 'nsw-theme' ); ?></strong></label><br>
		<input type="text" id="nsw_theme_event_location" name="nsw_theme_event_location" value="<?php echo esc_attr( $location ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Tirana, Ministry of Economy', 'nsw-theme' ); ?>">
	</p>
	<p>
		<label for="nsw_theme_event_type"><strong><?php esc_html_e( 'Type', 'nsw-theme' ); ?></strong></label><br>
		<select id="nsw_theme_event_type" name="nsw_theme_event_type" class="widefat">
			<option value=""           <?php selected( $type, '' ); ?>><?php esc_html_e( '— Select —', 'nsw-theme' ); ?></option>
			<option value="workshop"   <?php selected( $type, 'workshop' ); ?>><?php esc_html_e( 'Workshop',   'nsw-theme' ); ?></option>
			<option value="meeting"    <?php selected( $type, 'meeting' ); ?>><?php esc_html_e( 'Meeting',    'nsw-theme' ); ?></option>
			<option value="conference" <?php selected( $type, 'conference' ); ?>><?php esc_html_e( 'Conference', 'nsw-theme' ); ?></option>
			<option value="training"   <?php selected( $type, 'training' ); ?>><?php esc_html_e( 'Training',   'nsw-theme' ); ?></option>
		</select>
	</p>
	<?php
}

add_action( 'save_post_' . NSW_THEME_CPT_EVENT, 'nsw_theme_save_event_meta', 10, 2 );
function nsw_theme_save_event_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['nsw_theme_event_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_theme_event_meta_nonce'] ) ), 'nsw_theme_event_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_nsw_theme_event_date'     => 'nsw_theme_event_date',
		'_nsw_theme_event_time'     => 'nsw_theme_event_time',
		'_nsw_theme_event_location' => 'nsw_theme_event_location',
		'_nsw_theme_event_type'     => 'nsw_theme_event_type',
	);

	foreach ( $fields as $meta_key => $field ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}
}

/* --------------------------------------------------------------------------
 * Service details
 *
 * Which trade operations a service applies to: import / export / transit, as
 * checkboxes (a service can apply to more than one). Stored as a single
 * comma-separated meta value (_nsw_theme_service_type), matching the whitelist
 * sanitizer registered in inc/post-types.php.
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'nsw_theme_service_details',
			__( 'Service details', 'nsw-theme' ),
			'nsw_theme_render_service_meta_box',
			NSW_THEME_CPT_SERVICE,
			'side',
			'high'
		);
	}
);

function nsw_theme_render_service_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'nsw_theme_service_meta', 'nsw_theme_service_meta_nonce' );
	$raw      = (string) get_post_meta( $post->ID, '_nsw_theme_service_type', true );
	$selected = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	$options  = array(
		'import'  => nsw_theme_t( 'servicesPage.types.import' ),
		'export'  => nsw_theme_t( 'servicesPage.types.export' ),
		'transit' => nsw_theme_t( 'servicesPage.types.transit' ),
	);
	?>
	<p class="description" style="margin-top:0">
		<?php esc_html_e( 'Which trade operations this service applies to. Tick all that apply.', 'nsw-theme' ); ?>
	</p>
	<?php foreach ( $options as $value => $label ) : ?>
		<p style="margin:6px 0">
			<label>
				<input type="checkbox" name="nsw_theme_service_type[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $selected, true ) ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		</p>
	<?php endforeach; ?>
	<?php
}

add_action( 'save_post_' . NSW_THEME_CPT_SERVICE, 'nsw_theme_save_service_meta', 10, 2 );
function nsw_theme_save_service_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['nsw_theme_service_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_theme_service_meta_nonce'] ) ), 'nsw_theme_service_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$allowed = array( 'import', 'export', 'transit' );
	$posted  = isset( $_POST['nsw_theme_service_type'] ) ? (array) wp_unslash( $_POST['nsw_theme_service_type'] ) : array();
	$posted  = array_map( 'sanitize_text_field', $posted );
	$csv     = implode( ',', array_values( array_intersect( $allowed, $posted ) ) );
	if ( '' === $csv ) {
		delete_post_meta( $post_id, '_nsw_theme_service_type' );
	} else {
		update_post_meta( $post_id, '_nsw_theme_service_type', $csv );
	}
}

/* --------------------------------------------------------------------------
 * Document file + size
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'nsw_theme_document_file',
			__( 'Document file', 'nsw-theme' ),
			'nsw_theme_render_document_meta_box',
			NSW_THEME_CPT_DOCUMENT,
			'side',
			'high'
		);
	}
);

function nsw_theme_render_document_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'nsw_theme_document_meta', 'nsw_theme_document_meta_nonce' );
	$file_id   = (int) get_post_meta( $post->ID, '_nsw_theme_doc_file_id',   true );
	$file_type = get_post_meta( $post->ID, '_nsw_theme_doc_file_type', true );
	$size      = get_post_meta( $post->ID, '_nsw_theme_doc_size',     true );
	$file_url  = $file_id ? wp_get_attachment_url( $file_id ) : '';

	wp_enqueue_media();
	?>
	<p>
		<label><strong><?php esc_html_e( 'Attached file', 'nsw-theme' ); ?></strong></label><br>
		<input type="hidden" id="nsw_theme_doc_file_id" name="nsw_theme_doc_file_id" value="<?php echo esc_attr( (string) $file_id ); ?>">
		<span id="nsw_theme_doc_file_preview" style="display:block;margin:6px 0;font-size:12px;color:#555;">
			<?php echo $file_url ? esc_html( wp_basename( $file_url ) ) : esc_html__( 'No file selected.', 'nsw-theme' ); ?>
		</span>
		<button type="button" class="button" id="nsw_theme_doc_file_pick"><?php esc_html_e( 'Choose file', 'nsw-theme' ); ?></button>
		<button type="button" class="button-link" id="nsw_theme_doc_file_clear" style="margin-left:8px;<?php echo $file_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'nsw-theme' ); ?></button>
	</p>
	<p>
		<label for="nsw_theme_doc_file_type"><strong><?php esc_html_e( 'File type label', 'nsw-theme' ); ?></strong></label><br>
		<input type="text" id="nsw_theme_doc_file_type" name="nsw_theme_doc_file_type" value="<?php echo esc_attr( $file_type ); ?>" class="widefat" placeholder="PDF, DOCX, XLSX&hellip;">
	</p>
	<p>
		<label for="nsw_theme_doc_size"><strong><?php esc_html_e( 'Size (display)', 'nsw-theme' ); ?></strong></label><br>
		<input type="text" id="nsw_theme_doc_size" name="nsw_theme_doc_size" value="<?php echo esc_attr( $size ); ?>" class="widefat" placeholder="1.2 MB">
	</p>
	<script>
	(function(){
		const pick  = document.getElementById('nsw_theme_doc_file_pick');
		const clear = document.getElementById('nsw_theme_doc_file_clear');
		const input = document.getElementById('nsw_theme_doc_file_id');
		const label = document.getElementById('nsw_theme_doc_file_preview');
		let frame;
		pick.addEventListener('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title:  <?php echo wp_json_encode( __( 'Select document', 'nsw-theme' ) ); ?>,
				button: { text: <?php echo wp_json_encode( __( 'Use this file', 'nsw-theme' ) ); ?> },
				multiple: false
			});
			frame.on('select', function(){
				const file = frame.state().get('selection').first().toJSON();
				input.value = file.id;
				label.textContent = file.filename;
				clear.style.display = '';
			});
			frame.open();
		});
		clear.addEventListener('click', function(e){
			e.preventDefault();
			input.value = '';
			label.textContent = <?php echo wp_json_encode( __( 'No file selected.', 'nsw-theme' ) ); ?>;
			clear.style.display = 'none';
		});
	})();
	</script>
	<?php
}

add_action( 'save_post_' . NSW_THEME_CPT_DOCUMENT, 'nsw_theme_save_document_meta', 10, 2 );
function nsw_theme_save_document_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['nsw_theme_document_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_theme_document_meta_nonce'] ) ), 'nsw_theme_document_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$file_id = isset( $_POST['nsw_theme_doc_file_id'] ) ? absint( $_POST['nsw_theme_doc_file_id'] ) : 0;
	if ( $file_id > 0 ) {
		update_post_meta( $post_id, '_nsw_theme_doc_file_id', $file_id );
	} else {
		delete_post_meta( $post_id, '_nsw_theme_doc_file_id' );
	}

	foreach ( array( '_nsw_theme_doc_file_type' => 'nsw_theme_doc_file_type', '_nsw_theme_doc_size' => 'nsw_theme_doc_size' ) as $meta => $field ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta );
		} else {
			update_post_meta( $post_id, $meta, $value );
		}
	}
}

/* --------------------------------------------------------------------------
 * Agency details (bilingual). Logo is the post's featured image.
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'nsw_theme_agency_details',
			__( 'Agency details', 'nsw-theme' ),
			'nsw_theme_render_agency_meta_box',
			NSW_THEME_CPT_AGENCY,
			'normal',
			'high'
		);
	}
);

function nsw_theme_render_agency_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'nsw_theme_agency_meta', 'nsw_theme_agency_meta_nonce' );

	$get = static function ( string $key ) use ( $post ): string {
		return (string) get_post_meta( $post->ID, $key, true );
	};

	$id      = $get( '_nsw_theme_agency_id' );
	$abbr    = $get( '_nsw_theme_agency_abbr' );
	$name    = $get( '_nsw_theme_agency_name' );
	$desc    = $get( '_nsw_theme_agency_desc' );
	$docs    = $get( '_nsw_theme_agency_docs' );
	$color   = $get( '_nsw_theme_agency_color' );
	$website = $get( '_nsw_theme_agency_website' );
	?>
	<style>
		.nsw-theme-agency-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-top:4px}
		.nsw-theme-agency-grid .full{grid-column:1 / -1}
		.nsw-theme-agency-grid label{display:block;font-weight:600;margin-bottom:3px}
		.nsw-theme-agency-grid textarea{min-height:80px}
		.nsw-theme-agency-hint{color:#555;font-size:12px;margin:2px 0 0}
	</style>
	<p class="nsw-theme-agency-hint">
		<?php esc_html_e( 'This edits the current post\'s language — use the Language panel to switch to (or create) the other language. Name, abbreviation, description and documents are per-language; ID, colour, website and the logo (featured image) are shared across translations.', 'nsw-theme' ); ?>
	</p>
	<div class="nsw-theme-agency-grid">
		<p class="full">
			<label for="nsw_theme_agency_id"><?php esc_html_e( 'Agency ID (slug)', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_agency_id" name="nsw_theme_agency_id" value="<?php echo esc_attr( $id ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. akshi', 'nsw-theme' ); ?>">
			<span class="nsw-theme-agency-hint"><?php esc_html_e( 'Shared across languages. Defaults to the post slug if left blank.', 'nsw-theme' ); ?></span>
		</p>

		<p>
			<label for="nsw_theme_agency_name"><?php esc_html_e( 'Name', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_agency_name" name="nsw_theme_agency_name" value="<?php echo esc_attr( $name ); ?>" class="widefat">
		</p>
		<p>
			<label for="nsw_theme_agency_abbr"><?php esc_html_e( 'Abbreviation', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_agency_abbr" name="nsw_theme_agency_abbr" value="<?php echo esc_attr( $abbr ); ?>" class="widefat">
		</p>

		<p class="full">
			<label for="nsw_theme_agency_desc"><?php esc_html_e( 'Description', 'nsw-theme' ); ?></label>
			<textarea id="nsw_theme_agency_desc" name="nsw_theme_agency_desc" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>

		<p class="full">
			<label for="nsw_theme_agency_docs"><?php esc_html_e( 'Documents / services', 'nsw-theme' ); ?></label>
			<textarea id="nsw_theme_agency_docs" name="nsw_theme_agency_docs" class="widefat" placeholder="<?php esc_attr_e( 'One per line', 'nsw-theme' ); ?>"><?php echo esc_textarea( $docs ); ?></textarea>
		</p>

		<p>
			<label for="nsw_theme_agency_color"><?php esc_html_e( 'Brand colour (shared)', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_agency_color" name="nsw_theme_agency_color" value="<?php echo esc_attr( $color ); ?>" class="widefat" placeholder="#e30613">
		</p>
		<p>
			<label for="nsw_theme_agency_website"><?php esc_html_e( 'Website (shared)', 'nsw-theme' ); ?></label>
			<input type="url" id="nsw_theme_agency_website" name="nsw_theme_agency_website" value="<?php echo esc_attr( $website ); ?>" class="widefat" placeholder="https://example.gov.al">
		</p>
	</div>
	<?php
}

add_action( 'save_post_' . NSW_THEME_CPT_AGENCY, 'nsw_theme_save_agency_meta', 10, 2 );
function nsw_theme_save_agency_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['nsw_theme_agency_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_theme_agency_meta_nonce'] ) ), 'nsw_theme_agency_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Plain text fields.
	$text_fields = array(
		'_nsw_theme_agency_id'    => 'nsw_theme_agency_id',
		'_nsw_theme_agency_abbr'  => 'nsw_theme_agency_abbr',
		'_nsw_theme_agency_name'  => 'nsw_theme_agency_name',
		'_nsw_theme_agency_color' => 'nsw_theme_agency_color',
	);
	foreach ( $text_fields as $meta_key => $field ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	// Multi-line fields (description + documents list).
	$textarea_fields = array(
		'_nsw_theme_agency_desc' => 'nsw_theme_agency_desc',
		'_nsw_theme_agency_docs' => 'nsw_theme_agency_docs',
	);
	foreach ( $textarea_fields as $meta_key => $field ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	// Website (URL).
	$website = isset( $_POST['nsw_theme_agency_website'] ) ? esc_url_raw( wp_unslash( $_POST['nsw_theme_agency_website'] ) ) : '';
	if ( '' === $website ) {
		delete_post_meta( $post_id, '_nsw_theme_agency_website' );
	} else {
		update_post_meta( $post_id, '_nsw_theme_agency_website', $website );
	}
}

/* --------------------------------------------------------------------------
 * Partner details (bilingual). Logo is the post's featured image.
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'nsw_theme_partner_details',
			__( 'Partner details', 'nsw-theme' ),
			'nsw_theme_render_partner_meta_box',
			NSW_THEME_CPT_PARTNER,
			'normal',
			'high'
		);
	}
);

function nsw_theme_render_partner_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'nsw_theme_partner_meta', 'nsw_theme_partner_meta_nonce' );

	$get = static function ( string $key ) use ( $post ): string {
		return (string) get_post_meta( $post->ID, $key, true );
	};

	$id      = $get( '_nsw_theme_partner_id' );
	$type    = $get( '_nsw_theme_partner_type' );
	$name    = $get( '_nsw_theme_partner_name' );
	$desc    = $get( '_nsw_theme_partner_desc' );
	$color   = $get( '_nsw_theme_partner_color' );
	$website = $get( '_nsw_theme_partner_website' );
	$logo_bg = '1' === $get( '_nsw_theme_partner_logo_bg' );
	?>
	<style>
		.nsw-theme-partner-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-top:4px}
		.nsw-theme-partner-grid .full{grid-column:1 / -1}
		.nsw-theme-partner-grid label{display:block;font-weight:600;margin-bottom:3px}
		.nsw-theme-partner-grid textarea{min-height:80px}
	</style>
	<p class="description">
		<?php esc_html_e( 'This edits the current post\'s language — use the Language panel to switch to (or create) the other language. Name and description are per-language; ID, type, colour, website, logo background and the logo (featured image) are shared across translations.', 'nsw-theme' ); ?>
	</p>
	<div class="nsw-theme-partner-grid">
		<p>
			<label for="nsw_theme_partner_id"><?php esc_html_e( 'Partner ID (slug, shared)', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_partner_id" name="nsw_theme_partner_id" value="<?php echo esc_attr( $id ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. world-bank', 'nsw-theme' ); ?>">
		</p>
		<p>
			<label for="nsw_theme_partner_type"><?php esc_html_e( 'Type (shared)', 'nsw-theme' ); ?></label>
			<select id="nsw_theme_partner_type" name="nsw_theme_partner_type" class="widefat">
				<option value="international" <?php selected( $type, 'international' ); ?>><?php esc_html_e( 'International', 'nsw-theme' ); ?></option>
				<option value="government"    <?php selected( $type, 'government' ); ?>><?php esc_html_e( 'Government', 'nsw-theme' ); ?></option>
			</select>
		</p>

		<p class="full">
			<label for="nsw_theme_partner_name"><?php esc_html_e( 'Name', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_partner_name" name="nsw_theme_partner_name" value="<?php echo esc_attr( $name ); ?>" class="widefat">
		</p>

		<p class="full">
			<label for="nsw_theme_partner_desc"><?php esc_html_e( 'Description', 'nsw-theme' ); ?></label>
			<textarea id="nsw_theme_partner_desc" name="nsw_theme_partner_desc" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>

		<p>
			<label for="nsw_theme_partner_color"><?php esc_html_e( 'Brand colour (shared)', 'nsw-theme' ); ?></label>
			<input type="text" id="nsw_theme_partner_color" name="nsw_theme_partner_color" value="<?php echo esc_attr( $color ); ?>" class="widefat" placeholder="#1a73e8">
		</p>
		<p>
			<label for="nsw_theme_partner_website"><?php esc_html_e( 'Website (shared)', 'nsw-theme' ); ?></label>
			<input type="url" id="nsw_theme_partner_website" name="nsw_theme_partner_website" value="<?php echo esc_attr( $website ); ?>" class="widefat" placeholder="https://example.org">
		</p>
		<p class="full">
			<label><input type="checkbox" name="nsw_theme_partner_logo_bg" value="1" <?php checked( $logo_bg ); ?>> <?php esc_html_e( 'Show logo on the brand-colour background (shared)', 'nsw-theme' ); ?></label>
		</p>
	</div>
	<?php
}

add_action( 'save_post_' . NSW_THEME_CPT_PARTNER, 'nsw_theme_save_partner_meta', 10, 2 );
function nsw_theme_save_partner_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['nsw_theme_partner_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_theme_partner_meta_nonce'] ) ), 'nsw_theme_partner_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$text_fields = array(
		'_nsw_theme_partner_id'    => 'nsw_theme_partner_id',
		'_nsw_theme_partner_name'  => 'nsw_theme_partner_name',
		'_nsw_theme_partner_color' => 'nsw_theme_partner_color',
	);
	foreach ( $text_fields as $meta_key => $field ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	$type = isset( $_POST['nsw_theme_partner_type'] ) ? sanitize_key( wp_unslash( $_POST['nsw_theme_partner_type'] ) ) : '';
	update_post_meta( $post_id, '_nsw_theme_partner_type', in_array( $type, array( 'international', 'government' ), true ) ? $type : 'international' );

	$desc = isset( $_POST['nsw_theme_partner_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nsw_theme_partner_desc'] ) ) : '';
	if ( '' === $desc ) {
		delete_post_meta( $post_id, '_nsw_theme_partner_desc' );
	} else {
		update_post_meta( $post_id, '_nsw_theme_partner_desc', $desc );
	}

	$website = isset( $_POST['nsw_theme_partner_website'] ) ? esc_url_raw( wp_unslash( $_POST['nsw_theme_partner_website'] ) ) : '';
	if ( '' === $website ) {
		delete_post_meta( $post_id, '_nsw_theme_partner_website' );
	} else {
		update_post_meta( $post_id, '_nsw_theme_partner_website', $website );
	}

	if ( ! empty( $_POST['nsw_theme_partner_logo_bg'] ) ) {
		update_post_meta( $post_id, '_nsw_theme_partner_logo_bg', '1' );
	} else {
		delete_post_meta( $post_id, '_nsw_theme_partner_logo_bg' );
	}
}
