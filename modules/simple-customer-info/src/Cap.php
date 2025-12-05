<?php namespace SFA\SCI;
final class Cap {
	const DEFAULT_CAP = 'gravityforms_edit_forms';
	public static function capability(): string { return apply_filters( 'sfa_sci_capability', self::DEFAULT_CAP ); }
	public static function can_manage(): bool {
		if ( current_user_can( 'manage_options' ) ) return true;
		if ( class_exists('GFCommon') ) { return \GFCommon::current_user_can_any( [ self::capability() ] ); }
		return current_user_can( self::capability() );
	}
}
