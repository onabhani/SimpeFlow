<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Endpoints\Refresh_Inbox_Items;

use Gravity_Flow\Gravity_Flow\Ajax\Endpoint as Ajax_Endpoint;

/**
 * Refresh_Inbox_Items endpoint.
 *
 * @since 2.8
 */
class Endpoint extends Ajax_Endpoint {

	/**
	 * Handle the request.
	 *
	 * @since 2.8
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_HTTP_Response
	 */
	public function handle( $request ) {
		$search_args = $request->get_param( Config::SEARCH_ARGS );

		if ( is_string( $search_args ) ) {
			$search_args = json_decode( $search_args, true );
		}

		$filter_key = $this->model->get_filter_key_for_args( $search_args );
		$assignee   = $this->model->get_assignee_from_filter_key( $filter_key );
		$user       = $assignee->get_id();

		$search_args['user_id'] = $user;

		$tasks = $this->model->get_inbox_tasks( $search_args );
		$ids   = $request->get_param( Config::CURRENT_IDS );

		$data = array(
			'add'    => $this->added( $tasks, $ids ),
			'remove' => $this->removed( $tasks, $ids ),
			'update' => $this->updated( $tasks, $ids ),
		);

		return $this->response_factory->create( $data, 200 );
	}

	/**
	 * Parse the added tasks.
	 *
	 * @since 2.8
	 *
	 * @param array $tasks
	 * @param array $ids
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	private function added( $tasks, $ids ) {
		return array_filter( $tasks, function ( $item ) use ( $ids ) {
			return ! in_array( $item['id'], $ids );
		} );
	}

	/**
	 * Parse the updated tasks.
	 *
	 * @since 2.8
	 *
	 * @param array $tasks
	 * @param array $ids
	 *
	 * @return array
	 */
	private function updated( $tasks, $ids ) {
		return array_values( array_filter( $tasks, function ( $item ) use ( $ids ) {
			return in_array( $item['id'], $ids );
		} ) );
	}

	/**
	 * Parse the removed tasks.
	 *
	 * @since 2.8
	 *
	 * @param array $tasks
	 * @param array $ids
	 *
	 * @return array
	 */
	private function removed( $tasks, $ids ) {
		$task_ids = array();

		foreach ( $tasks as $task ) {
			$task_ids[] = $task['id'];
		}

		return array_values( array_map(
			function( $item ) {
				return array( 'id' => $item );
			},
			array_diff( $ids, $task_ids )
		) );
	}

}
