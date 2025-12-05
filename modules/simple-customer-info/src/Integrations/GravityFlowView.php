<?php namespace SFA\SCI\Integrations;

use SFA\SCI\MapRepository;
use SFA\SCI\Renderer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GravityFlowView {
	private $repo;
	private $renderer;

	public function __construct( MapRepository $r, Renderer $v ) {
		$this->repo     = $r;
		$this->renderer = $v;
	}

	public function register() : void {
		// Admin meta box (wp-admin Gravity Flow entry view)
		add_filter( 'gravityflow_entry_detail_meta_boxes', [ $this, 'addBox' ], 5, 3 );

		// Front-end inbox entry detail: render early so it appears before native fields
		add_action( 'gravityflow_entry_detail_before', [ $this, 'renderDirect' ], 1, 3 );
		add_action( 'gravityflow_entry_detail',        [ $this, 'renderDirect' ], 1, 3 );
	}

	public function addBox( $boxes, $args, $step ) {
		$form  = rgar( $args, 'form' );
		$entry = rgar( $args, 'entry' );
		if ( ! $form || ! $entry ) { return $boxes; }

		$boxes['sfa_sci'] = array(
			'id'       => 'sfa_sci',
			'title'    => esc_html__( 'Customer Info', 'simple-flow-attachment' ),
			'context'  => 'primary',
			'callback' => function() use ( $form, $entry ) {
				$map = $this->repo->get( (int) rgar( $form, 'id' ) );
				echo $this->renderer->build( $form, $entry, $map );
			},
			'priority' => 1,
		);
		return $boxes;
	}

	public function renderDirect( $form = null, $entry = null ) : void {
		try {
		if ( ! $form || ! $entry ) { return; }
		$map = $this->repo->get( (int) rgar( $form, 'id' ) );
		echo $this->renderer->build( $form, $entry, $map );

		if ( ! empty( $map['options']['hide_native'] ) ) {
			if ( $this->isCurrentUserAssignee( $form, $entry ) ) { return; }
			$ids = array();
			foreach ( array_merge( $map['preset'] ?? array(), $map['extra'] ?? array() ) as $slot ) {
				$fid = isset( $slot['field_id'] ) ? (int) $slot['field_id'] : 0;
				if ( $fid ) { $ids[] = $fid; }
			}
			$ids = array_values( array_unique( $ids ) );
			printf('<script>window.SFA_SCI_SKIP_HIDE=%s;window.SFA_SCI_HIDE_IDS=%s;</script>', $this->isCurrentUserAssignee($entry) ? 'true' : 'false', wp_json_encode($ids));
		}
		} catch (\Throwable $e) { error_log('[SFA SCI] GravityFlowView render error: '.$e->getMessage()); }
	}

	private function isCurrentUserAssignee( $form, $entry ) : bool {
		try {
		if ( ! function_exists( 'gravity_flow' ) ) { return false; }
		$api  = gravity_flow();
		if ( ! $api ) { return false; }
		$step = $api->get_current_step( $form, $entry );
		if ( ! $step ) { return false; }
		if ( method_exists( $step, 'is_user_assignee' ) ) {
			return (bool) $step->is_user_assignee( get_current_user_id() );
		}
		return false;
		} catch (\Throwable $e) { error_log('[SFA SCI] assignee check error: '.$e->getMessage()); return false; }
	}
}
