<?php
namespace SFA\DesignerWorkload\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure formatting helpers. No WordPress calls, so they run in tests/run.php.
 */
final class Format {

	const WARN_DAYS = 7;
	const CRIT_DAYS = 14;

	/**
	 * Whole days between two Unix timestamps, never negative.
	 */
	public static function age_days( int $start_ts, int $now_ts ): int {
		if ( $now_ts <= $start_ts ) {
			return 0;
		}
		return intdiv( $now_ts - $start_ts, 86400 );
	}

	/**
	 * Colour bucket for a waiting time: ok (< 7), warn (7..13), crit (>= 14).
	 */
	public static function age_bucket( int $days ): string {
		if ( $days >= self::CRIT_DAYS ) {
			return 'crit';
		}
		if ( $days >= self::WARN_DAYS ) {
			return 'warn';
		}
		return 'ok';
	}

	/**
	 * Map a "Current Branch" (form 1, field 229) choice to a short key.
	 */
	public static function branch_key( string $choice ): string {
		$c = strtolower( $choice );
		if ( strpos( $c, 'alnuzhah' ) !== false ) {
			return 'alnuzhah';
		}
		if ( strpos( $c, 'alyasmin' ) !== false ) {
			return 'alyasmin';
		}
		if ( strpos( $c, 'touches' ) !== false ) {
			return 'touches';
		}
		return '';
	}

	/**
	 * Oldest waiting first; ties broken by entry ID ascending.
	 *
	 * @param array<int, array<string, mixed>> $tasks
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort_oldest_first( array $tasks ): array {
		usort(
			$tasks,
			static function ( array $a, array $b ): int {
				return array( (int) $a['start_ts'], (int) $a['entry_id'] ) <=> array( (int) $b['start_ts'], (int) $b['entry_id'] );
			}
		);
		return $tasks;
	}

	/**
	 * Workload size of a task list. Single place for the future weighting rule
	 * (Touches marble jobs vs washbasin jobs); today every task counts as 1.
	 *
	 * @param array<int, array<string, mixed>> $tasks
	 */
	public static function count( array $tasks ): int {
		return count( $tasks );
	}

	/**
	 * True when the user is a pending assignee of the entry's current step.
	 * Gravity Flow can leave workflow_user_id_X = pending behind after the entry moves on,
	 * so that meta alone is not proof the task is still the designer's.
	 *
	 * @param array<int, array{key: string, status: string}> $assignees Current step's assignees.
	 */
	public static function is_current_assignee( array $assignees, int $user_id ): bool {
		foreach ( $assignees as $assignee ) {
			if ( 'user_id|' . $user_id === $assignee['key'] && 'pending' === $assignee['status'] ) {
				return true;
			}
		}
		return false;
	}
}
