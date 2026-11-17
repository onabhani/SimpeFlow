<?php
/**
 * Read-only check for the Designer Workload module.
 * Run: wp eval-file wp-content/plugins/SimpeFlow/scripts/designer-workload-check.php
 *
 * Compares the board counts with GFAPI::count_entries() using the same filters.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SFA\\DesignerWorkload\\Data\\WorkloadRepository' ) ) {
	echo "Designer Workload module not loaded.\n";
	return;
}

use SFA\DesignerWorkload\Data\WorkloadRepository;
use SFA\DesignerWorkload\Support\Settings;

WorkloadRepository::flush();
$board = WorkloadRepository::get_board();
$forms = Settings::get()['forms'];

foreach ( $board['designers'] as $designer ) {
	$expected = (int) GFAPI::count_entries(
		$forms,
		array(
			'status'        => 'active',
			'field_filters' => array(
				'mode' => 'all',
				array(
					'key'   => 'workflow_final_status',
					'value' => 'pending',
				),
				array(
					'key'   => 'workflow_user_id_' . $designer['id'],
					'value' => 'pending',
				),
			),
		)
	);
	$count  = count( $designer['tasks'] );
	$oldest = $count ? $designer['tasks'][0]['entry_id'] : '-';
	$status = $count === $expected ? 'MATCH' : 'MISMATCH';

	printf(
		"%d\t%s\t%s\t%d\t(count_entries %d)\toldest #%s\t%s\n",
		$designer['id'],
		$designer['name'],
		'' !== $designer['branch'] ? $designer['branch'] : '-',
		$count,
		$expected,
		$oldest,
		$status
	);
}
