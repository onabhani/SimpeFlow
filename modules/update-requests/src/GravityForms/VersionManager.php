<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Version Manager
 *
 * Manages file version history for drawings and invoices
 * Stores version data in JSON format in entry meta
 */
class VersionManager {

	const META_KEY = '_ur_file_versions';

	/**
	 * Get all versions for entry
	 *
	 * @param int $entry_id Entry ID
	 * @return array Array of file versions
	 */
	public function get_versions( $entry_id ) {
		$versions_json = gform_get_meta( $entry_id, self::META_KEY );

		if ( ! $versions_json ) {
			return [];
		}

		$versions = json_decode( $versions_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( sprintf(
				'Update Requests: Corrupt JSON in %s for entry %d: %s',
				self::META_KEY,
				$entry_id,
				json_last_error_msg()
			) );
			return [];
		}

		return is_array( $versions ) ? $versions : [];
	}

	/**
	 * Get current version info for a specific file
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $filename Filename to get version for
	 * @return array|null Version info or null if not found
	 */
	public function get_current_version( $entry_id, $filename ) {
		$all_versions = $this->get_versions( $entry_id );

		if ( ! isset( $all_versions[ $filename ] ) ) {
			return null;
		}

		$file_versions = $all_versions[ $filename ];

		if ( ! isset( $file_versions['versions'] ) || empty( $file_versions['versions'] ) ) {
			return null;
		}

		// Get latest version (last in array)
		$versions_array = $file_versions['versions'];
		return end( $versions_array );
	}

	/**
	 * Get version history for a specific file
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $filename Filename to get history for
	 * @return array Array of version info
	 */
	public function get_file_history( $entry_id, $filename ) {
		$all_versions = $this->get_versions( $entry_id );

		if ( ! isset( $all_versions[ $filename ] ) ) {
			return [];
		}

		$file_versions = $all_versions[ $filename ];

		return isset( $file_versions['versions'] ) ? $file_versions['versions'] : [];
	}

	/**
	 * Add new version for a file
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $filename Original filename
	 * @param string $file_url URL of new version
	 * @param string $status   Status (original, approved, rejected)
	 * @param array  $metadata Additional metadata (reason, child_entry_id, etc.)
	 * @return int New version number
	 */
	public function add_version( $entry_id, $filename, $file_url, $status = 'pending', $metadata = [] ) {
		$all_versions = $this->get_versions( $entry_id );

		// Initialize file if it doesn't exist
		if ( ! isset( $all_versions[ $filename ] ) ) {
			$all_versions[ $filename ] = [
				'current_version' => 0,
				'versions' => [],
			];
		}

		// Calculate new version number
		$current_version_number = $all_versions[ $filename ]['current_version'];
		$new_version_number = $current_version_number + 1;

		// Create version entry
		$version_entry = [
			'version' => $new_version_number,
			'filename' => basename( $file_url ),
			'url' => $file_url,
			'date' => current_time( 'mysql' ),
			'user_id' => get_current_user_id(),
			'user_name' => is_user_logged_in() ? wp_get_current_user()->display_name : 'System',
			'status' => $status,
		];

		// Add metadata
		if ( ! empty( $metadata ) ) {
			$version_entry = array_merge( $version_entry, $metadata );
		}

		// Add to versions array
		$all_versions[ $filename ]['versions'][] = $version_entry;
		$all_versions[ $filename ]['current_version'] = $new_version_number;

		// Save to entry meta
		gform_update_meta( $entry_id, self::META_KEY, wp_json_encode( $all_versions ) );

		return $new_version_number;
	}

	/**
	 * Initialize original files from entry field
	 *
	 * This should be called once when entry is first created to
	 * set version 1 for all original files
	 *
	 * @param int   $entry_id Entry ID
	 * @param array $file_urls Array of file URLs from entry field
	 */
	public function initialize_original_files( $entry_id, $file_urls ) {
		$all_versions = $this->get_versions( $entry_id );

		// Skip if already initialized
		if ( ! empty( $all_versions ) ) {
			return;
		}

		foreach ( $file_urls as $file_url ) {
			$filename = basename( $file_url );

			$all_versions[ $filename ] = [
				'current_version' => 1,
				'versions' => [
					[
						'version' => 1,
						'filename' => $filename,
						'url' => $file_url,
						'date' => current_time( 'mysql' ),
						'user_id' => 0, // Original upload (not an update)
						'user_name' => 'Original Upload',
						'status' => 'original',
					],
				],
			];
		}

		// Save to entry meta
		gform_update_meta( $entry_id, self::META_KEY, wp_json_encode( $all_versions ) );
	}

	/**
	 * Update version status (for approval workflow)
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $filename Filename
	 * @param int    $version_number Version number
	 * @param string $new_status New status (approved, rejected)
	 */
	public function update_version_status( $entry_id, $filename, $version_number, $new_status ) {
		$all_versions = $this->get_versions( $entry_id );

		if ( ! isset( $all_versions[ $filename ] ) ) {
			return;
		}

		// Find and update the specific version
		foreach ( $all_versions[ $filename ]['versions'] as &$version ) {
			if ( $version['version'] === $version_number ) {
				$version['status'] = $new_status;

				if ( $new_status === 'approved' ) {
					$version['approved_at'] = current_time( 'mysql' );
					$version['approved_by'] = get_current_user_id();
				} elseif ( $new_status === 'rejected' ) {
					$version['rejected_at'] = current_time( 'mysql' );
					$version['rejected_by'] = get_current_user_id();
				}

				break;
			}
		}

		// Save to entry meta
		gform_update_meta( $entry_id, self::META_KEY, wp_json_encode( $all_versions ) );
	}

	/**
	 * Get total version count for a file
	 *
	 * @param int    $entry_id Entry ID
	 * @param string $filename Filename
	 * @return int Version count
	 */
	public function get_version_count( $entry_id, $filename ) {
		$history = $this->get_file_history( $entry_id, $filename );
		return count( $history );
	}
}
