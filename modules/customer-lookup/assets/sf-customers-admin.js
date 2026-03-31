(function ($) {
	'use strict';

	if (typeof sfaCustomers === 'undefined') {
		return;
	}

	var $phoneField   = $('#sfa_phone');
	var $submitBtn    = $('#sfa-submit-btn');
	var checkXhr      = null;
	var debounceTimer = null;

	if (!$phoneField.length) {
		return;
	}

	/**
	 * Check for duplicate phone on blur and input (debounced).
	 */
	$phoneField.on('blur', function () {
		var phone = $.trim($(this).val());
		if (phone.length >= 9) {
			checkPhone(phone);
		}
	});

	$phoneField.on('input', function () {
		clearTimeout(debounceTimer);
		clearError();
		$submitBtn.prop('disabled', false);

		var phone = $.trim($(this).val());
		if (phone.length >= 9) {
			debounceTimer = setTimeout(function () {
				checkPhone(phone);
			}, 400);
		}
	});

	/**
	 * AJAX duplicate phone check.
	 */
	function checkPhone(phone) {
		if (checkXhr && checkXhr.readyState !== 4) {
			checkXhr.abort();
		}

		var excludeId = $('input[name="exclude_id"]').val() || 0;

		checkXhr = $.post(sfaCustomers.ajax_url, {
			action:     'sfa_cl_check_phone_exists',
			_wpnonce:   sfaCustomers.nonce,
			phone:      phone,
			exclude_id: excludeId
		})
		.done(function (response) {
			if (response && response.success && response.data && response.data.exists) {
				showError(sfaCustomers.i18n.phone_exists);
				$submitBtn.prop('disabled', true);
			} else {
				clearError();
				$submitBtn.prop('disabled', false);
			}
		})
		.fail(function (jqXHR, textStatus) {
			if (textStatus !== 'abort') {
				clearError();
				$submitBtn.prop('disabled', false);
			}
		});
	}

	/**
	 * Show inline error below phone field.
	 */
	function showError(msg) {
		clearError();
		$phoneField.after(
			$('<span class="sfa-phone-error"></span>').text(msg)
		);
	}

	/**
	 * Clear inline error.
	 */
	function clearError() {
		$phoneField.siblings('.sfa-phone-error').remove();
	}

})(jQuery);
