<?php
namespace SFA\SimpleNotes\Frontend;

/**
 * Auto-positioning for Gravity Forms and GravityFlow pages
 *
 * IMPORTANT: DUAL IMPLEMENTATION ARCHITECTURE
 * ==========================================
 * The Simple Notes system has TWO SEPARATE implementations:
 *
 * 1. FRONTEND (This File - AutoPositioning.php):
 *    - Location: Workflow inbox pages (frontend entry view)
 *    - Implementation: Standalone inline JavaScript
 *    - Functions: addNoteFrontend(), loadNotesFrontend(), deleteFrontendNote()
 *    - Element IDs: note-content-frontend-{id}, notes-list-frontend-{id}
 *    - CSS Classes: author-name-clickable-frontend
 *
 * 2. BACKEND (notes.js):
 *    - Location: Admin entry detail pages
 *    - Implementation: SimpleNotes JavaScript object
 *    - Functions: SimpleNotes.addNote(), SimpleNotes.renderNotes(), etc.
 *    - Element IDs: note-content-{id}, notes-list-{id}
 *    - CSS Classes: author-name-clickable
 *
 * When making changes to features (like clickable author names), you MUST
 * update BOTH implementations independently. They do not share code.
 */
class AutoPositioning {

	public function __construct() {
		add_action( 'admin_footer', [ $this, 'add_notes_to_entry_pages' ] );
		add_action( 'wp_footer', [ $this, 'add_notes_to_entry_pages' ] );
	}

	/**
	 * Automatic positioning for entry pages
	 */
	public function add_notes_to_entry_pages() {
		if ( ! isset( $_GET['lid'] ) || ! isset( $_GET['view'] ) || $_GET['view'] != 'entry' ) {
			return;
		}

		$entry_id           = intval( $_GET['lid'] );
		$is_workflow_inbox  = ( strpos( $_SERVER['REQUEST_URI'], 'workflow-inbox' ) !== false );
		$is_admin           = is_admin();

		if ( $is_workflow_inbox ) {
			$this->add_frontend_notes_widget( $entry_id );
		} elseif ( $is_admin ) {
			$this->add_admin_notes_widget( $entry_id );
		}
	}

	private function add_frontend_notes_widget( $entry_id ) {
		$nonce = wp_create_nonce( 'simple_notes_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		jQuery(document).ready(function() {
			setTimeout(function() {
				// Find workflow section
				var workflowSection = null;
				var workflowHeading = jQuery('h3:contains("Workflow"), h2:contains("Workflow"), h4:contains("Workflow")').first();

				if (workflowHeading.length > 0) {
					workflowSection = workflowHeading.closest('div[class*="widget"], div[class*="section"], div[style*="border"], .workflow-widget, .workflow-section');

					if (workflowSection.length === 0) {
						workflowSection = workflowHeading.parent();
					}
				}

				// Create notes widget
				if (workflowSection && workflowSection.length > 0) {
					var notesWidget = jQuery(`
						<div class="notes-widget-container" style="background: white; border: 1px solid #ddd; border-radius: 6px; margin-top: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
							<div class="widget-header" style="background: #f8f9fa; border-bottom: 1px solid #ddd; padding: 12px 15px;">
								<h3 style="margin: 0; font-size: 16px; color: #333; font-weight: 600;">Notes with Mentions</h3>
							</div>
							<div class="widget-content" style="padding: 15px;">
								<div class="add-note-section" style="margin-bottom: 15px; position: relative;">
									<textarea id="note-content-frontend-<?php echo $entry_id; ?>" placeholder="Add a note... Use @username to mention users" style="width: 100%; height: 60px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; resize: vertical; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; box-sizing: border-box;"></textarea>
									<div id="mention-dropdown-frontend-<?php echo $entry_id; ?>" class="mention-dropdown" style="display: none; position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; z-index: 10000; width: 200px;"></div>
									<button onclick="addNoteFrontend(<?php echo $entry_id; ?>)" style="background: #0073aa; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; margin-top: 8px; font-size: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">Add Note</button>
								</div>
								<div id="notes-list-frontend-<?php echo $entry_id; ?>" style="max-height: 300px; overflow-y: auto;">
									<div style="text-align: center; color: #666; font-size: 12px;">Loading notes...</div>
								</div>
							</div>
						</div>
					`);

					workflowSection.after(notesWidget);
					setupFrontendMentions(<?php echo $entry_id; ?>);
					loadNotesFrontend(<?php echo $entry_id; ?>);
				}
			}, 2000);
		});

		function setupFrontendMentions(entityId) {
			var textarea = jQuery("#note-content-frontend-" + entityId);
			var dropdown = jQuery("#mention-dropdown-frontend-" + entityId);

			textarea.on("input", function(e) {
				var text = jQuery(this).val();
				var cursorPos = this.selectionStart;
				var beforeCursor = text.substring(0, cursorPos);
				var lastAtIndex = beforeCursor.lastIndexOf("@");

				if (lastAtIndex !== -1) {
					var afterAt = beforeCursor.substring(lastAtIndex + 1);
					if (afterAt.indexOf(" ") === -1 && afterAt.indexOf("\n") === -1) {
						if (afterAt.length >= 1) {
							searchUsersFrontend(afterAt, entityId);
						} else {
							dropdown.hide();
						}
					} else {
						dropdown.hide();
					}
				} else {
					dropdown.hide();
				}
			});
		}

		function searchUsersFrontend(query, entityId) {
			var dropdown = jQuery("#mention-dropdown-frontend-" + entityId);

			jQuery.ajax({
				url: '<?php echo $ajax_url; ?>',
				method: "POST",
				data: {
					action: "simple_notes_search_users",
					nonce: '<?php echo $nonce; ?>',
					query: query
				},
				success: function(response) {
					if (response.success && response.data.length > 0) {
						var html = "";
						response.data.forEach(function(user) {
							html += '<div class="mention-item" data-username="' + user.username + '" data-display="' + user.display_name + '" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;">';
							html += '<strong>' + user.display_name + '</strong> <span style="color: #666;">@' + user.username + '</span>';
							html += '</div>';
						});
						dropdown.html(html).show();

						dropdown.find(".mention-item").on("click", function() {
							insertFrontendMention(entityId, jQuery(this).data("username"));
						});
					} else {
						dropdown.hide();
					}
				}
			});
		}

		function insertFrontendMention(entityId, username) {
			var textarea = jQuery("#note-content-frontend-" + entityId);
			var text = textarea.val();
			var cursorPos = textarea[0].selectionStart;
			var beforeCursor = text.substring(0, cursorPos);
			var lastAtIndex = beforeCursor.lastIndexOf("@");

			if (lastAtIndex !== -1) {
				var beforeAt = text.substring(0, lastAtIndex);
				var afterCursor = text.substring(cursorPos);
				var newText = beforeAt + "@" + username + " " + afterCursor;

				textarea.val(newText);
				var newCursorPos = lastAtIndex + username.length + 2;
				textarea[0].setSelectionRange(newCursorPos, newCursorPos);
			}

			jQuery("#mention-dropdown-frontend-" + entityId).hide();
			textarea.focus();
		}

		function addNoteFrontend(entityId) {
			var content = jQuery('#note-content-frontend-' + entityId).val().trim();
			if (!content) {
				alert('Please enter a note');
				return;
			}

			var button = jQuery('button[onclick*="addNoteFrontend"]');
			button.text('Adding...').prop('disabled', true);

			jQuery.ajax({
				url: '<?php echo $ajax_url; ?>',
				type: 'POST',
				data: {
					action: 'simple_notes_add',
					entity_type: 'gravity_form_entry',
					entity_id: entityId,
					content: content,
					nonce: '<?php echo $nonce; ?>'
				},
				success: function(response) {
					button.text('Add Note').prop('disabled', false);

					if (response.success) {
						jQuery('#note-content-frontend-' + entityId).val('');
						loadNotesFrontend(entityId);

						var message = 'Note added successfully!';
						if (response.data.mentions_sent > 0) {
							message += ' ' + response.data.mentions_sent + ' user(s) have been notified by email.';
						}
						alert(message);
					} else {
						alert('Error adding note: ' + (response.data || 'Unknown error'));
					}
				}
			});
		}

		function loadNotesFrontend(entityId) {
			jQuery.ajax({
				url: '<?php echo $ajax_url; ?>',
				type: 'POST',
				data: {
					action: 'simple_notes_get',
					entity_type: 'gravity_form_entry',
					entity_id: entityId,
					nonce: '<?php echo $nonce; ?>'
				},
				success: function(response) {
					if (response.success) {
						var html = '';
						if (response.data.length > 0) {
							response.data.forEach(function(note) {
								var processedContent = note.content.replace(/@([a-zA-Z0-9_.-]+)/g, '<span style="background: #e3f2fd; color: #1976d2; padding: 2px 4px; border-radius: 3px; font-weight: 500;">@$1</span>');
								var deleteButton = '';

								if (note.can_delete) {
									deleteButton = '<button onclick="deleteFrontendNote(' + note.id + ', ' + entityId + ')" style="background: #dc3545; color: white; padding: 2px 6px; border: none; border-radius: 3px; cursor: pointer; font-size: 10px; margin-left: 10px;">Delete</button>';
								}

								// Escape author name and username for safe HTML output
								var authorName = String(note.author_name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
								var username = String(note.author_username || '').replace(/"/g, '&quot;');

								html += '<div style="border-bottom: 1px solid #eee; padding: 8px 0; font-size: 12px;">';
								html += '<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">';
								html += '<strong class="author-name-clickable-frontend" data-username="' + username + '" data-entity-id="' + entityId + '" style="cursor: pointer; color: #0073aa; user-select: none; text-decoration: none;">' + authorName + '</strong>';
								html += '<div>';
								html += '<span style="color: #666; font-size: 10px;">' + note.created_at + '</span>';
								html += deleteButton;
								html += '</div></div>';
								html += '<div style="color: #555; line-height: 1.4;">' + processedContent + '</div>';
								html += '</div>';
							});
						} else {
							html = '<div style="text-align: center; color: #666; font-size: 12px; font-style: italic;">No notes yet</div>';
						}
						jQuery('#notes-list-frontend-' + entityId).html(html);

						// Attach click handler for author names
						jQuery('#notes-list-frontend-' + entityId).off('click', '.author-name-clickable-frontend').on('click', '.author-name-clickable-frontend', function(e) {
							e.preventDefault();
							e.stopPropagation();

							var username = jQuery(this).attr('data-username');
							var entityId = jQuery(this).attr('data-entity-id');

							if (username && entityId) {
								var textarea = jQuery("#note-content-frontend-" + entityId);
								var currentText = textarea.val();
								var mention = "@" + username + " ";

								if (currentText && !currentText.endsWith(" ")) {
									mention = " " + mention;
								}

								textarea.val(currentText + mention);
								textarea.focus();
							}
						});
					}
				}
			});
		}

		function deleteFrontendNote(noteId, entityId) {
			if (!confirm("Are you sure you want to delete this note?")) {
				return;
			}

			jQuery.ajax({
				url: "<?php echo $ajax_url; ?>",
				method: "POST",
				data: {
					action: 'simple_notes_delete',
					note_id: noteId,
					nonce: "<?php echo $nonce; ?>"
				},
				success: function(response) {
					if (response.success) {
						alert("Note deleted successfully");
						loadNotesFrontend(entityId);
					} else {
						alert("Error: " + response.data);
					}
				}
			});
		}
		</script>

		<style>
		.mention-dropdown .mention-item:hover,
		.mention-dropdown .mention-item.selected {
			background: #f0f0f1;
		}
		</style>
		<?php
	}

	private function add_admin_notes_widget( $entry_id ) {
		?>
		<script>
		jQuery(document).ready(function() {
			setTimeout(function() {
				if (typeof SimpleNotes === 'undefined') {
					setTimeout(arguments.callee, 500);
					return;
				}

				if (jQuery('#simple-notes-container').length > 0) {
					return;
				}

				var sidebar = jQuery('#postbox-container-1');

				if (sidebar.length > 0) {
					var widget = jQuery('<div class="postbox"><div class="postbox-header"><h2 class="hndle ui-sortable-handle">Notes with Mentions</h2></div><div class="inside" id="simple-notes-container"></div></div>');

					var entryInfoPostbox = sidebar.find('.postbox').filter(function() {
						var heading = jQuery(this).find('h2.hndle, h3.hndle');
						return heading.text().trim() === 'Entry Information';
					});

					if (entryInfoPostbox.length > 0) {
						entryInfoPostbox.after(widget);
					} else {
						var firstPostbox = sidebar.find('.postbox').first();
						if (firstPostbox.length > 0) {
							firstPostbox.after(widget);
						} else {
							sidebar.append(widget);
						}
					}

					SimpleNotes.init('#simple-notes-container', 'gravity_form_entry', '<?php echo $entry_id; ?>');
				}
			}, 1000);
		});
		</script>
		<?php
	}
}
