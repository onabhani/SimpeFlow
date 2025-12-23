/**
 * Production Scheduling - Billing Step Integration
 * Real-time schedule preview during form filling
 */
(function($) {
    'use strict';

    var config = window.sfaProdConfig || {};
    var $lmField, $installField, $prodStartField, $prodEndField;
    var $productionFields = []; // Array of production field elements
    var previewTimeout;

    // Wait for DOM ready
    $(document).ready(function() {
        // Check if we have installation field (required)
        if (!config.installFieldId) {
            return; // Not configured
        }

        $installField = $('#input_' + config.formId + '_' + config.installFieldId);
        if (!$installField.length) {
            return; // Installation field not found
        }

        // Check for multi-field configuration or legacy single field
        var hasProductionFields = config.productionFields && config.productionFields.length > 0;
        var hasLegacyField = config.lmFieldId;

        if (!hasProductionFields && !hasLegacyField) {
            return; // No production fields configured
        }

        // Find production fields
        if (hasProductionFields) {
            // Multi-field mode
            config.productionFields.forEach(function(fieldConfig) {
                var $field = $('#input_' + config.formId + '_' + fieldConfig.field_id);
                if ($field.length) {
                    $productionFields.push({
                        element: $field,
                        fieldId: fieldConfig.field_id,
                        fieldType: fieldConfig.field_type
                    });
                }
            });

            if ($productionFields.length === 0) {
                return; // No production fields found in DOM
            }
        } else {
            // Legacy mode (single LM field)
            $lmField = $('#input_' + config.formId + '_' + config.lmFieldId);
            if (!$lmField.length) {
                return; // LM field not found
            }
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
        // Create preview container
        var $previewContainer = $('<div/>', {
            'class': 'sfa-prod-preview',
            'id': 'sfa-prod-preview-' + config.formId,
            'style': 'margin: 15px 0; padding: 15px; background: #f0f9ff; border-left: 4px solid #0073aa;'
        });

        // Insert preview container after the first production field
        if ($productionFields.length > 0) {
            // Multi-field mode: insert after first field
            var $firstField = $productionFields[0].element;
            var $firstContainer = $firstField.closest('.ginput_container');
            if ($firstContainer.length) {
                $previewContainer.insertAfter($firstContainer);
            } else {
                $previewContainer.insertAfter($firstField);
            }

            // Attach change handlers to all production fields
            $productionFields.forEach(function(fieldObj) {
                fieldObj.element.on('input change', function() {
                    clearTimeout(previewTimeout);
                    previewTimeout = setTimeout(function() {
                        updateSchedulePreview();
                    }, 500); // Debounce 500ms
                });
            });

            // Initial calculation if any field has value
            var hasValue = $productionFields.some(function(fieldObj) {
                return fieldObj.element.val() && parseFloat(fieldObj.element.val()) > 0;
            });
            if (hasValue) {
                updateSchedulePreview();
            }
        } else {
            // Legacy mode: insert after LM field
            var $lmContainer = $lmField.closest('.ginput_container');
            if ($lmContainer.length) {
                $previewContainer.insertAfter($lmContainer);
            } else {
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
    }

    function updateSchedulePreview() {
        var ajaxData = {
            action: 'sfa_prod_preview_schedule',
            nonce: config.nonce
        };

        if ($productionFields.length > 0) {
            // Multi-field mode
            var fieldValues = {};
            var hasAnyValue = false;

            $productionFields.forEach(function(fieldObj) {
                var value = parseFloat(fieldObj.element.val()) || 0;
                fieldValues[fieldObj.fieldId] = value;
                if (value > 0) {
                    hasAnyValue = true;
                }
            });

            if (!hasAnyValue) {
                clearPreview();
                return;
            }

            ajaxData.field_values = fieldValues;
            ajaxData.field_configs = config.productionFields;
        } else {
            // Legacy mode (single LM field)
            var lmValue = parseInt($lmField.val(), 10);

            if (!lmValue || lmValue <= 0) {
                clearPreview();
                return;
            }

            ajaxData.lm_required = lmValue;
        }

        showLoading();

        $.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: ajaxData,
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
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + formatDateDisplay(schedule.production_start) + '</td>';
        html += '</tr>';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Production Complete:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + formatDateDisplay(schedule.production_end) + '</td>';
        html += '</tr>';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>Total Days:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd;">' + schedule.total_days + ' day' + (schedule.total_days > 1 ? 's' : '') + '</td>';
        html += '</tr>';
        html += '<tr style="background: #d4edda; border-left: 4px solid #28a745;">';
        html += '<td style="padding: 10px; border: 1px solid #c3e6cb; font-weight: bold; font-size: 14px;">📦 Earliest Installation:</td>';
        html += '<td style="padding: 10px; border: 1px solid #c3e6cb; font-weight: bold; font-size: 14px; color: #155724;">' + formatDateDisplay(schedule.installation_minimum) + '</td>';
        html += '</tr>';
        html += '</table>';
        html += '<p style="margin: 10px 0; font-size: 13px; color: #666;">✓ Based on current factory capacity and bookings</p>';
        html += '</div>';

        $container.html(html).show();

        // Set installation date field minimum and value
        // Note: GF date fields may be configured for dd/mm/yyyy format
        $installField.attr('min', schedule.installation_minimum);

        // Always update installation date to the new calculated minimum
        // This ensures the field reflects the current LM value's production schedule
        var installDateFormatted = formatDateDisplay(schedule.installation_minimum);
        $installField.val(installDateFormatted);

        // Populate production date fields if they exist
        // Use DD/MM/YYYY format for better readability
        if ($prodStartField && $prodStartField.length) {
            $prodStartField.val(formatDateDisplay(schedule.production_start));
        }
        if ($prodEndField && $prodEndField.length) {
            $prodEndField.val(formatDateDisplay(schedule.production_end));
        }
    }

    function displayError(message) {
        var $container = $('#sfa-prod-preview-' + config.formId);
        var html = '<div class="sfa-prod-error" style="background: #fee; padding: 10px; border-left: 3px solid #c00; margin: 10px 0;">';
        html += '❌ ' + message;
        html += '</div>';
        $container.html(html).show();
    }

    function formatDateDisplay(dateStr) {
        // Format as DD/MM/YYYY
        var parts = dateStr.split('-');
        if (parts.length === 3) {
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        return dateStr;
    }

    function normalizeDateToISO(dateStr) {
        // Convert DD/MM/YYYY to YYYY-MM-DD for comparison
        if (!dateStr) return '';

        // Check if already in YYYY-MM-DD format
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
            return dateStr;
        }

        // Check if in DD/MM/YYYY format
        var match = dateStr.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (match) {
            return match[3] + '-' + match[2] + '-' + match[1];
        }

        return '';
    }

    function formatDate(dateStr) {
        // Legacy function - kept for compatibility
        var date = new Date(dateStr + 'T00:00:00');
        var options = { year: 'numeric', month: 'long', day: 'numeric' };
        return date.toLocaleDateString(undefined, options);
    }

})(jQuery);
