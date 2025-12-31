#!/usr/bin/env php
<?php
/**
 * Cleanup Invalid Production Bookings
 *
 * This script removes all production booking metadata from entries with 0 LM or empty production fields.
 * The entries themselves are NOT deleted, only the production scheduling metadata.
 *
 * Usage:
 *   php cleanup-invalid-production-bookings.php
 *
 * OR via WP-CLI:
 *   wp eval-file cleanup-invalid-production-bookings.php
 */

// Load WordPress
if ( ! defined( 'ABSPATH' ) ) {
	// Try to find wp-load.php
	$wp_load_locations = [
		__DIR__ . '/../../../../wp-load.php',  // If in plugins/simpleflow/scripts/
		__DIR__ . '/../../../wp-load.php',      // Alternative
		__DIR__ . '/../../wp-load.php',         // Alternative
		__DIR__ . '/../wp-load.php',            // Alternative
		getcwd() . '/wp-load.php',              // Current working directory
	];

	$wp_loaded = false;
	foreach ( $wp_load_locations as $wp_load ) {
		if ( file_exists( $wp_load ) ) {
			require_once $wp_load;
			$wp_loaded = true;
			break;
		}
	}

	if ( ! $wp_loaded ) {
		die( "Error: Could not find wp-load.php. Please run this script from your WordPress root directory.\n" );
	}
}

// Ensure we have WordPress loaded
if ( ! function_exists( 'gform_delete_meta' ) ) {
	die( "Error: Gravity Forms is not active. This script requires Gravity Forms.\n" );
}

echo "=================================================\n";
echo "Production Bookings Cleanup Script\n";
echo "=================================================\n\n";

global $wpdb;

// Step 1: Find all entries with 0 or null LM
echo "Step 1: Finding entries with invalid production bookings...\n";

$entries_to_clean = $wpdb->get_results(
	"SELECT DISTINCT entry_id
	FROM {$wpdb->prefix}gf_entry_meta
	WHERE (
		(meta_key = '_prod_lm_required' AND (meta_value = '0' OR meta_value IS NULL OR meta_value = ''))
		OR
		(meta_key = '_prod_total_slots' AND (meta_value = '0' OR meta_value IS NULL OR meta_value = ''))
	)",
	ARRAY_A
);

if ( empty( $entries_to_clean ) ) {
	echo "✓ No invalid bookings found. Database is clean!\n\n";
	exit( 0 );
}

$total_entries = count( $entries_to_clean );
echo "Found {$total_entries} entries with invalid bookings (0 LM).\n\n";

// Step 2: Show preview of entries to be cleaned
echo "Preview of entries to be cleaned:\n";
echo "Entry ID\n";
echo "---------\n";
$preview_count = min( 10, $total_entries );
for ( $i = 0; $i < $preview_count; $i++ ) {
	$entry_id = $entries_to_clean[ $i ]['entry_id'];
	echo $entry_id . "\n";
}
if ( $total_entries > 10 ) {
	echo "... and " . ( $total_entries - 10 ) . " more entries\n";
}
echo "\n";

// Step 3: Confirm before proceeding
echo "WARNING: This will delete production booking metadata from {$total_entries} entries.\n";
echo "The entries themselves will NOT be deleted, only production scheduling data.\n\n";

if ( php_sapi_name() === 'cli' ) {
	echo "Do you want to proceed? (yes/no): ";
	$handle = fopen( "php://stdin", "r" );
	$response = trim( fgets( $handle ) );
	fclose( $handle );

	if ( strtolower( $response ) !== 'yes' ) {
		echo "\nOperation cancelled by user.\n";
		exit( 0 );
	}
} else {
	echo "Running in non-interactive mode. Proceeding with cleanup...\n";
}

// Step 4: Clean up entries
echo "\nStep 2: Cleaning up invalid bookings...\n";

$meta_keys_to_delete = [
	'_prod_lm_required',
	'_prod_total_slots',
	'_prod_field_breakdown',
	'_prod_slots_allocation',
	'_prod_start_date',
	'_prod_end_date',
	'_install_date',
	'_prod_booking_status',
	'_prod_booked_at',
	'_prod_booked_by',
	'_prod_daily_capacity_at_booking',
];

$deleted_count = 0;
$progress_step = max( 1, floor( $total_entries / 10 ) );

foreach ( $entries_to_clean as $index => $row ) {
	$entry_id = $row['entry_id'];

	// Delete all production-related meta for this entry
	foreach ( $meta_keys_to_delete as $meta_key ) {
		gform_delete_meta( $entry_id, $meta_key );
	}

	$deleted_count++;

	// Show progress
	if ( $deleted_count % $progress_step === 0 || $deleted_count === $total_entries ) {
		$percentage = round( ( $deleted_count / $total_entries ) * 100 );
		echo "  Progress: {$deleted_count}/{$total_entries} ({$percentage}%)...\n";
	}
}

// Step 5: Clear cache
echo "\nStep 3: Clearing WordPress cache...\n";
wp_cache_flush();

// Step 6: Summary
echo "\n=================================================\n";
echo "✓ Cleanup completed successfully!\n";
echo "=================================================\n";
echo "Total entries cleaned: {$deleted_count}\n";
echo "Metadata keys removed per entry: " . count( $meta_keys_to_delete ) . "\n";
echo "Total database rows deleted: " . ( $deleted_count * count( $meta_keys_to_delete ) ) . "\n\n";

echo "Note: The entries themselves were NOT deleted.\n";
echo "Only production scheduling metadata was removed.\n\n";

exit( 0 );
