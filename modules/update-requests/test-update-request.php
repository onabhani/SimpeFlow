<?php
/**
 * Update Request Module Test
 *
 * Add this to your theme's functions.php temporarily:
 * require_once '/path/to/simpleflow/modules/update-requests/test-update-request.php';
 *
 * Then access: /?test_ur_module=1
 */

add_action( 'template_redirect', function() {
	if ( ! isset( $_GET['test_ur_module'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Admin access required' );
	}

	echo '<h1>Update Requests Module Test</h1>';
	echo '<style>body { font-family: monospace; padding: 20px; }</style>';

	// Test 1: Module loaded
	echo '<h2>✓ Test 1: Module Loaded</h2>';
	if ( defined( 'SFA_UR_VER' ) ) {
		echo '<p style="color: green;">✓ Module version: ' . SFA_UR_VER . '</p>';
	} else {
		echo '<p style="color: red;">✗ Module not loaded</p>';
	}

	// Test 2: Classes exist
	echo '<h2>✓ Test 2: Classes Loaded</h2>';
	$classes = [
		'SFA\\UpdateRequests\\GravityForms\\ModeDetector',
		'SFA\\UpdateRequests\\GravityForms\\ChildLinking',
		'SFA\\UpdateRequests\\GravityForms\\ApprovalGuards',
		'SFA\\UpdateRequests\\GravityForms\\FileAttachments',
		'SFA\\UpdateRequests\\GravityForms\\EntryUpdating',
		'SFA\\UpdateRequests\\Admin\\ParentPanel',
	];

	foreach ( $classes as $class ) {
		if ( class_exists( $class ) ) {
			echo '<p style="color: green;">✓ ' . $class . '</p>';
		} else {
			echo '<p style="color: red;">✗ ' . $class . ' - NOT FOUND</p>';
		}
	}

	// Test 3: Find forms with required fields
	echo '<h2>✓ Test 3: Forms with Update Request Fields</h2>';
	if ( ! class_exists( 'GFAPI' ) ) {
		echo '<p style="color: red;">✗ Gravity Forms not loaded</p>';
	} else {
		$forms = \GFAPI::get_forms();
		$found = false;

		foreach ( $forms as $form ) {
			$has_mode = false;
			$has_parent = false;
			$has_type = false;

			foreach ( $form['fields'] as $field ) {
				if ( isset( $field->adminLabel ) ) {
					if ( $field->adminLabel === '_ur_mode' ) {
						$has_mode = true;
					}
					if ( $field->adminLabel === '_ur_parent_id' ) {
						$has_parent = true;
					}
					if ( $field->adminLabel === '_ur_type' ) {
						$has_type = true;
					}
				}
			}

			if ( $has_mode && $has_parent && $has_type ) {
				$found = true;
				echo '<p style="color: green;">✓ Form #' . $form['id'] . ': ' . $form['title'] . ' - READY</p>';

				// Show example URL
				$form_url = get_permalink( $form['id'] ); // Might not work, adjust as needed
				echo '<p style="background: #f0f0f0; padding: 10px; margin: 10px 0;">';
				echo 'Example URL:<br>';
				echo '<code>YOUR_FORM_PAGE/?update_request=1&parent_id=40507&request_type=entry_updating</code>';
				echo '</p>';
			} elseif ( $has_mode || $has_parent || $has_type ) {
				echo '<p style="color: orange;">⚠ Form #' . $form['id'] . ': ' . $form['title'] . ' - PARTIAL (mode:' . ($has_mode?'Y':'N') . ' parent:' . ($has_parent?'Y':'N') . ' type:' . ($has_type?'Y':'N') . ')</p>';
			}
		}

		if ( ! $found ) {
			echo '<p style="color: red;">✗ No forms found with all required fields (_ur_mode, _ur_parent_id, _ur_type)</p>';
			echo '<p>You need to add three hidden fields with these Admin Labels to your form.</p>';
		}
	}

	// Test 4: Check if parent entry exists
	echo '<h2>✓ Test 4: Parent Entry Check</h2>';
	$parent_id = 40507;
	$parent_entry = \GFAPI::get_entry( $parent_id );

	if ( is_wp_error( $parent_entry ) ) {
		echo '<p style="color: red;">✗ Parent entry #' . $parent_id . ' not found</p>';
	} else {
		echo '<p style="color: green;">✓ Parent entry #' . $parent_id . ' exists (Form #' . $parent_entry['form_id'] . ')</p>';

		// Check for existing update requests
		$children = gform_get_meta( $parent_id, '_ur_children' );
		if ( $children ) {
			$children_array = json_decode( $children, true );
			echo '<p>Existing update requests: ' . count( $children_array ) . '</p>';
		} else {
			echo '<p>No update requests yet for this parent.</p>';
		}
	}

	echo '<hr>';
	echo '<p><strong>Next Steps:</strong></p>';
	echo '<ol>';
	echo '<li>Find the page/URL where your form is displayed</li>';
	echo '<li>Add the update request parameters to that URL</li>';
	echo '<li>Open the URL and submit the form</li>';
	echo '</ol>';

	exit;
}, 1 );
