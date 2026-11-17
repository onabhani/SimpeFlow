<?php
namespace SFA\DesignerWorkload\Data;

use SFA\DesignerWorkload\Support\Format;
use SFA\DesignerWorkload\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads each designer's pending Gravity Flow assignments. Read only.
 */
final class WorkloadRepository {

	const TRANSIENT  = 'sfa_dw_tasks_v1';
	const CACHE_TTL  = 120;
	const PAGE_SIZE  = 200;
	const ROLE       = 'designer';
	const BRANCH_STEP = 17;

	/** Client name field per form: DOFS -> Client Name, Projects -> Project Name. */
	const CLIENT_FIELDS = array(
		1  => '171',
		46 => '253',
	);

	/**
	 * @return array{generated_at: int, designers: array<int, array{id: int, name: string, branch: string, tasks: array<int, array<string, mixed>>}>}
	 */
	public static function get_board(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$settings = Settings::get();
		$form_ids = $settings['forms'];
		$branches = self::branch_map();
		$titles   = array();

		$designers = array();
		$users     = get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$tasks   = array();

			foreach ( $form_ids ? self::pending_entries( $user_id, $form_ids ) : array() as $entry ) {
				$form_id = (int) $entry['form_id'];
				if ( ! isset( $titles[ $form_id ] ) ) {
					$form               = \GFAPI::get_form( $form_id );
					$titles[ $form_id ] = is_array( $form ) ? (string) $form['title'] : '';
				}
				$tasks[] = self::to_task( $entry, $user_id, $titles[ $form_id ] );
			}

			$designers[] = array(
				'id'     => $user_id,
				'name'   => (string) $user->display_name,
				'branch' => $branches[ $user_id ] ?? '',
				'tasks'  => Format::sort_oldest_first( $tasks ),
			);
		}

		$board = array(
			'generated_at' => time(),
			'designers'    => $designers,
		);
		set_transient( self::TRANSIENT, $board, self::CACHE_TTL );

		return $board;
	}

	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Active entries in the given forms with an open workflow and a pending assignment for the user.
	 *
	 * @param int[] $form_ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_entries( int $user_id, array $form_ids ): array {
		$criteria = array(
			'status'        => 'active',
			'field_filters' => array(
				'mode' => 'all',
				array(
					'key'   => 'workflow_final_status',
					'value' => 'pending',
				),
				array(
					'key'   => 'workflow_user_id_' . $user_id,
					'value' => 'pending',
				),
			),
		);
		$sorting  = array(
			'key'       => 'id',
			'direction' => 'ASC',
		);

		$all    = array();
		$offset = 0;
		do {
			$page = \GFAPI::get_entries(
				$form_ids,
				$criteria,
				$sorting,
				array(
					'offset'    => $offset,
					'page_size' => self::PAGE_SIZE,
				)
			);
			if ( is_wp_error( $page ) || ! is_array( $page ) ) {
				break;
			}
			foreach ( $page as $entry ) {
				$all[] = $entry;
			}
			$offset += self::PAGE_SIZE;
		} while ( count( $page ) === self::PAGE_SIZE );

		return $all;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{entry_id: int, form_id: int, form_title: string, client: string, step_name: string, start_ts: int}
	 */
	private static function to_task( array $entry, int $user_id, string $form_title ): array {
		$entry_id = (int) $entry['id'];
		$form_id  = (int) $entry['form_id'];
		$step_id  = (int) rgar( $entry, 'workflow_step' );

		$client = '';
		if ( isset( self::CLIENT_FIELDS[ $form_id ] ) ) {
			$client = trim( (string) rgar( $entry, self::CLIENT_FIELDS[ $form_id ] ) );
		}

		$step_name = '';
		if ( $step_id > 0 ) {
			$step = gravity_flow()->get_step( $step_id, $entry );
			if ( $step ) {
				$step_name = (string) $step->get_name();
			}
		}

		return array(
			'entry_id'   => $entry_id,
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'client'     => $client,
			'step_name'  => $step_name,
			'start_ts'   => self::start_ts( $entry, $user_id, $step_id ),
		);
	}

	/**
	 * When the task started: assignment time, else step start, else entry creation.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function start_ts( array $entry, int $user_id, int $step_id ): int {
		$entry_id = (int) $entry['id'];

		$ts = (int) gform_get_meta( $entry_id, 'workflow_user_id_' . $user_id . '_timestamp' );
		if ( $ts <= 0 && $step_id > 0 ) {
			$ts = (int) gform_get_meta( $entry_id, 'workflow_step_' . $step_id . '_timestamp' );
		}
		if ( $ts <= 0 ) {
			$ts = (int) strtotime( (string) rgar( $entry, 'date_created' ) . ' UTC' );
		}
		return max( 0, $ts );
	}

	/**
	 * Designer user ID -> branch key, read from the routing of form 1 step 17 (field 229).
	 *
	 * @return array<int, string>
	 */
	private static function branch_map(): array {
		$map  = array();
		$step = gravity_flow()->get_step( self::BRANCH_STEP );
		if ( ! $step ) {
			return $map;
		}
		$meta = $step->get_feed_meta();
		foreach ( (array) ( $meta['routing'] ?? array() ) as $rule ) {
			$assignee = (string) ( $rule['assignee'] ?? '' );
			if ( strpos( $assignee, 'user_id|' ) !== 0 ) {
				continue;
			}
			$user_id = (int) substr( $assignee, 8 );
			$key     = Format::branch_key( (string) ( $rule['value'] ?? '' ) );
			if ( $user_id && '' !== $key && ! isset( $map[ $user_id ] ) ) {
				$map[ $user_id ] = $key;
			}
		}
		return $map;
	}
}
