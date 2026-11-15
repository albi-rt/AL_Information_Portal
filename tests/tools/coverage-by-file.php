<?php
/**
 * Prints line coverage per theme file from a Clover XML report.
 * Usage: php tests/tools/coverage-by-file.php build/clover.xml [path-filter] [--min=80]
 * With --min=N it exits with status 1 when the total line coverage is below N percent.
 */
declare(strict_types=1);

$args   = array_slice( $argv, 1 );
$min    = null;
foreach ( $args as $i => $arg ) {
	if ( 0 === strpos( $arg, '--min=' ) ) {
		$min = (float) substr( $arg, 6 );
		unset( $args[ $i ] );
	}
}
$args   = array_values( $args );
$clover = $args[0] ?? 'build/clover.xml';
$filter = $args[1] ?? '';
$xml    = simplexml_load_file( $clover );
if ( false === $xml ) {
	fwrite( STDERR, "Cannot read $clover\n" );
	exit( 1 );
}
$total_lines = 0;
$total_hit   = 0;
$rows        = array();
foreach ( $xml->xpath( '//file' ) as $file ) {
	$name = (string) $file['name'];
	if ( '' !== $filter && false === strpos( $name, $filter ) ) {
		continue;
	}
	$lines = 0;
	$hit   = 0;
	$miss  = array();
	foreach ( $file->line as $line ) {
		if ( 'stmt' !== (string) $line['type'] ) {
			continue;
		}
		++$lines;
		if ( (int) $line['count'] > 0 ) {
			++$hit;
		} else {
			$miss[] = (int) $line['num'];
		}
	}
	$total_lines += $lines;
	$total_hit   += $hit;
	$short        = preg_replace( '#^.*/nsw-theme/#', '', $name );
	$rows[]       = sprintf( "%6.1f%%  %4d/%-4d  %s%s", $lines ? 100 * $hit / $lines : 100, $hit, $lines, $short,
		$miss ? '  uncovered: ' . implode( ',', array_slice( $miss, 0, 40 ) ) . ( count( $miss ) > 40 ? ',...' : '' ) : '' );
}
sort( $rows );
echo implode( "\n", $rows ), "\n";
$total_pct = $total_lines ? 100 * $total_hit / $total_lines : 100;
printf( "TOTAL %.1f%% (%d/%d lines)\n", $total_pct, $total_hit, $total_lines );
if ( null !== $min ) {
	if ( $total_pct < $min ) {
		printf( "FAIL: line coverage %.1f%% is below the minimum of %.1f%%\n", $total_pct, $min );
		exit( 1 );
	}
	printf( "OK: line coverage %.1f%% meets the minimum of %.1f%%\n", $total_pct, $min );
}
