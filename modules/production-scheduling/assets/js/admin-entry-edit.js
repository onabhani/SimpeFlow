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
     * Read the current values of production fields from the DOM.
     * Returns an object like { "12": "3.5", "14": "6" } keyed by field ID,
     * or null if no production fields are configured.
     */
    function getCurrentFieldValues() {
        var fields = sfaProdAdmin.productionFields;
        if (!fields || !fields.length) {
            // Legacy mode: try single LM field
            if (sfaProdAdmin.lmFieldId) {
                var $lm = $('input[name="input_' + sfaProdAdmin.lmFieldId + '"]');
                if ($lm.length) {
                    return { _legacy_lm: $lm.val() };
                }
            }
            return null;
        }

        var values = {};
        for (var i = 0; i < fields.length; i++) {
            var fid = fields[i].field_id;
            var $input = $('input[name="input_' + fid + '"]');
            if ($input.length) {
                values[fid] = $input.val();
            }
        }
        return values;
    }

    /**
     * Check if the new installation date causes overbooking
     */
    function checkCapacityBeforeSave(installDate, entryId) {
        var data = {
            action: 'sfa_prod_check_capacity_before_save',
            entry_id: entryId,
            install_date: installDate,
            nonce: sfaProdAdmin.nonce
        };

        // Include current production field values from the DOM
        var fieldValues = getCurrentFieldValues();
        if (fieldValues) {
            if (fieldValues._legacy_lm !== undefined) {
                data.current_lm = fieldValues._legacy_lm;
            } else {
                data.current_field_values = fieldValues;
            }
        }

        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data
        });
    }

    /**
     * Find the installation date field value using configured field ID
     */
    function getInstallationDate() {
        // Use configured field ID from PHP
        if (!sfaProdAdmin.formId || !sfaProdAdmin.installFieldId) {
            return null;
        }

        // Gravity Forms admin uses input_FIELDID format for entry edit
        let $field = $('input[name="input_' + sfaProdAdmin.installFieldId + '"]');

        if ($field.length) {
            return $field.val();
        }

        // Fallback: try alternative selector patterns
        $field = $('#input_' + sfaProdAdmin.formId + '_' + sfaProdAdmin.installFieldId);
        if ($field.length) {
            return $field.val();
        }

        return null;
    }

    /**
     * Get entry ID from config or URL
     */
    function getEntryId() {
        // Use configured entry ID from PHP
        if (sfaProdAdmin.entryId) {
            return sfaProdAdmin.entryId;
        }

        // Fallback: try URL parameter
        let urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('lid');
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
     * Store capacity choice via AJAX (more reliable than hidden field)
     */
    function setCapacityChoice(choice, entryId) {
        return $.ajax({
            url: sfaProdAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'sfa_prod_store_capacity_choice',
                entry_id: entryId,
                choice: choice,
                nonce: sfaProdAdmin.nonce
            }
        });
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

        // Show loading indicator - target only the first/main submit button
        let $submitBtn = $('#entry_form input[type="submit"]').first();
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
                                // Admin made a choice - store via AJAX then submit
                                $submitBtn.val('Saving choice...').prop('disabled', true);
                                setCapacityChoice(choice, entryId)
                                    .done(function() {
                                        capacityCheckPassed = true;
                                        $('#entry_form').submit();
                                    })
                                    .fail(function() {
                                        // If AJAX fails, try anyway
                                        capacityCheckPassed = true;
                                        $('#entry_form').submit();
                                    });
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
