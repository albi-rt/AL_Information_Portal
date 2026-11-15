<?php
/**
 * Records every add_action() and add_filter() call the theme makes while it loads.
 *
 * Much of the theme is written as closures passed to add_action()/add_filter(). The
 * recorder keeps them so a test can fetch a callback and call it directly:
 *
 *     $cb = nsw_test_hook_callback( 'rest_api_init' );
 *     $cb();
 *
 * Defined before Brain Monkey's own hook functions, which only define add_action and
 * add_filter when they do not exist yet. The other hook functions (apply_filters,
 * do_action, has_action, ...) still come from Brain Monkey.
 */

declare(strict_types=1);

$GLOBALS['nsw_test_hooks'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['nsw_test_hooks'][ $hook_name ][] = array(
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

/**
 * All registrations for a hook, in the order the theme added them.
 *
 * @return array<int, array{callback: callable, priority: int, accepted_args: int}>
 */
function nsw_test_hook_registrations( string $hook_name ): array {
	return $GLOBALS['nsw_test_hooks'][ $hook_name ] ?? array();
}

/**
 * The callback registered for a hook. When the theme registers more than one callback
 * on the same hook, pass $index (0 = first registered) or a $match function that picks
 * the right one, for example by the priority.
 */
function nsw_test_hook_callback( string $hook_name, int $index = 0, ?callable $match = null ): callable {
	$regs = nsw_test_hook_registrations( $hook_name );
	if ( null !== $match ) {
		$regs = array_values( array_filter( $regs, $match ) );
	}
	if ( ! isset( $regs[ $index ] ) ) {
		throw new RuntimeException( "No callback number $index registered on hook '$hook_name'." );
	}
	return $regs[ $index ]['callback'];
}
