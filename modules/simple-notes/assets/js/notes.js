/**
 * Simple Notes JavaScript
 * Handles notes widget, mentions, and AJAX operations
 */

window.SimpleNotes = {
	ajaxUrl: window.simpleNotesConfig?.ajaxUrl || '',
	nonce: window.simpleNotesConfig?.nonce || '',
	currentUser: window.simpleNotesConfig?.currentUser || { id: 0, name: '' },

	init: function(container, entityType, entityId) {
		var self = this;
		var $container = jQuery(container);

		console.log("SimpleNotes: Initializing", entityType, entityId);

		var html = `
			<div class="simple-notes-container" style="border: 1px solid #ccc; padding: 15px; margin: 10px 0; background: #fff;">
				<div class="add-note-section" style="margin-bottom: 20px; position: relative;">
					<textarea id="note-content-${entityId}" placeholder="Add a note... Use @username to mention users" style="width: 100%; height: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;"></textarea>
					<div id="mention-dropdown-${entityId}" class="mention-dropdown" style="display: none; position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; z-index: 10000; width: 200px;"></div>
					<br><br>
					<button class="sn-add-note-btn" data-entity-type="${entityType}" data-entity-id="${entityId}" style="background: #0073aa; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">Add Note</button>
				</div>

				<div id="notes-list-${entityId}" class="notes-list" data-entity-type="${entityType}">
					<div style="text-align: center; color: #666;">Loading notes...</div>
				</div>
			</div>
		`;

		$container.html(html);

		// Attach add note button click handler
		$container.off('click', '.sn-add-note-btn').on('click', '.sn-add-note-btn', function() {
			var entityType = jQuery(this).data('entity-type');
			var entityId = jQuery(this).data('entity-id');
			self.addNote(entityType, entityId);
		});

		this.setupMentions(entityId);
		this.loadNotes(entityType, entityId);
	},

	setupMentions: function(entityId) {
		var self = this;
		var textarea = jQuery("#note-content-" + entityId);
		var dropdown = jQuery("#mention-dropdown-" + entityId);

		textarea.on("input", function(e) {
			var text = jQuery(this).val();
			var cursorPos = this.selectionStart;

			var beforeCursor = text.substring(0, cursorPos);
			var lastAtIndex = beforeCursor.lastIndexOf("@");

			if (lastAtIndex !== -1) {
				var afterAt = beforeCursor.substring(lastAtIndex + 1);

				if (afterAt.indexOf(" ") === -1 && afterAt.indexOf("\n") === -1) {
					if (afterAt.length >= 1) {
						self.searchUsers(afterAt, entityId);
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

		textarea.on("keydown", function(e) {
			if (dropdown.is(":visible")) {
				var items = dropdown.find(".mention-item");
				var selected = dropdown.find(".mention-item.selected");

				if (e.keyCode === 38) { // Up arrow
					e.preventDefault();
					if (selected.length === 0) {
						items.last().addClass("selected");
					} else {
						selected.removeClass("selected");
						var prev = selected.prev(".mention-item");
						if (prev.length > 0) {
							prev.addClass("selected");
						} else {
							items.last().addClass("selected");
						}
					}
				} else if (e.keyCode === 40) { // Down arrow
					e.preventDefault();
					if (selected.length === 0) {
						items.first().addClass("selected");
					} else {
						selected.removeClass("selected");
						var next = selected.next(".mention-item");
						if (next.length > 0) {
							next.addClass("selected");
						} else {
							items.first().addClass("selected");
						}
					}
				} else if (e.keyCode === 13) { // Enter
					e.preventDefault();
					if (selected.length > 0) {
						selected.click();
					}
				} else if (e.keyCode === 27) { // Escape
					dropdown.hide();
				}
			}
		});

		textarea.on("focus input", function() {
			setTimeout(function() {
				self.positionDropdown(textarea, dropdown);
			}, 10);
		});
	},

	positionDropdown: function(textarea, dropdown) {
		var textareaOffset = textarea.offset();
		var textareaHeight = textarea.outerHeight();
		var dropdownHeight = dropdown.outerHeight() || 200;
		var windowHeight = jQuery(window).height();
		var scrollTop = jQuery(window).scrollTop();

		var spaceBelow = windowHeight - (textareaOffset.top - scrollTop + textareaHeight);
		var spaceAbove = textareaOffset.top - scrollTop;

		var top, left;

		if (spaceBelow >= dropdownHeight || spaceBelow >= spaceAbove) {
			top = textareaOffset.top + textareaHeight + 5;
		} else {
			top = textareaOffset.top - dropdownHeight - 5;
		}

		left = textareaOffset.left;

		var windowWidth = jQuery(window).width();
		var dropdownWidth = 200;
		if (left + dropdownWidth > windowWidth) {
			left = windowWidth - dropdownWidth - 10;
		}

		dropdown.css({
			position: "fixed",
			top: top - scrollTop,
			left: left,
			width: dropdownWidth + "px"
		});
	},

	searchUsers: function(query, entityId) {
		var self = this;
		var dropdown = jQuery("#mention-dropdown-" + entityId);

		jQuery.ajax({
			url: this.ajaxUrl,
			method: "POST",
			data: {
				action: "simple_notes_search_users",
				nonce: this.nonce,
				query: query
			},
			success: function(response) {
				if (response.success && response.data.length > 0) {
					var html = "";
					response.data.forEach(function(user) {
						html += `<div class="mention-item" data-username="${user.username}" data-display="${user.display_name}" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 13px;">`;
						html += `<strong>${user.display_name}</strong> <span style="color: #666;">@${user.username}</span>`;
						html += `</div>`;
					});
					dropdown.html(html).show();

					var textarea = jQuery("#note-content-" + entityId);
					self.positionDropdown(textarea, dropdown);

					dropdown.find(".mention-item").on("click", function() {
						self.insertMention(entityId, jQuery(this).data("username"), jQuery(this).data("display"));
					});

					dropdown.find(".mention-item").on("mouseenter", function() {
						dropdown.find(".mention-item").removeClass("selected");
						jQuery(this).addClass("selected");
					});
				} else {
					dropdown.hide();
				}
			}
		});
	},

	insertMention: function(entityId, username, displayName) {
		var textarea = jQuery("#note-content-" + entityId);
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

		jQuery("#mention-dropdown-" + entityId).hide();
		textarea.focus();
	},

	addNote: function(entityType, entityId) {
		var content = jQuery("#note-content-" + entityId).val().trim();
		if (!content) {
			alert("Please enter a note");
			return;
		}

		var button = jQuery("button[onclick*='addNote']");
		var originalText = button.text();
		button.text("Adding...").prop("disabled", true);

		jQuery.ajax({
			url: this.ajaxUrl,
			method: "POST",
			data: {
				action: "simple_notes_add",
				nonce: this.nonce,
				entity_type: entityType,
				entity_id: entityId,
				content: content
			},
			success: function(response) {
				button.text(originalText).prop("disabled", false);

				if (response.success) {
					jQuery("#note-content-" + entityId).val("");
					SimpleNotes.loadNotes(entityType, entityId);

					var message = "Note added successfully!";
					if (response.data.mentions_sent > 0) {
						message += " " + response.data.mentions_sent + " user(s) have been notified by email.";
					}
					alert(message);
				} else {
					alert("Error: " + (response.data || "Failed to add note"));
				}
			},
			error: function(xhr, status, error) {
				button.text(originalText).prop("disabled", false);
				console.error("AJAX Error:", xhr.responseText);
				alert("Network error. Please try again. Check console for details.");
			}
		});
	},

	loadNotes: function(entityType, entityId) {
		jQuery.ajax({
			url: this.ajaxUrl,
			method: "POST",
			data: {
				action: "simple_notes_get",
				nonce: this.nonce,
				entity_type: entityType,
				entity_id: entityId
			},
			success: function(response) {
				if (response.success) {
					SimpleNotes.renderNotes(entityId, response.data);
				} else {
					jQuery("#notes-list-" + entityId).html('<div style="color: red;">Error loading notes</div>');
				}
			},
			error: function() {
				jQuery("#notes-list-" + entityId).html('<div style="color: red;">Network error</div>');
			}
		});
	},

	renderNotes: function(entityId, notes) {
		var self = this;
		var html = "";
		if (notes.length === 0) {
			html = '<div style="text-align: center; color: #666; font-style: italic;">No notes yet. Add the first one above!</div>';
		} else {
			for (var i = 0; i < notes.length; i++) {
				var note = notes[i];
				var processedContent = this.processMentions(note.content);
				var deleteButton = "";

				if (note.can_delete) {
					deleteButton = '<button class="sn-delete-btn" data-note-id="' + note.id + '" data-entity-id="' + entityId + '" style="background: #dc3545; color: white; padding: 2px 6px; border: none; border-radius: 3px; cursor: pointer; font-size: 10px; margin-left: 10px;">Delete</button>';
				}

				// Escape username and author name for safe insertion
				var escapedUsername = String(note.author_username || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
				var escapedAuthorName = String(note.author_name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

				html += `
					<div style="border-bottom: 1px solid #eee; padding: 10px 0;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
							<strong class="author-name-clickable" data-username="${escapedUsername}" data-entity-id="${entityId}" style="cursor: pointer; color: #0073aa;">${escapedAuthorName}</strong>
							<div>
								<small style="color: #666;">${note.created_at}</small>
								${deleteButton}
							</div>
						</div>
						<div style="line-height: 1.4;">${processedContent}</div>
					</div>
				`;
			}
		}

		var $notesList = jQuery("#notes-list-" + entityId);
		$notesList.html(html);

		// Attach click handlers using event delegation (safer and handles special characters)
		$notesList.off('click', '.author-name-clickable').on('click', '.author-name-clickable', function() {
			var username = jQuery(this).data('username');
			var entityId = jQuery(this).data('entity-id');
			self.mentionUser(username, entityId);
		});

		// Attach delete button click handler
		$notesList.off('click', '.sn-delete-btn').on('click', '.sn-delete-btn', function() {
			var noteId = jQuery(this).data('note-id');
			var entityId = jQuery(this).data('entity-id');
			self.deleteNote(noteId, entityId);
		});
	},

	processMentions: function(content) {
		return content.replace(/@([a-zA-Z0-9_.-]+)/g, '<span style="background: #e3f2fd; color: #1976d2; padding: 2px 4px; border-radius: 3px; font-weight: 500;">@$1</span>');
	},

	mentionUser: function(username, entityId) {
		var textarea = jQuery("#note-content-" + entityId);
		var currentText = textarea.val();
		var mention = "@" + username + " ";

		if (currentText && !currentText.endsWith(" ")) {
			mention = " " + mention;
		}

		textarea.val(currentText + mention);
		textarea.focus();

		var textareaElement = textarea[0];
		textareaElement.setSelectionRange(textareaElement.value.length, textareaElement.value.length);
	},

	deleteNote: function(noteId, entityId) {
		if (!confirm("Are you sure you want to delete this note?")) {
			return;
		}

		jQuery.ajax({
			url: this.ajaxUrl,
			method: "POST",
			data: {
				action: "simple_notes_delete",
				note_id: noteId,
				nonce: this.nonce
			},
			success: function(response) {
				if (response.success) {
					alert("Note deleted successfully");
					// Reload notes - we need to store entity type somewhere
					var $list = jQuery("#notes-list-" + entityId);
					var entityType = $list.data("entity-type") || 'gravity_form_entry';
					SimpleNotes.loadNotes(entityType, entityId);
				} else {
					alert("Error: " + response.data);
				}
			},
			error: function() {
				alert("Error deleting note");
			}
		});
	}
};

// Auto-initialize any widgets on page load
jQuery(document).ready(function() {
	jQuery(".simple-notes-widget").each(function() {
		var entityType = jQuery(this).data("entity-type");
		var entityId = jQuery(this).data("entity-id");
		if (entityType && entityId) {
			SimpleNotes.init(this, entityType, entityId);
		}
	});

	console.log("SimpleNotes: Ready");
});
