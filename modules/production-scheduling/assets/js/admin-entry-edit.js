/**
 * Admin Entry Edit - Capacity Check
 *
 * Shows confirmation dialog when admin manually edits installation date
 * to a date that would cause overbooking.
 */
(function($) {
    'use strict';

    // Only run on Gravity Forms entry detail page
    if (typeof gform === 'undefined' || !$('#entry_form').length) {
        return;
    }

    let isCheckingCapacity = false;
    let capacityCheckPassed = false;

    /**
     * Check if the new installation date causes overbooking
     */
    function checkCapacityBeforeSave(installDate, entryId) {
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sfa_prod_check_capacity_before_save',
                entry_id: entryId,
                install_date: installDate,
                nonce: sfaProdAdmin.nonce
            }
        });
    }

    /**
     * Find the installation date field value
     */
    function getInstallationDate() {
        // Try common field patterns
        let installDate = null;

        // Pattern 1: input_X_Y format (where X is form ID, Y is field ID)
        $('input[id*="input_"][id*="_"]').each(function() {
            let $field = $(this);
            let fieldName = $field.attr('name') || '';

            // Check if this looks like an installation date field
            if (fieldName.toLowerCase().includes('install') ||
                $field.closest('.gfield').find('label').text().toLowerCase().includes('install')) {
                installDate = $field.val();
                return false; // Break loop
            }
        });

        return installDate;
    }

    /**
     * Get entry ID from URL or form
     */
    function getEntryId() {
        // Try URL parameter
        let urlParams = new URLSearchParams(window.location.search);
        let entryId = urlParams.get('lid');

        if (!entryId) {
            // Try hidden field in form
            entryId = $('input[name="entry_id"]').val();
        }

        if (!entryId) {
            // Try from form action URL
            let action = $('#entry_form').attr('action') || '';
            let match = action.match(/lid=(\d+)/);
            if (match) {
                entryId = match[1];
            }
        }

        return entryId;
    }

    /**
     * Show confirmation dialog with overbooking details
     */
    function showOverbookingConfirmation(overbookingData) {
        let message = '⚠️ CAPACITY WARNING\n\n';
        message += 'This installation date will EXCEED production capacity:\n\n';

        overbookingData.overbooked_dates.forEach(function(dateInfo) {
            message += '• ' + dateInfo.date_formatted + ': ' + dateInfo.new_total + '/' + dateInfo.capacity + ' LM ';
            message += '(' + dateInfo.overage + ' LM over capacity)\n';
        });

        message += '\nAs an administrator, you can still save this booking.\n';
        message += 'Regular users would be blocked from making this booking.\n\n';
        message += 'Do you want to continue?';

        return confirm(message);
    }

    /**
     * Intercept form submission
     */
    $('#entry_form').on('submit', function(e) {
        // If we already checked and got approval, allow submission
        if (capacityCheckPassed) {
            return true;
        }

        // If we're currently checking, prevent submission
        if (isCheckingCapacity) {
            e.preventDefault();
            return false;
        }

        // Get installation date and entry ID
        let installDate = getInstallationDate();
        let entryId = getEntryId();

        if (!installDate || !entryId) {
            // No installation date found, allow normal submission
            return true;
        }

        // Prevent submission while we check capacity
        e.preventDefault();
        isCheckingCapacity = true;

        // Show loading indicator
        let $submitBtn = $('#entry_form input[type="submit"]');
        let originalBtnText = $submitBtn.val();
        $submitBtn.val('Checking capacity...').prop('disabled', true);

        // Check capacity via AJAX
        checkCapacityBeforeSave(installDate, entryId)
            .done(function(response) {
                if (response.success) {
                    if (response.data.has_overbooking) {
                        // Show confirmation dialog
                        if (showOverbookingConfirmation(response.data)) {
                            // Admin confirmed - allow submission
                            capacityCheckPassed = true;
                            $('#entry_form').submit();
                        } else {
                            // Admin cancelled - restore button
                            $submitBtn.val(originalBtnText).prop('disabled', false);
                        }
                    } else {
                        // No overbooking - allow submission
                        capacityCheckPassed = true;
                        $('#entry_form').submit();
                    }
                } else {
                    // Error in check - show error and restore button
                    alert('Error checking capacity: ' + (response.data.message || 'Unknown error'));
                    $submitBtn.val(originalBtnText).prop('disabled', false);
                }
            })
            .fail(function(xhr, status, error) {
                // AJAX failed - allow submission anyway (graceful degradation)
                console.error('Capacity check failed:', error);
                capacityCheckPassed = true;
                $('#entry_form').submit();
            })
            .always(function() {
                isCheckingCapacity = false;
            });

        return false;
    });

})(jQuery);
