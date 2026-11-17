<?php
namespace SFA\DesignerWorkload\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may view the Designer Workload page. Pure, no WordPress calls.
 */
final class Access {

	/**
	 * @param string[] $user_roles       Roles of the viewer.
	 * @param string[] $allowed_roles    Roles picked in settings.
	 * @param int[]    $allowed_user_ids Users picked in settings.
	 */
	public static function is_allowed( int $user_id, array $user_roles, array $allowed_roles, array $allowed_user_ids ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( in_array( 'administrator', $user_roles, true ) ) {
			return true;
		}
		if ( array_intersect( $user_roles, $allowed_roles ) ) {
			return true;
		}
		return in_array( $user_id, array_map( 'intval', $allowed_user_ids ), true );
	}
}
