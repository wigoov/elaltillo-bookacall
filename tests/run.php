<?php
/**
 * Runs every test, each one isolated, and prints one line per test.
 *
 *   wp eval-file tests/run.php                 all suites
 *   wp eval-file tests/run.php sync emails     only suites whose file name contains a word
 *
 * Only on a disposable/local site: it refuses to run where Google credentials are saved.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once __DIR__ . '/bootstrap.php';

$elab_filters = isset( $args ) && is_array( $args ) ? $args : array();
$elab_files = glob( __DIR__ . '/cases/*.php' );
sort( $elab_files );
ELAB_Test::boot();
$elab_totals = array( 'pass' => 0, 'fail' => 0 );
$elab_failures = array();
try {
	foreach ( $elab_files as $elab_file ) {
		$elab_suite = basename( $elab_file, '.php' );
		if ( $elab_filters && ! array_filter( $elab_filters, static function ( $word ) use ( $elab_suite ) { return false !== strpos( $elab_suite, $word ); } ) ) {
			continue;
		}
		$elab_tests = require $elab_file;
		WP_CLI::log( '' );
		WP_CLI::log( '■ ' . $elab_suite . ' (' . count( $elab_tests ) . ')' );
		foreach ( $elab_tests as $elab_name => $elab_test ) {
			ELAB_Test::reset();
			try {
				$elab_test();
				$elab_totals['pass']++;
				WP_CLI::log( '  PASS ' . $elab_name );
			} catch ( Throwable $elab_error ) {
				$elab_totals['fail']++;
				$elab_where = $elab_error instanceof ELAB_Test_Failure ? '' : ' [' . get_class( $elab_error ) . ' ' . basename( $elab_error->getFile() ) . ':' . $elab_error->getLine() . ']';
				$elab_failures[] = $elab_suite . ' › ' . $elab_name . ': ' . $elab_error->getMessage() . $elab_where;
				WP_CLI::log( '  FAIL ' . $elab_name . ' — ' . $elab_error->getMessage() . $elab_where );
			}
		}
	}
} finally {
	ELAB_Test::shutdown();
}
WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d tests passed, %d failed, %d checks.', $elab_totals['pass'], $elab_totals['fail'], ELAB_Test::$checks ) );
if ( $elab_failures ) {
	WP_CLI::error( "Failures:\n- " . implode( "\n- ", $elab_failures ) );
}
WP_CLI::success( 'All tests passed.' );
