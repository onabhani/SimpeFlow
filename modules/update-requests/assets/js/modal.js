/**
 * Update Requests Modal JavaScript
 */
(function($) {
	'use strict';

	$(document).ready(function() {

		// Open Update Request Modal
		$(document).on('click', '.sfa-ur-update-btn', function(e) {
			e.preventDefault();

			var entryId = $(this).data('entry-id');
			var formId = $(this).data('form-id');
			var filename = $(this).data('filename');
			var version = $(this).data('current-version');

			// Populate modal fields
			$('#sfa-ur-entry-id').val(entryId);
			$('#sfa-ur-form-id').val(formId);
			$('#sfa-ur-filename').val(filename);
			$('#sfa-ur-current-name').text(filename);
			$('#sfa-ur-current-version').text(version);

			// Reset form
			$('#sfa-ur-update-form')[0].reset();
			$('#sfa-ur-entry-id').val(entryId);
			$('#sfa-ur-form-id').val(formId);
			$('#sfa-ur-filename').val(filename);
			$('.sfa-ur-form-message').hide();

			// Show modal
			$('#sfa-ur-update-modal').fadeIn(200);
		});

		// Open Following Invoice Modal
		$(document).on('click', '.sfa-ur-following-btn', function(e) {
			e.preventDefault();

			var entryId = $(this).data('entry-id');
			var formId = $(this).data('form-id');

			// Populate modal fields
			$('#sfa-ur-following-entry-id').val(entryId);
			$('#sfa-ur-following-form-id').val(formId);

			// Reset form
			$('#sfa-ur-following-form')[0].reset();
			$('#sfa-ur-following-entry-id').val(entryId);
			$('#sfa-ur-following-form-id').val(formId);
			$('.sfa-ur-form-message').hide();

			// Show modal
			$('#sfa-ur-following-modal').fadeIn(200);
		});

		// Close modal on X click
		$(document).on('click', '.sfa-ur-modal-close, .sfa-ur-modal-cancel', function(e) {
			e.preventDefault();
			$(this).closest('.sfa-ur-modal').fadeOut(200);
		});

		// Close modal on outside click
		$(document).on('click', '.sfa-ur-modal', function(e) {
			if ($(e.target).hasClass('sfa-ur-modal')) {
				$(this).fadeOut(200);
			}
		});

		// Submit Update Request Form
		$(document).on('submit', '#sfa-ur-update-form', function(e) {
			e.preventDefault();

			var form = $(this);
			var formData = new FormData(this);
			var messageBox = form.find('.sfa-ur-form-message');

			// Add loading state
			form.closest('.sfa-ur-modal-content').addClass('loading');
			messageBox.hide();

			$.ajax({
				url: sfaUrData.ajaxurl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					form.closest('.sfa-ur-modal-content').removeClass('loading');

					if (response.success) {
						messageBox
							.removeClass('error info')
							.addClass('success')
							.html('<strong>Success!</strong> ' + response.data.message)
							.fadeIn();

						// Close modal after 2 seconds and reload page
						setTimeout(function() {
							$('#sfa-ur-update-modal').fadeOut(200);
							location.reload();
						}, 2000);
					} else {
						messageBox
							.removeClass('success info')
							.addClass('error')
							.html('<strong>Error:</strong> ' + response.data.message)
							.fadeIn();
					}
				},
				error: function(xhr, status, error) {
					form.closest('.sfa-ur-modal-content').removeClass('loading');
					messageBox
						.removeClass('success info')
						.addClass('error')
						.html('<strong>Error:</strong> Failed to submit request. Please try again.')
						.fadeIn();
				}
			});
		});

		// Submit Following Invoice Form
		$(document).on('submit', '#sfa-ur-following-form', function(e) {
			e.preventDefault();

			var form = $(this);
			var formData = new FormData(this);
			var messageBox = form.find('.sfa-ur-form-message');

			// Add loading state
			form.closest('.sfa-ur-modal-content').addClass('loading');
			messageBox.hide();

			$.ajax({
				url: sfaUrData.ajaxurl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					form.closest('.sfa-ur-modal-content').removeClass('loading');

					if (response.success) {
						messageBox
							.removeClass('error info')
							.addClass('success')
							.html('<strong>Success!</strong> ' + response.data.message)
							.fadeIn();

						// Close modal after 2 seconds and reload page
						setTimeout(function() {
							$('#sfa-ur-following-modal').fadeOut(200);
							location.reload();
						}, 2000);
					} else {
						messageBox
							.removeClass('success info')
							.addClass('error')
							.html('<strong>Error:</strong> ' + response.data.message)
							.fadeIn();
					}
				},
				error: function(xhr, status, error) {
					form.closest('.sfa-ur-modal-content').removeClass('loading');
					messageBox
						.removeClass('success info')
						.addClass('error')
						.html('<strong>Error:</strong> Failed to submit request. Please try again.')
						.fadeIn();
				}
			});
		});

	});

})(jQuery);
