<?php
/**
 * Read-only check for the Designer Workload module.
 * Run: wp eval-file wp-content/plugins/SimpeFlow/scripts/designer-workload-check.php
 *
 * Recounts each designer's tasks through Gravity_Flow_API (independent of the module's
 * step lookup): entries with workflow_user_id_X = pending whose current step really has
 * the designer as a pending assignee. Also reports stale marks the board skips.
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
	$marked   = WorkloadRepository::pending_entries( (int) $designer['id'], $forms );
	$expected = 0;
	$stale    = array();

	foreach ( $marked as $entry ) {
		$step = ( new Gravity_Flow_API( (int) $entry['form_id'] ) )->get_current_step( $entry );
		$real = false;
		if ( $step ) {
			foreach ( $step->get_assignees() as $assignee ) {
				if ( $assignee->get_key() === 'user_id|' . $designer['id'] && 'pending' === $assignee->get_status() ) {
					$real = true;
					break;
				}
			}
		}
		if ( $real ) {
			$expected++;
		} else {
			$stale[] = '#' . $entry['id'];
		}
	}

	$count  = count( $designer['tasks'] );
	$oldest = $count ? $designer['tasks'][0]['entry_id'] : '-';
	$status = $count === $expected ? 'MATCH' : 'MISMATCH';

	printf(
		"%d\t%s\t%s\t%d\t(expected %d)\toldest #%s\t%s\tstale skipped %d %s\n",
		$designer['id'],
		$designer['name'],
		'' !== $designer['branch'] ? $designer['branch'] : '-',
		$count,
		$expected,
		$oldest,
		$status,
		count( $stale ),
		implode( ' ', $stale )
	);
}
