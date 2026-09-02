<?php
/**
 * Agency Officers: scope a user to a single agency so they only see and write
 * that agency's content, across every agency-scoped post type (core News Posts
 * and Trade Services).
 *
 * - Each user can be assigned one agency (a field on their profile).
 * - Each scoped post carries an agency (auto for officers; a dropdown for admins).
 * - An Agency Officer only sees their agency's items in wp-admin, and can only
 *   edit/delete that agency's items.
 *
 * The set of scoped post types and their per-type agency meta key + approval-
 * bypass capabilities is declared once in nsw_agency_scoped_types(); every
 * section below loops that registry, so both types share one implementation.
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

/**
 * Registry of agency-scoped post types.
 *
 * Each entry maps a post type to:
 *  - 'meta':   the post meta key that stores the item's agency stable id.
 *  - 'bypass': the capabilities that, if granted to the officer role, would let
 *              officers skip admin approval (used only by the drift guard in
 *              section 7 — theme code never adds or removes them).
 *
 * News ('post') behaves exactly as it always has; Services ('nsw_service') reuse
 * the same machinery with their own meta key and dedicated capability set.
 */
function nsw_agency_scoped_types(): array {
	return array(
		'post'        => array(
			'meta'   => '_nsw_news_agency',
			'bypass' => array( 'publish_posts', 'edit_published_posts', 'delete_published_posts' ),
		),
		'nsw_service' => array(
			'meta'   => '_nsw_service_agency',
			'bypass' => array( 'publish_nsw_services', 'edit_published_nsw_services', 'delete_published_nsw_services' ),
		),
	);
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
 * 1. Scoped types → agency meta
 * -------------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		foreach ( nsw_agency_scoped_types() as $type => $conf ) {
			register_post_meta(
				$type,
				$conf['meta'],
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
				<p class="description"><?php esc_html_e( 'An Agency Officer can only see and write content for this agency.', 'nsw-theme' ); ?></p>
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
 * 3. Agency meta box, per scoped type (admins/editors choose; officers don't —
 *    it's auto-set to their agency on save)
 * -------------------------------------------------------------------------- */

foreach ( array_keys( nsw_agency_scoped_types() ) as $nsw_scoped_type ) {
	add_action(
		'add_meta_boxes_' . $nsw_scoped_type,
		function () use ( $nsw_scoped_type ) {
			if ( nsw_is_agency_officer() ) {
				return; // officers can't pick — it's forced to their agency on save
			}
			add_meta_box( 'nsw_agency_box', __( 'Agency', 'nsw-theme' ), 'nsw_render_agency_box', $nsw_scoped_type, 'side', 'default' );
		}
	);
}
unset( $nsw_scoped_type );

function nsw_render_agency_box( WP_Post $post ): void {
	$types = nsw_agency_scoped_types();
	$conf  = $types[ $post->post_type ] ?? null;
	if ( ! $conf ) {
		return;
	}
	$current = (string) get_post_meta( $post->ID, $conf['meta'], true );
	wp_nonce_field( 'nsw_agency_box', 'nsw_agency_box_nonce' );
	echo '<select name="nsw_agency_box" style="width:100%">';
	echo '<option value="">' . esc_html__( '— None —', 'nsw-theme' ) . '</option>';
	foreach ( nsw_agency_choices() as $id => $name ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $id ), selected( $current, $id, false ), esc_html( $name ) );
	}
	echo '</select>';
}

/* --------------------------------------------------------------------------
 * 4. On save, stamp the agency (one generic handler; unscoped types skip early)
 * -------------------------------------------------------------------------- */

add_action(
	'save_post',
	function ( $post_id, $post ) {
		$types = nsw_agency_scoped_types();
		$conf  = $types[ $post->post_type ] ?? null;
		if ( ! $conf ) {
			return; // not an agency-scoped type
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( nsw_is_agency_officer() ) {
			// Officers: always their own agency, no choice.
			$agency = nsw_user_agency();
			if ( '' !== $agency ) {
				update_post_meta( $post_id, $conf['meta'], $agency );
			}
			return;
		}
		// Admins/editors: from the meta box.
		if ( isset( $_POST['nsw_agency_box_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nsw_agency_box_nonce'] ) ), 'nsw_agency_box' ) ) {
			$val = isset( $_POST['nsw_agency_box'] ) ? sanitize_text_field( wp_unslash( $_POST['nsw_agency_box'] ) ) : '';
			if ( '' === $val ) {
				delete_post_meta( $post_id, $conf['meta'] );
			} else {
				update_post_meta( $post_id, $conf['meta'], $val );
			}
		}
	},
	10,
	2
);

/* --------------------------------------------------------------------------
 * 5. Restrict each scoped type's admin list to the officer's agency
 * -------------------------------------------------------------------------- */

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$post_type = (string) ( $query->get( 'post_type' ) ?: 'post' );
		$types     = nsw_agency_scoped_types();
		$conf      = $types[ $post_type ] ?? null;
		if ( ! $conf ) {
			return;
		}
		if ( ! nsw_is_agency_officer() ) {
			return;
		}
		$agency = nsw_user_agency();
		$meta   = (array) $query->get( 'meta_query' );
		// No agency assigned → match a value that never exists, so they see nothing.
		$meta[] = array(
			'key'     => $conf['meta'],
			'value'   => '' !== $agency ? $agency : '__nsw_no_agency__',
			'compare' => '=',
		);
		$query->set( 'meta_query', $meta );
	}
);

/* --------------------------------------------------------------------------
 * 6. Block editing/deleting another agency's content (even by direct URL)
 *
 * `copy_post` is PublishPress Revisions' entry gate for the "Revise" / submit-
 * revision flow. Once officers lack `edit_published_*` (see section 7), that
 * flow — not the core Edit link (`edit_post`) — is how they touch PUBLISHED
 * items, so we must scope it to their agency too, or an officer could open a
 * revision on another agency's live post. We run at priority 10 (Revisions maps
 * `copy_post` at priority 5), so returning `do_not_allow` here still vetoes
 * cross-agency. The scoped post type and its agency meta key come from the
 * registry, so News and Services are enforced identically.
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
		if ( ! $post_id ) {
			return $caps;
		}
		$types = nsw_agency_scoped_types();
		$conf  = $types[ get_post_type( $post_id ) ] ?? null;
		if ( ! $conf ) {
			return $caps;
		}
		// Allow the brand-new auto-draft so officers can create content.
		if ( 'auto-draft' === get_post_status( $post_id ) ) {
			return $caps;
		}
		$post_agency  = (string) get_post_meta( $post_id, $conf['meta'], true );
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
 * workflow is a pure consequence of which boxes are ticked, per scoped type:
 *   - NEW items: WordPress core reads the type's `publish_*` cap. Unticked →
 *     officers get the "Submit for Review" flow (saved `pending`, an admin
 *     publishes it); ticked → they publish live directly.
 *   - EDITS to PUBLISHED items: PublishPress Revisions reads `edit_published_*`
 *     (see rvy_is_full_editor in wp-content/plugins/revisionary/rvy_init-functions.php).
 *     Unticked → the officer is a non-full editor and edits route into the
 *     pending-revision approval queue for an admin to approve; ticked → they edit
 *     live posts directly. `delete_published_*` likewise gates deleting live
 *     items outright.
 *
 * So the whole approval path lives in those checkboxes (three per scoped type).
 * The theme enforces only agency isolation (sections 1-6, 8) plus this visible
 * guard: if the role still carries any of the approval-bypassing caps for any
 * scoped type, warn admins so the drift is obvious and fixable in the Members UI.
 * This is a read-only check on page render — no cap manipulation, no DB writes,
 * no option storage.
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
		$present = array();
		foreach ( nsw_agency_scoped_types() as $conf ) {
			foreach ( $conf['bypass'] as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$present[] = $cap;
				}
			}
		}
		if ( ! $present ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			sprintf(
				/* translators: %s: comma-separated list of WordPress capability slugs. */
				esc_html__( 'The Agency Officer role can currently publish, edit, or delete live content directly, bypassing admin approval. Offending capabilities: %s.', 'nsw-theme' ),
				'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $present ) ) . '</code>'
			),
			esc_html__( 'Untick those capabilities in Members → Roles to restore the approval workflow.', 'nsw-theme' )
		);
	}
);

/* --------------------------------------------------------------------------
 * 8. Agency column in each scoped type's admin list
 * -------------------------------------------------------------------------- */

foreach ( nsw_agency_scoped_types() as $nsw_scoped_type => $nsw_scoped_conf ) {
	add_filter(
		'manage_' . $nsw_scoped_type . '_posts_columns',
		function ( $cols ) {
			$cols['nsw_agency'] = __( 'Agency', 'nsw-theme' );
			return $cols;
		}
	);
	add_action(
		'manage_' . $nsw_scoped_type . '_posts_custom_column',
		function ( $col, $post_id ) use ( $nsw_scoped_conf ) {
			if ( 'nsw_agency' !== $col ) {
				return;
			}
			$id = (string) get_post_meta( $post_id, $nsw_scoped_conf['meta'], true );
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
}
unset( $nsw_scoped_type, $nsw_scoped_conf );
