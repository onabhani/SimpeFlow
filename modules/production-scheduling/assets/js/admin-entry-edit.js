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
     * Show capacity choice dialog
     * Returns: 'over_capacity', 'fill_spill', or null (cancelled)
     */
    function showCapacityChoiceDialog(data) {
        return new Promise(function(resolve) {
            // Remove existing dialog if any
            $('#sfa-capacity-dialog-overlay').remove();

            // Build over-capacity description
            let overCapacityDesc = 'Force all ' + data.total_lm + ' LM on ' + data.target_date_formatted;
            if (data.overbooked_dates && data.overbooked_dates.length > 0) {
                let overage = data.overbooked_dates[0].overage;
                overCapacityDesc += ' (exceeds by ' + overage + ' LM)';
            }

            // Build fill+spill description
            let fillSpillDesc = '';
            if (data.fill_spill_allocation && data.fill_spill_allocation.length > 0) {
                let parts = data.fill_spill_allocation.map(function(item) {
                    return item.lm + ' LM on ' + item.date_formatted;
                });
                fillSpillDesc = parts.join(', ');
            } else {
                fillSpillDesc = 'Fill available capacity, spill remainder to next day(s)';
            }

            // Create dialog HTML
            let html = '<div id="sfa-capacity-dialog-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;">';
            html += '<div style="background:#fff;padding:25px;border-radius:8px;max-width:500px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">';
            html += '<h3 style="margin:0 0 15px;color:#d63638;">⚠️ Capacity Warning</h3>';
            html += '<p style="margin:0 0 15px;color:#50575e;">The selected date <strong>' + data.target_date_formatted + '</strong> does not have enough capacity for ' + data.total_lm + ' LM.</p>';
            html += '<p style="margin:0 0 20px;color:#50575e;">Please choose how to handle this booking:</p>';

            // Option A: Over-capacity
            html += '<div id="sfa-option-over-capacity" class="sfa-capacity-option" style="border:2px solid #ddd;border-radius:6px;padding:15px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;">';
            html += '<strong style="color:#d63638;">Option A: Over-capacity</strong><br>';
            html += '<span style="color:#666;font-size:13px;">' + overCapacityDesc + '</span>';
            html += '</div>';

            // Option B: Fill + Spill
            html += '<div id="sfa-option-fill-spill" class="sfa-capacity-option" style="border:2px solid #ddd;border-radius:6px;padding:15px;margin-bottom:20px;cursor:pointer;transition:all 0.2s;">';
            html += '<strong style="color:#2271b1;">Option B: Fill + Spill</strong><br>';
            html += '<span style="color:#666;font-size:13px;">' + fillSpillDesc + '</span>';
            html += '</div>';

            // Buttons
            html += '<div style="text-align:right;">';
            html += '<button id="sfa-cancel-btn" type="button" style="padding:8px 16px;margin-right:10px;cursor:pointer;border:1px solid #ddd;background:#f6f7f7;border-radius:4px;">Cancel</button>';
            html += '</div>';

            html += '</div></div>';

            $('body').append(html);

            // Option hover effects
            $('.sfa-capacity-option').hover(
                function() { $(this).css('border-color', '#2271b1'); },
                function() { $(this).css('border-color', '#ddd'); }
            );

            // Option click handlers
            $('#sfa-option-over-capacity').on('click', function() {
                $('#sfa-capacity-dialog-overlay').remove();
                resolve('over_capacity');
            });

            $('#sfa-option-fill-spill').on('click', function() {
                $('#sfa-capacity-dialog-overlay').remove();
                resolve('fill_spill');
            });

            $('#sfa-cancel-btn').on('click', function() {
                $('#sfa-capacity-dialog-overlay').remove();
                resolve(null);
            });

            // Close on overlay click
            $('#sfa-capacity-dialog-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#sfa-capacity-dialog-overlay').remove();
                    resolve(null);
                }
            });
        });
    }

    /**
     * Add hidden field for capacity choice
     */
    function setCapacityChoice(choice) {
        // Remove existing hidden field
        $('input[name="sfa_capacity_choice"]').remove();

        if (choice) {
            // Add hidden field with choice
            $('#entry_form').append('<input type="hidden" name="sfa_capacity_choice" value="' + choice + '">');
        }
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
                        // Show choice dialog
                        showCapacityChoiceDialog(response.data).then(function(choice) {
                            if (choice) {
                                // Admin made a choice - set hidden field and submit
                                setCapacityChoice(choice);
                                capacityCheckPassed = true;
                                $('#entry_form').submit();
                            } else {
                                // Admin cancelled - restore button
                                $submitBtn.val(originalBtnText).prop('disabled', false);
                            }
                        });
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
