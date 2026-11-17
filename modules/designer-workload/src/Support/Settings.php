<?php
namespace SFA\DesignerWorkload\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module settings stored in option sfa_dw_settings.
 */
final class Settings {

	const OPTION = 'sfa_dw_settings';

	/**
	 * @return array{roles: string[], users: int[], forms: int[]}
	 */
	public static function defaults(): array {
		return array(
			'roles' => array( 'manager', 'sales_supervisor' ),
			'users' => array(),
			'forms' => array( 1, 46 ),
		);
	}

	/**
	 * @return array{roles: string[], users: int[], forms: int[]}
	 */
	public static function get(): array {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );

		return array(
			'roles' => array_values( array_map( 'strval', (array) $settings['roles'] ) ),
			'users' => array_values( array_map( 'intval', (array) $settings['users'] ) ),
			'forms' => array_values( array_map( 'intval', (array) $settings['forms'] ) ),
		);
	}

	/**
	 * Keep only roles, users and forms that exist.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{roles: string[], users: int[], forms: int[]}
	 */
	public static function sanitize( array $raw ): array {
		$known_roles = array_keys( wp_roles()->get_names() );

		$roles = array();
		foreach ( (array) ( $raw['roles'] ?? array() ) as $role ) {
			$role = sanitize_key( (string) $role );
			if ( 'administrator' !== $role && in_array( $role, $known_roles, true ) ) {
				$roles[] = $role;
			}
		}

		$users = array();
		foreach ( (array) ( $raw['users'] ?? array() ) as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id && get_userdata( $user_id ) ) {
				$users[] = $user_id;
			}
		}

		$forms = array();
		foreach ( (array) ( $raw['forms'] ?? array() ) as $form_id ) {
			$form_id = absint( $form_id );
			if ( $form_id && is_array( \GFAPI::get_form( $form_id ) ) ) {
				$forms[] = $form_id;
			}
		}

		return array(
			'roles' => array_values( array_unique( $roles ) ),
			'users' => array_values( array_unique( $users ) ),
			'forms' => array_values( array_unique( $forms ) ),
		);
	}
}
