(function ($) {
	'use strict';

	if (typeof sfaClLookup === 'undefined') {
		return;
	}

	var debounceTimer = null;
	var currentXhr    = null;
	var DEBOUNCE_MS   = 600;
	var MIN_DIGITS    = 9;

	/**
	 * Strip phone to digits only.
	 */
	function stripPhone(val) {
		return val.replace(/[^0-9]/g, '');
	}

	/**
	 * Convert semantic key to CSS class: name_arabic => sf-field-name-arabic
	 */
	function fieldClass(key) {
		return 'sf-field-' + key.replace(/_/g, '-');
	}

	/**
	 * Find the actual input/select inside a GF field wrapper that has the CSS class.
	 * GF adds CSS classes to the wrapper <li>, so we need to find the input inside it.
	 */
	function findInput(cssClass) {
		// First try direct input match
		var $direct = $('input.' + cssClass + ', select.' + cssClass + ', textarea.' + cssClass);
		if ($direct.length) {
			return $direct;
		}

		// Then try finding input inside a GF field wrapper with that class
		var $wrapper = $('li.' + cssClass + ', .gfield.' + cssClass);
		if ($wrapper.length) {
			return $wrapper.find('input, select, textarea').first();
		}

		return $();
	}

	/**
	 * Populate form fields from lookup result.
	 */
	function populateFields(fields) {
		$.each(fields, function (key, value) {
			var $el = findInput(fieldClass(key));
			if ($el.length) {
				$el.val(value).trigger('change');
				$el.closest('.gfield').addClass('sf-lookup-populated');
			}
		});
	}

	/**
	 * Clear previously populated fields within the same form.
	 */
	function clearPopulated($scope) {
		var $container = $scope ? $scope.closest('form') : $(document);
		$container.find('.sf-lookup-populated').each(function () {
			$(this).find('input, select, textarea').first().val('').trigger('change');
			$(this).removeClass('sf-lookup-populated');
		});
	}

	/**
	 * Show a temporary message next to the phone field.
	 */
	function showMessage($phoneField, text) {
		// Remove any existing message
		$phoneField.closest('.gfield').find('.sf-cl-message').remove();

		var $msg = $('<span class="sf-cl-message" style="color:#d63638;margin-inline-start:8px;font-size:13px;"></span>')
			.text(text);

		$phoneField.after($msg);

		setTimeout(function () {
			$msg.fadeOut(200, function () {
				$msg.remove();
			});
		}, 3000);
	}

	/**
	 * Perform the AJAX lookup.
	 */
	function doLookup(phone, $phoneField) {
		// Abort any in-flight request
		if (currentXhr && currentXhr.readyState !== 4) {
			currentXhr.abort();
		}

		currentXhr = $.post(sfaClLookup.ajax_url, {
			action:   'sfa_cl_lookup',
			_wpnonce: sfaClLookup.nonce,
			phone:    phone
		})
		.done(function (response) {
			if (response && response.success && response.data && response.data.found) {
				populateFields(response.data.fields);
			} else {
				clearPopulated($phoneField);
				var msg = (sfaClLookup.i18n && sfaClLookup.i18n.not_found) || 'Number not registered';
				showMessage($phoneField, msg);
			}
		})
		.fail(function (jqXHR, textStatus) {
			// Fail silently — do not block form submission
			if (textStatus !== 'abort') {
				console.warn('SF Customer Lookup failed:', textStatus);
			}
		});
	}

	/**
	 * Bind to phone input fields.
	 */
	$(document).on('input', '.sf-customer-phone input, input.sf-customer-phone', function () {
		var $this  = $(this);
		var digits = stripPhone($this.val());

		clearTimeout(debounceTimer);

		if (digits.length < MIN_DIGITS) {
			return;
		}

		debounceTimer = setTimeout(function () {
			doLookup(digits, $this);
		}, DEBOUNCE_MS);
	});

})(jQuery);
