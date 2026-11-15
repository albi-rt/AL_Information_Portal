<?php
/**
 * Prints line coverage per theme file from a Clover XML report.
 * Usage: php tests/tools/coverage-by-file.php build/clover.xml [path-filter]
 */
declare(strict_types=1);

$clover = $argv[1] ?? 'build/clover.xml';
$filter = $argv[2] ?? '';
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
printf( "TOTAL %.1f%% (%d/%d lines)\n", $total_lines ? 100 * $total_hit / $total_lines : 100, $total_hit, $total_lines );
