<?php
/**
 * Designer Workload: plain PHP checks for the pure helpers.
 * Run: php modules/designer-workload/tests/run.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/src/Support/Format.php';
require_once dirname( __DIR__ ) . '/src/Support/Access.php';

use SFA\DesignerWorkload\Support\Format;
use SFA\DesignerWorkload\Support\Access;

$failures = 0;

function check( string $label, $actual, $expected ): void {
	global $failures;
	if ( $actual === $expected ) {
		echo "PASS  {$label}\n";
		return;
	}
	$failures++;
	echo "FAIL  {$label}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function task( int $entry_id, int $start_ts ): array {
	return array(
		'entry_id'   => $entry_id,
		'form_id'    => 1,
		'form_title' => 'DOFS',
		'client'     => '',
		'step_name'  => '',
		'start_ts'   => $start_ts,
	);
}

// age_days.
check( 'age_days just under a day', Format::age_days( 0, 86399 ), 0 );
check( 'age_days exactly a day', Format::age_days( 0, 86400 ), 1 );
check( 'age_days future start is 0', Format::age_days( 1000, 0 ), 0 );

// age_bucket.
check( 'age_bucket 6 ok', Format::age_bucket( 6 ), 'ok' );
check( 'age_bucket 7 warn', Format::age_bucket( 7 ), 'warn' );
check( 'age_bucket 13 warn', Format::age_bucket( 13 ), 'warn' );
check( 'age_bucket 14 crit', Format::age_bucket( 14 ), 'crit' );

// branch_key.
check( 'branch alnuzhah', Format::branch_key( 'DMNUZ - Alnuzhah Branch' ), 'alnuzhah' );
check( 'branch alyasmin', Format::branch_key( 'DMYAS - Alyasmin Branch' ), 'alyasmin' );
check( 'branch touches', Format::branch_key( 'DM Touches' ), 'touches' );
check( 'branch unknown', Format::branch_key( 'Other' ), '' );

// sort_oldest_first.
$sorted = Format::sort_oldest_first( array( task( 1, 300 ), task( 2, 100 ), task( 9, 100 ), task( 5, 100 ) ) );
check( 'sort order', array_column( $sorted, 'entry_id' ), array( 2, 5, 9, 1 ) );

// count.
check( 'count empty', Format::count( array() ), 0 );
check( 'count three', Format::count( array( task( 1, 1 ), task( 2, 2 ), task( 3, 3 ) ) ), 3 );

// is_allowed.
check( 'guest denied', Access::is_allowed( 0, array( 'administrator' ), array(), array() ), false );
check( 'administrator allowed', Access::is_allowed( 5, array( 'administrator' ), array(), array() ), true );
check( 'manager allowed', Access::is_allowed( 5, array( 'manager' ), array( 'manager', 'sales_supervisor' ), array() ), true );
check( 'designer denied', Access::is_allowed( 5, array( 'designer' ), array( 'manager' ), array() ), false );
check( 'picked user without role allowed', Access::is_allowed( 42, array(), array( 'manager' ), array( 42 ) ), true );

echo $failures ? "\n{$failures} failed\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
