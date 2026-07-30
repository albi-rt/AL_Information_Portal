<?php
/**
 * Agency Officers: scope a user to a single agency so they only see and write
 * that agency's News (core Posts).
 *
 * - Each user can be assigned one agency (a field on their profile).
 * - Each News post carries an agency (auto for officers; a dropdown for admins).
 * - An Agency Officer only sees their agency's News in wp-admin, and can only
 *   edit/delete that agency's News.
 *
 * Agencies are keyed by their stable id (_nsw_theme_agency_id) so the link is
 * language-agnostic across the sq/en agency posts. Admins (manage_options) are
 * never restricted.
 *
 * @package NSW_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Members role slug for agency officers. Auto-detects the common variants;
 * hard-code the return value here if yours differs.
 */
function nsw_agency_officer_role(): string {
	static $slug = null;
	if ( null !== $slug ) {
		return $slug;
	}
	foreach ( array( 'agency_officer', 'agency-officer', 'agency_officers' ) as $candidate ) {
		if ( get_role( $candidate ) ) {
			return $slug = $candidate;
		}
	}
	return $slug = 'agency_officer';
}

/** The agency stable id assigned to a user ('' if none). */
function nsw_user_agency( int $user_id = 0 ): string {
	$user_id = $user_id ?: get_current_user_id();
	return (string) get_user_meta( $user_id, '_nsw_agency', true );
}

/** True if the user is an Agency Officer. Admins are never restricted. */
function nsw_is_agency_officer( ?WP_User $user = null ): bool {
	$user = $user ?: wp_get_current_user();
	if ( ! $user || ! $user->exists() || user_can( $user, 'manage_options' ) ) {
		return false;
	}
	return in_array( nsw_agency_officer_role(), (array) $user->roles, true );
}

/** All agencies as [ stable_id => display_name ], deduped across languages. */
function nsw_agency_choices(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$args = array(
		'post_type'      => 'nsw_agency',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);
	// One language only, so we list 14 agencies rather than the 28 sq/en posts.
	if ( function_exists( 'pll_default_language' ) ) {
		$args['lang'] = pll_default_language( 'slug' );
	}
	$out = array();
	foreach ( get_posts( $args ) as $agency ) {
		$id   = (string) get_post_meta( $agency->ID, '_nsw_theme_agency_id', true );
		$id   = '' !== $id ? $id : $agency->post_name;
		$name = (string) get_post_meta( $agency->ID, '_nsw_theme_agency_name', true );
		$out[ $id ] = '' !== $name ? $name : get_the_title( $agency );
	}
	asort( $out );
	return $cache = $out;
}

/* --------------------------------------------------------------------------
 * 1. News → agency meta
 * -------------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		register_post_meta(
			'post',
			'_nsw_news_agency',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
);

/* --------------------------------------------------------------------------
 * 2. User profile: "Assigned agency" (only admins can set it)
 * -------------------------------------------------------------------------- */

function nsw_render_user_agency_field( $context = null ): void {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	// Profile screens pass a WP_User; the Add New User screen passes a context
	// string. Skip the multisite "add existing user" variant.
	if ( is_string( $context ) && 'add-new-user' !== $context ) {
		return;
	}
	$user_id = ( $context instanceof WP_User ) ? $context->ID : 0;
	$current = $user_id ? nsw_user_agency( $user_id ) : '';
	wp_nonce_field( 'nsw_user_agency', 'nsw_user_agency_nonce' );
	?>
	<h2><?php esc_html_e( 'NSW Agency', 'nsw-theme' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="nsw_agency"><?php esc_html_e( 'Assigned agency', 'nsw-theme' ); ?></label></th>
			<td>
				<select id="nsw_agency" name="nsw_agency">
					<option value=""><?php esc_html_e( '— None —', 'nsw-theme' ); ?></option>
					<?php foreach ( nsw_agency_choices() as $id => $name ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'An Agency Officer can only see and write News for this agency.', 'nsw-theme' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'nsw_render_user_agency_field' );
add_action( 'edit_user_profile', 'nsw_render_user_agency_field' );
add_action( 'user_new_form', 'nsw_render_user_agency_field' );

function nsw_save_user_agency( int $user_id ): void {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	if ( ! isset( $_POST['nsw_user_agency_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_user_agency_nonce'] ) ), 'nsw_user_agency' ) ) {
		return;
	}
	$val = isset( $_POST['nsw_agency'] ) ? sanitize_text_field( wp_unslash( $_POST['nsw_agency'] ) ) : '';
	if ( '' === $val ) {
		delete_user_meta( $user_id, '_nsw_agency' );
	} else {
		update_user_meta( $user_id, '_nsw_agency', $val );
	}
}
add_action( 'personal_options_update', 'nsw_save_user_agency' );
add_action( 'edit_user_profile_update', 'nsw_save_user_agency' );
add_action( 'user_register', 'nsw_save_user_agency' );

/* --------------------------------------------------------------------------
 * 3. News agency meta box (admins/editors choose; officers don't — auto-set)
 * -------------------------------------------------------------------------- */

add_action(
	'add_meta_boxes_post',
	function () {
		if ( nsw_is_agency_officer() ) {
			return; // officers can't pick — it's forced to their agency on save
		}
		add_meta_box( 'nsw_news_agency', __( 'Agency', 'nsw-theme' ), 'nsw_render_news_agency_box', 'post', 'side', 'default' );
	}
);

function nsw_render_news_agency_box( WP_Post $post ): void {
	$current = (string) get_post_meta( $post->ID, '_nsw_news_agency', true );
	wp_nonce_field( 'nsw_news_agency', 'nsw_news_agency_nonce' );
	echo '<select name="nsw_news_agency" style="width:100%">';
	echo '<option value="">' . esc_html__( '— None —', 'nsw-theme' ) . '</option>';
	foreach ( nsw_agency_choices() as $id => $name ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $id ), selected( $current, $id, false ), esc_html( $name ) );
	}
	echo '</select>';
}

/* --------------------------------------------------------------------------
 * 4. On save, stamp the agency
 * -------------------------------------------------------------------------- */

add_action(
	'save_post_post',
	function ( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( nsw_is_agency_officer() ) {
			// Officers: always their own agency, no choice.
			$agency = nsw_user_agency();
			if ( '' !== $agency ) {
				update_post_meta( $post_id, '_nsw_news_agency', $agency );
			}
			return;
		}
		// Admins/editors: from the meta box.
		if ( isset( $_POST['nsw_news_agency_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_news_agency_nonce'] ) ), 'nsw_news_agency' ) ) {
			$val = isset( $_POST['nsw_news_agency'] ) ? sanitize_text_field( wp_unslash( $_POST['nsw_news_agency'] ) ) : '';
			if ( '' === $val ) {
				delete_post_meta( $post_id, '_nsw_news_agency' );
			} else {
				update_post_meta( $post_id, '_nsw_news_agency', $val );
			}
		}
	}
);

/* --------------------------------------------------------------------------
 * 5. Restrict the News list to the officer's agency
 * -------------------------------------------------------------------------- */

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow || 'post' !== ( $query->get( 'post_type' ) ?: 'post' ) ) {
			return;
		}
		if ( ! nsw_is_agency_officer() ) {
			return;
		}
		$agency = nsw_user_agency();
		$meta   = (array) $query->get( 'meta_query' );
		// No agency assigned → match a value that never exists, so they see nothing.
		$meta[] = array(
			'key'     => '_nsw_news_agency',
			'value'   => '' !== $agency ? $agency : '__nsw_no_agency__',
			'compare' => '=',
		);
		$query->set( 'meta_query', $meta );
	}
);

/* --------------------------------------------------------------------------
 * 6. Block editing/deleting another agency's News (even by direct URL)
 *
 * `copy_post` is PublishPress Revisions' entry gate for the "Revise" / submit-
 * revision flow. Once officers lack `edit_published_posts` (see section 7), that
 * flow — not the core Edit link (`edit_post`) — is how they touch PUBLISHED News,
 * so we must scope it to their agency too, or an officer could open a revision on
 * another agency's live post. We run at priority 10 (Revisions maps `copy_post`
 * at priority 5), so returning `do_not_allow` here still vetoes cross-agency.
 * -------------------------------------------------------------------------- */

add_filter(
	'map_meta_cap',
	function ( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'copy_post' ), true ) ) {
			return $caps;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! nsw_is_agency_officer( $user ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			return $caps;
		}
		// Allow the brand-new auto-draft so officers can create News.
		if ( 'auto-draft' === get_post_status( $post_id ) ) {
			return $caps;
		}
		$post_agency  = (string) get_post_meta( $post_id, '_nsw_news_agency', true );
		$their_agency = nsw_user_agency( $user_id );
		if ( '' === $their_agency || $post_agency !== $their_agency ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	},
	10,
	4
);

/* --------------------------------------------------------------------------
 * 7. Approval-workflow guard: warn admins when the officer role's capabilities
 *    bypass admin approval
 *
 * CONTRACT: the Agency Officer role's capabilities are POLICY, owned entirely by
 * the Members plugin UI (Members → Roles) and tuned per environment. Theme code
 * does NOT add, remove, or otherwise manipulate any role capability. The approval
 * workflow is a pure consequence of which boxes are ticked:
 *   - NEW News: WordPress core reads `publish_posts`. Unticked → officers get the
 *     "Submit for Review" flow (post saved `pending`, an admin publishes it);
 *     ticked → they publish live directly.
 *   - EDITS to PUBLISHED News: PublishPress Revisions reads `edit_published_posts`
 *     (see rvy_is_full_editor in wp-content/plugins/revisionary/rvy_init-functions.php).
 *     Unticked → the officer is a non-full editor and edits route into the
 *     pending-revision approval queue for an admin to approve; ticked → they edit
 *     live posts directly. `delete_published_posts` likewise gates deleting live
 *     News outright.
 *
 * So the whole approval path lives in those checkboxes. The theme enforces only
 * agency isolation (sections 1-6, 8) plus this visible guard: if the role still
 * carries any of the three approval-bypassing caps, warn admins so the drift is
 * obvious and fixable in the Members UI. This is a read-only check on page render
 * — no cap manipulation, no DB writes, no option storage.
 * -------------------------------------------------------------------------- */

add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$role = get_role( nsw_agency_officer_role() );
		if ( ! $role ) {
			return;
		}
		$bypass  = array( 'publish_posts', 'edit_published_posts', 'delete_published_posts' );
		$present = array();
		foreach ( $bypass as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$present[] = $cap;
			}
		}
		if ( ! $present ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			sprintf(
				/* translators: %s: comma-separated list of WordPress capability slugs. */
				esc_html__( 'The Agency Officer role can currently publish, edit, or delete live News directly, bypassing admin approval. Offending capabilities: %s.', 'nsw-theme' ),
				'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $present ) ) . '</code>'
			),
			esc_html__( 'Untick those capabilities in Members → Roles to restore the approval workflow.', 'nsw-theme' )
		);
	}
);

/* --------------------------------------------------------------------------
 * 8. Agency column in the News list
 * -------------------------------------------------------------------------- */

add_filter(
	'manage_post_posts_columns',
	function ( $cols ) {
		$cols['nsw_agency'] = __( 'Agency', 'nsw-theme' );
		return $cols;
	}
);
add_action(
	'manage_post_posts_custom_column',
	function ( $col, $post_id ) {
		if ( 'nsw_agency' !== $col ) {
			return;
		}
		$id = (string) get_post_meta( $post_id, '_nsw_news_agency', true );
		if ( '' === $id ) {
			echo '—';
			return;
		}
		$names = nsw_agency_choices();
		echo esc_html( $names[ $id ] ?? $id );
	},
	10,
	2
);
