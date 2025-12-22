/**
 * Production Scheduling - Billing Step Integration
 * Real-time schedule preview during form filling
 */
(function($) {
    'use strict';

    var config = window.sfaProdConfig || {};
    var $lmField, $installField, $prodStartField, $prodEndField;
    var previewTimeout;

    // Wait for DOM ready
    $(document).ready(function() {
        if (!config.lmFieldId || !config.installFieldId) {
            return; // Not configured
        }

        // Find the required fields
        $lmField = $('#input_' + config.formId + '_' + config.lmFieldId);
        $installField = $('#input_' + config.formId + '_' + config.installFieldId);

        if (!$lmField.length || !$installField.length) {
            return; // Fields not found
        }

        // Find optional production date fields
        if (config.prodStartFieldId) {
            $prodStartField = $('#input_' + config.formId + '_' + config.prodStartFieldId);
        }
        if (config.prodEndFieldId) {
            $prodEndField = $('#input_' + config.formId + '_' + config.prodEndFieldId);
        }

        // Initialize
        initializeProductionScheduling();
    });

    function initializeProductionScheduling() {
        // Create preview container and insert it right after the LM field container
        var $previewContainer = $('<div/>', {
            'class': 'sfa-prod-preview',
            'id': 'sfa-prod-preview-' + config.formId,
            'style': 'margin: 15px 0; padding: 15px; background: #f0f9ff; border-left: 4px solid #0073aa;'
        });

        // Insert after the LM field's ginput_container
        var $lmContainer = $lmField.closest('.ginput_container');
        if ($lmContainer.length) {
            $previewContainer.insertAfter($lmContainer);
        } else {
            // Fallback: insert after the field itself
            $previewContainer.insertAfter($lmField);
        }

        // Attach change handler to LM field
        $lmField.on('input change', function() {
            clearTimeout(previewTimeout);
            previewTimeout = setTimeout(function() {
                updateSchedulePreview();
            }, 500); // Debounce 500ms
        });

        // Initial calculation if LM field has value
        if ($lmField.val()) {
            updateSchedulePreview();
        }
    }

    function updateSchedulePreview() {
        var lmValue = parseInt($lmField.val(), 10);

        if (!lmValue || lmValue <= 0) {
            clearPreview();
            return;
        }

        showLoading();

        $.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'sfa_prod_preview_schedule',
                nonce: config.nonce,
                lm_required: lmValue
            },
            success: function(response) {
                if (response.success) {
                    displaySchedule(response.data);
                } else {
                    displayError(response.data.message || 'Unable to calculate schedule');
                }
            },
            error: function() {
                displayError('Network error. Please try again.');
            }
        });
    }

    function showLoading() {
        var $container = $('#sfa-prod-preview-' + config.formId);
        $container.html('<div class="sfa-prod-loading">⏳ Calculating production schedule...</div>');
        $container.show();
    }

    function clearPreview() {
        var $container = $('#sfa-prod-preview-' + config.formId);
        $container.html('').hide();

        // Clear installation date field minimum
        $installField.removeAttr('min');
    }

    function displaySchedule(schedule) {
        var $container = $('#sfa-prod-preview-' + config.formId);

        var html = '<div class="sfa-prod-schedule-info">';
        html += '<h4 style="margin: 10px 0;">📅 Production Schedule</h4>';
        html += '<table class="sfa-prod-schedule-table" style="width: 100%; border-collapse: collapse;">';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Production Start:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + formatDate(schedule.production_start) + '</td>';
        html += '</tr>';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Production Complete:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + formatDate(schedule.production_end) + '</td>';
        html += '</tr>';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Total Days:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + schedule.total_days + ' day' + (schedule.total_days > 1 ? 's' : '') + '</td>';
        html += '</tr>';
        html += '<tr style="background: #f0f9ff;">';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Earliest Installation:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + formatDate(schedule.installation_minimum) + '</td>';
        html += '</tr>';
        html += '</table>';
        html += '<p style="margin: 10px 0; font-size: 13px; color: #666;">✓ Based on current factory capacity and bookings</p>';
        html += '</div>';

        $container.html(html).show();

        // Set installation date field minimum and value
        $installField.attr('min', schedule.installation_minimum);

        // Auto-fill installation date if empty or less than minimum
        var currentValue = $installField.val();
        if (!currentValue || currentValue < schedule.installation_minimum) {
            $installField.val(schedule.installation_minimum);
        }

        // Populate production date fields if they exist
        if ($prodStartField && $prodStartField.length) {
            $prodStartField.val(schedule.production_start);
        }
        if ($prodEndField && $prodEndField.length) {
            $prodEndField.val(schedule.production_end);
        }
    }

    function displayError(message) {
        var $container = $('#sfa-prod-preview-' + config.formId);
        var html = '<div class="sfa-prod-error" style="background: #fee; padding: 10px; border-left: 3px solid #c00; margin: 10px 0;">';
        html += '❌ ' + message;
        html += '</div>';
        $container.html(html).show();
    }

    function formatDate(dateStr) {
        var date = new Date(dateStr + 'T00:00:00');
        var options = { year: 'numeric', month: 'long', day: 'numeric' };
        return date.toLocaleDateString(undefined, options);
    }

})(jQuery);
