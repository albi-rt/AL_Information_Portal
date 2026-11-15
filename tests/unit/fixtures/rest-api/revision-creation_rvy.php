<?php
/**
 * Test stand-in for the PublishPress Revisions file revision-creation_rvy.php.
 *
 * The content API loads this file from the plugin folder (next to REVISIONARY_FILE)
 * to create a pending revision of a published item. This stand-in only records the
 * call and returns the value the test put in $GLOBALS['nsw_test_rvy_result'].
 * It is used by tests/unit/RestApiTest.php and never by WordPress.
 */

namespace PublishPress\Revisions;

class RevisionCreation {

	public function createRevision( $post_id, $revision_status, $args = array(), $data = array() ) {
		$GLOBALS['nsw_test_rvy_calls'][] = array(
			'post_id'         => $post_id,
			'revision_status' => $revision_status,
			'args'            => $args,
			'data'            => $data,
		);
		return $GLOBALS['nsw_test_rvy_result'] ?? 0;
	}
}
