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
    var isEditMode = false; // Track if we're editing an existing entry
    var preservedInstallDate = null; // Store the original date to preserve

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

        // Check if we're in edit mode by checking if installation field has a value
        // This happens when GravityFlow loads an existing entry
        var initialInstallDate = $installField.val();
        if (initialInstallDate && initialInstallDate.trim() !== '') {
            isEditMode = true;
            preservedInstallDate = initialInstallDate;
            console.log('SFA Production: Edit mode detected, preserving date:', preservedInstallDate);
        }

        // Initialize
        initializeProductionScheduling();
    });

    function initializeProductionScheduling() {
        // Create preview container with full width styling to break out of column constraints
        var $previewContainer = $('<div/>', {
            'class': 'sfa-prod-preview gfield gfield--width-full',
            'id': 'sfa-prod-preview-' + config.formId,
            'style': 'margin: 15px 0 !important; padding: 15px; background: #f0f9ff; border-left: 4px solid #0073aa; width: 100% !important; max-width: 100% !important; display: block; box-sizing: border-box; clear: both; grid-column: 1 / -1; flex-basis: 100%; position: relative; float: none;'
        });

        // Create a clearing div to reset layout flow after the preview
        var $clearDiv = $('<div/>', {
            'class': 'sfa-prod-clear',
            'style': 'clear: both; display: block; height: 0; overflow: hidden; grid-column: 1 / -1; flex-basis: 100%; width: 100%;'
        });

        // Insert preview container after the last production field
        if ($productionFields.length > 0) {
            // Multi-field mode: insert after LAST field's parent .gfield wrapper
            var $lastField = $productionFields[$productionFields.length - 1].element;
            var $gfieldWrapper = $lastField.closest('.gfield');
            if ($gfieldWrapper.length) {
                $previewContainer.insertAfter($gfieldWrapper);
                $clearDiv.insertAfter($previewContainer);
            } else {
                $previewContainer.insertAfter($lastField);
                $clearDiv.insertAfter($previewContainer);
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
            // Legacy mode: insert after LM field's parent .gfield wrapper
            var $gfieldWrapper = $lmField.closest('.gfield');
            if ($gfieldWrapper.length) {
                $previewContainer.insertAfter($gfieldWrapper);
                $clearDiv.insertAfter($previewContainer);
            } else {
                $previewContainer.insertAfter($lmField);
                $clearDiv.insertAfter($previewContainer);
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

        // Capture current installation date BEFORE making AJAX call
        // This ensures we preserve it even if AJAX changes field order
        var currentInstallDateBeforeAjax = $installField.val();

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

        var html = '<div class="sfa-prod-schedule-info" style="width: 100%; display: block;">';
        html += '<h4 style="margin: 10px 0;">📅 Production Schedule</h4>';
        html += '<table class="sfa-prod-schedule-table" style="width: 100% !important; min-width: 100%; max-width: 100%; border-collapse: collapse; table-layout: fixed; display: table;">';
        html += '<tr>';
        html += '<td style="padding: 8px; border: 1px solid #ddd; width: 50%;"><strong>Production Start:</strong></td>';
        html += '<td style="padding: 8px; border: 1px solid #ddd; width: 50%;">' + formatDateDisplay(schedule.production_start) + '</td>';
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

        // Set installation date field minimum
        $installField.attr('min', schedule.installation_minimum);

        // Handle date field population based on mode
        // Check if user manually changed the date (different from what we captured before AJAX)
        var userChangedDate = currentInstallDateBeforeAjax &&
                             currentInstallDateBeforeAjax !== preservedInstallDate &&
                             currentInstallDateBeforeAjax !== '';

        if (isEditMode && preservedInstallDate && !userChangedDate) {
            // EDIT MODE: Restore the original preserved date
            // This prevents JavaScript from overwriting the backend-preserved date
            console.log('SFA Production: Restoring preserved date:', preservedInstallDate, '(was', currentInstallDateBeforeAjax, ')');
            $installField.val(preservedInstallDate);
        } else if (userChangedDate) {
            // User manually changed the date - respect their choice
            console.log('SFA Production: User changed date to:', currentInstallDateBeforeAjax, '- preserving user choice');
            $installField.val(currentInstallDateBeforeAjax);
        } else {
            // NEW ENTRY MODE: Set to calculated minimum
            var installDateFormatted = formatDateDisplay(schedule.installation_minimum);
            $installField.val(installDateFormatted);
            console.log('SFA Production: New entry, setting date to:', installDateFormatted);
        }

        // Only populate production date fields if they're empty (or in edit mode, preserve them)
        if ($prodStartField && $prodStartField.length) {
            if (!isEditMode) {
                $prodStartField.val(formatDateDisplay(schedule.production_start));
            }
        }
        if ($prodEndField && $prodEndField.length) {
            if (!isEditMode) {
                $prodEndField.val(formatDateDisplay(schedule.production_end));
            }
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
