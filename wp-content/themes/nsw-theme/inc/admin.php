<?php
/**
 * Admin tweaks:
 *  - Contact-form recipient setting under Settings → General.
 *  - Custom list-table columns for Events and Documents (surface meta fields).
 *  - Friendly dashboard widget for Agency users.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --------------------------------------------------------------------------
 * Contact-form recipient setting (Settings → General).
 * -------------------------------------------------------------------------- */

add_action(
	'admin_init',
	function () {
		register_setting(
			'general',
			'nsw_theme_contact_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_option( 'admin_email' ),
			)
		);

		add_settings_field(
			'nsw_theme_contact_email',
			__( 'NSW contact form recipient', 'nsw-theme' ),
			function () {
				$value = (string) get_option( 'nsw_theme_contact_email', get_option( 'admin_email' ) );
				echo '<input type="email" class="regular-text" name="nsw_theme_contact_email" value="' . esc_attr( $value ) . '" />';
				echo '<p class="description">' . esc_html__( 'Where the contact-form submissions are emailed.', 'nsw-theme' ) . '</p>';
			},
			'general'
		);
	}
);

/* --------------------------------------------------------------------------
 * Event admin columns: surface date / location / type.
 * -------------------------------------------------------------------------- */

add_filter(
	'manage_' . NSW_THEME_CPT_EVENT . '_posts_columns',
	function ( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['nsw_event_date']     = __( 'Date',     'nsw-theme' );
				$new['nsw_event_location'] = __( 'Location', 'nsw-theme' );
				$new['nsw_event_type']     = __( 'Type',     'nsw-theme' );
			}
		}
		return $new;
	}
);

add_action(
	'manage_' . NSW_THEME_CPT_EVENT . '_posts_custom_column',
	function ( $column, $post_id ) {
		switch ( $column ) {
			case 'nsw_event_date':
				echo esc_html( (string) get_post_meta( $post_id, '_nsw_theme_event_date', true ) );
				break;
			case 'nsw_event_location':
				echo esc_html( (string) get_post_meta( $post_id, '_nsw_theme_event_location', true ) );
				break;
			case 'nsw_event_type':
				echo esc_html( (string) get_post_meta( $post_id, '_nsw_theme_event_type', true ) );
				break;
		}
	},
	10,
	2
);

add_filter(
	'manage_edit-' . NSW_THEME_CPT_EVENT . '_sortable_columns',
	function ( $columns ) {
		$columns['nsw_event_date'] = 'nsw_event_date';
		return $columns;
	}
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'nsw_event_date' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_nsw_theme_event_date' );
			$query->set( 'orderby', 'meta_value' );
		}
	}
);

/* --------------------------------------------------------------------------
 * Document admin columns: file type and size.
 * -------------------------------------------------------------------------- */

add_filter(
	'manage_' . NSW_THEME_CPT_DOCUMENT . '_posts_columns',
	function ( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['nsw_doc_type'] = __( 'File type', 'nsw-theme' );
				$new['nsw_doc_size'] = __( 'Size',      'nsw-theme' );
			}
		}
		return $new;
	}
);

add_action(
	'manage_' . NSW_THEME_CPT_DOCUMENT . '_posts_custom_column',
	function ( $column, $post_id ) {
		switch ( $column ) {
			case 'nsw_doc_type':
				echo esc_html( (string) get_post_meta( $post_id, '_nsw_theme_doc_file_type', true ) );
				break;
			case 'nsw_doc_size':
				echo esc_html( (string) get_post_meta( $post_id, '_nsw_theme_doc_size', true ) );
				break;
		}
	},
	10,
	2
);

/* --------------------------------------------------------------------------
 * Agency users — friendly dashboard widget; hide legacy ones.
 * -------------------------------------------------------------------------- */

add_action(
	'wp_dashboard_setup',
	function () {
		if ( ! function_exists( 'nsw_theme_is_agency' ) || ! nsw_theme_is_agency( wp_get_current_user() ) ) {
			return;
		}
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary',     'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity',    'dashboard', 'normal' );
	}
);

add_action(
	'wp_dashboard_setup',
	function () {
		if ( ! function_exists( 'nsw_theme_is_agency' ) || ! nsw_theme_is_agency( wp_get_current_user() ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'nsw_theme_agency_welcome',
			__( 'Welcome', 'nsw-theme' ),
			function () {
				$user = wp_get_current_user();
				?>
				<p><?php
					printf(
						/* translators: %s: display name */
						esc_html__( 'Hello %s — you can add News, Events, and Documents from the menu on the left. Only items you publish yourself appear in your lists.', 'nsw-theme' ),
						esc_html( $user->display_name )
					);
				?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . NSW_THEME_CPT_NEWS ) ); ?>"><?php esc_html_e( 'New News article', 'nsw-theme' ); ?></a>
					<a class="button"               href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . NSW_THEME_CPT_EVENT ) ); ?>"><?php esc_html_e( 'New Event',       'nsw-theme' ); ?></a>
					<a class="button"               href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . NSW_THEME_CPT_DOCUMENT ) ); ?>"><?php esc_html_e( 'New Document',    'nsw-theme' ); ?></a>
				</p>
				<?php
			}
		);
	}
);
