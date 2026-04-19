<?php
namespace SFA\ProductionScheduling\Stages;

/**
 * StageBadge
 *
 * Renders the inline colored pill shown next to an entry number on the
 * production bookings tables. Defense-in-depth: re-validates the hex at
 * render time and drops the badge if the shape is wrong.
 */
class StageBadge {

	/**
	 * @param array $stage Stage array from StageRepository.
	 * @return string HTML snippet or empty string.
	 */
	public static function render( $stage ) {
		if ( ! is_array( $stage ) ) {
			return '';
		}
		$name  = isset( $stage['name'] ) ? (string) $stage['name'] : '';
		$color = isset( $stage['color'] ) ? strtolower( (string) $stage['color'] ) : '';
		if ( $name === '' || ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
			return '';
		}

		$text_color = self::best_text_color( $color );

		return sprintf(
			'<span class="sfa-prod-stage-badge" style="background:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $color ),
			esc_attr( $text_color ),
			esc_html( $name )
		);
	}

	/**
	 * Pick #222 on light backgrounds and #fff on dark ones, using the
	 * relative-luminance formula from WCAG.
	 */
	private static function best_text_color( $hex ) {
		$r = hexdec( substr( $hex, 1, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 3, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 5, 2 ) ) / 255;
		$lin = function ( $c ) {
			return ( $c <= 0.03928 ) ? ( $c / 12.92 ) : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$L = 0.2126 * $lin( $r ) + 0.7152 * $lin( $g ) + 0.0722 * $lin( $b );
		return $L > 0.5 ? '#222222' : '#ffffff';
	}

	/**
	 * The small CSS block that styles the badge. Embedded once per table
	 * render so neither ScheduleView nor FrontendCalendar needs a new CSS
	 * enqueue.
	 */
	public static function css() {
		return '<style>.sfa-prod-stage-badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;line-height:1.4;vertical-align:middle;white-space:nowrap;}</style>';
	}
}
