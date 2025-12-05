<?php namespace SFA\SCI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Renderer {
	private $template;
	public function __construct( string $template ){ $this->template = $template; }

	public function build( array $form, array $entry, array $map ) : string {
		$map = is_array($map) ? $map : array();
		$preset = isset($map['preset']) && is_array($map['preset']) ? $map['preset'] : array();
		$extra  = isset($map['extra'])  && is_array($map['extra'])  ? $map['extra']  : array();
		$options = isset($map['options']) && is_array($map['options']) ? $map['options'] : array();

		$rows  = array();
		$slots = array_merge( $preset, $extra );
		$mapped_ids = array();

		foreach ( $slots as $slot ) {
			$label   = isset($slot['label']) ? (string) $slot['label'] : '';
			$fieldId = isset($slot['field_id']) ? (int) $slot['field_id'] : 0;
			if ( $fieldId > 0 ) {
				$mapped_ids[] = $fieldId;
			}
			$value = $fieldId ? $this->getValueFromEntry($entry, $fieldId) : '';
			// Hide empty fields on the card
			if (trim((string)$value) === '') { continue; }
            // Hide additional phone if digits < 4 (configurable; defaults to common id 46 if set in label)
            $opt_ids = array();
            if ( isset($options['optional_phone_ids']) && is_string($options['optional_phone_ids']) && $options['optional_phone_ids'] !== '' ) {
                foreach ( preg_split('/\s*,\s*/', $options['optional_phone_ids']) as $_id ) {
                    $_id = intval($_id);
                    if ($_id > 0) { $opt_ids[] = $_id; }
                }
            }
            $label_lc = strtolower($label);
            $is_additional_phone = in_array($fieldId, $opt_ids, true) 
                                   || (strpos($label_lc, 'additional') !== false && strpos($label_lc, 'phone') !== false)
                                   || (strpos($label_lc, 'extra') !== false && strpos($label_lc, 'phone') !== false);
            if ( $is_additional_phone ) {
                $digits = preg_replace('/\D+/', '', (string)$value);
                if ( strlen($digits) < 4 ) {
                    continue;
                }
            }
    
			$rows[] = array(
				'label'    => $label,
				'field_id' => $fieldId,
				'value'    => $value,
			);
		}

		// Title & subtitle
		$title    = isset($options['title']) && $options['title'] !== '' ? sanitize_text_field($options['title']) : 'Customer Card';
		$subtitle = isset($options['subtitle']) ? sanitize_text_field($options['subtitle']) : '';
		if ( $subtitle === '' ) {
			$_auto = $this->firstNonEmpty( $entry, array(171,205) ); // AR then EN
			if ( $_auto !== '' ) $subtitle = $_auto;
		}

		$entry_id = isset($entry['id']) ? (int) $entry['id'] : ( isset($entry['entry_id']) ? (int) $entry['entry_id'] : 0 );
		$encoded_ids = esc_attr( wp_json_encode( $mapped_ids ) );
		$collapsed = ! empty( $options['collapsed'] );

		// --- Status badge color mapping (options first; filter can override) ---
		$badge_style = '';
		$status_val = '';
		$config = null;
		// From options
		$opt_field = isset($options['badge_field_id']) ? (int)$options['badge_field_id'] : 0;
		$opt_colors_raw = isset($options['badge_colors']) ? (string)$options['badge_colors'] : '';
		$opt_colors = array();
		if ($opt_colors_raw !== '') {
			$lines = preg_split('/
|
|
/', $opt_colors_raw);
			foreach ($lines as $ln) {
				$ln = trim($ln);
				if ($ln === '') continue;
				// Accept "Label|#hex" or "Label:#hex"
				if (strpos($ln,'|') !== false) { list($k,$v) = array_map('trim', explode('|',$ln,2)); }
				elseif (strpos($ln,':') !== false) { list($k,$v) = array_map('trim', explode(':',$ln,2)); }
				else { continue; }
				if ($k !== '' && $v !== '') { $opt_colors[$k] = $v; }
			}
		}
		if ($opt_field && !empty($opt_colors)) { $config = array('field_id'=>$opt_field, 'colors'=>$opt_colors); }
		// Allow filter to override or provide config if not set via options
		$config = apply_filters( 'sfa_sci_badge_status', $config, $form, $entry, $map );
		if ( is_array( $config ) ) {
			$field_id = isset($config['field_id']) ? (int) $config['field_id'] : 0;
			$colors   = isset($config['colors']) && is_array($config['colors']) ? $config['colors'] : array();
			if ( $field_id ) {
				$status_val = trim( (string) $this->getValueFromEntry($entry, $field_id) );
				if ( $status_val !== '' ) {
					$bg = '';
					// Exact label match first
					if ( isset( $colors[ $status_val ] ) ) {
						$bg = (string) $colors[ $status_val ];
					} else {
						// Case-insensitive lookup
						foreach ( $colors as $k=>$v ) {
							if ( strcasecmp( (string)$k, $status_val ) === 0 ) { $bg = (string) $v; break; }
						}
					}
					if ( $bg ) {
						$fg = $this->pickTextColorForBg( $bg );
						$bg = esc_attr( $bg );
						$fg = esc_attr( $fg );
						$badge_style = 'style="background-color: '.$bg.'; color: '.$fg.';"';
					}
				}
			}
		}

		// Render the template
		ob_start();
		$title = (string) $title; $subtitle = (string) $subtitle; $entry_id = (int) $entry_id;
		$rows = $rows; $encoded_ids = $encoded_ids; $collapsed = $collapsed;
		$badge_style = $badge_style;
		include $this->template;
		return (string) ob_get_clean();
	}

	private function getValueFromEntry(array $entry, $fid) : string {
		$k1 = (string)$fid; if ( isset($entry[$k1]) && $entry[$k1] !== '' && $entry[$k1] !== null ) {
			$val = $entry[$k1];
			return is_array($val) ? implode(', ', $val) : (string)$val;
		}
		$k2 = (int)$fid; if ( isset($entry[$k2]) && $entry[$k2] !== '' && $entry[$k2] !== null ) {
			$val = $entry[$k2];
			return is_array($val) ? implode(', ', $val) : (string)$val;
		}
		foreach ( $entry as $k=>$v ) {
			if ( strpos( (string)$k, (string)$fid . '.' ) === 0 && $v !== '' && $v !== null ) {
				return is_array($v) ? implode(', ', $v) : (string)$v;
			}
		}
		return '';
	}

	private function firstNonEmpty( array $entry, array $ids ) : string {
		foreach ( $ids as $fid ) {
			if ( $fid ) {
				$val = $this->getValueFromEntry($entry, $fid);
				if ( trim( (string)$val ) !== '' ) { return (string)$val; }
			}
		}
		return '';
	}

	private function pickTextColorForBg( string $hex ) : string {
		$hex = ltrim($hex, '#');
		if ( strlen($hex) === 3 ) {
			$hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		}
		$r = hexdec(substr($hex,0,2));
		$g = hexdec(substr($hex,2,2));
		$b = hexdec(substr($hex,4,2));
		// Perceived luminance
		$l = (0.299*$r + 0.587*$g + 0.114*$b) / 255;
		return $l > 0.6 ? '#111' : '#fff';
	}
}
