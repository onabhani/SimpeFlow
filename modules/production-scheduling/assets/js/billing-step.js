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
    var preservedProdStartDate = null; // Store original production start date
    var preservedProdEndDate = null; // Store original production end date
    var currentInstallDateBeforeAjax = null; // Capture date before AJAX for each call
    var hasUserChangedDate = false; // Track if user manually changed the date

    // Check if we should initialize on this page
    function shouldInitialize() {
        // Check if config is available
        if (!config.formId || !config.installFieldId) {
            return false;
        }

        // Check if we're on a Gravity Forms page
        if (typeof gform === 'undefined' && typeof window.gf_submitting === 'undefined') {
            return false;
        }

        // Check if the form exists on the page
        var formSelector = '#gform_' + config.formId + ', #gform_wrapper_' + config.formId;
        if ($(formSelector).length === 0) {
            return false;
        }

        return true;
    }

    // Initialize on DOM ready
    $(document).ready(function() {
        if (!shouldInitialize()) {
            return;
        }

        initializeProductionScheduling();
    });

    // Re-initialize on Gravity Forms render (for AJAX/multi-page forms and workflow navigation)
    $(document).on('gform_post_render', function(event, form_id) {
        if (form_id != config.formId) {
            return;
        }

        if (!shouldInitialize()) {
            return;
        }

        console.log('SFA Production: Form re-rendered, re-initializing (form_id:', form_id, ')');

        // Clear existing state
        $productionFields = [];
        clearTimeout(previewTimeout);

        // Re-initialize (this will re-detect edit mode)
        initializeProductionScheduling();
    });

    function initializeProductionScheduling() {
        // Check for multi-field configuration or legacy single field
        var hasProductionFields = config.productionFields && config.productionFields.length > 0;
        var hasLegacyField = config.lmFieldId;

        if (!hasProductionFields && !hasLegacyField) {
            return; // No production fields configured
        }

        // Find installation field (required)
        $installField = $('#input_' + config.formId + '_' + config.installFieldId);
        if (!$installField.length) {
            return; // Installation field not found
        }

        // Find optional production date fields
        if (config.prodStartFieldId) {
            $prodStartField = $('#input_' + config.formId + '_' + config.prodStartFieldId);
        }
        if (config.prodEndFieldId) {
            $prodEndField = $('#input_' + config.formId + '_' + config.prodEndFieldId);
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

        // Re-detect edit mode EVERY time we initialize (important for workflow navigation)
        var initialInstallDate = $installField.val();
        if (initialInstallDate && initialInstallDate.trim() !== '') {
            isEditMode = true;
            preservedInstallDate = initialInstallDate;
            console.log('SFA Production: Edit mode detected, preserving install date:', preservedInstallDate);
        } else {
            isEditMode = false;
            preservedInstallDate = null;
        }

        // Preserve production dates if in edit mode
        if (isEditMode) {
            if ($prodStartField && $prodStartField.length) {
                var initialProdStart = $prodStartField.val();
                if (initialProdStart && initialProdStart.trim() !== '') {
                    preservedProdStartDate = initialProdStart;
                    console.log('SFA Production: Preserving prod start date:', preservedProdStartDate);
                }
            }
            if ($prodEndField && $prodEndField.length) {
                var initialProdEnd = $prodEndField.val();
                if (initialProdEnd && initialProdEnd.trim() !== '') {
                    preservedProdEndDate = initialProdEnd;
                    console.log('SFA Production: Preserving prod end date:', preservedProdEndDate);
                }
            }
        }

        // Reset user change tracking
        hasUserChangedDate = false;

        // Track manual date changes by user
        $installField.off('change.sfaProd').on('change.sfaProd', function() {
            var currentValue = $(this).val();
            if (currentValue && currentValue !== preservedInstallDate) {
                hasUserChangedDate = true;
                console.log('SFA Production: User manually changed date to:', currentValue);
            }
        });

        // Setup preview container and event handlers
        setupPreviewContainer();
    }

    function setupPreviewContainer() {
        // Check if preview container already exists
        var $existingPreview = $('#sfa-prod-preview-' + config.formId);
        if ($existingPreview.length > 0) {
            console.log('SFA Production: Preview container already exists, skipping creation');
            return;
        }

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
        currentInstallDateBeforeAjax = $installField.val();

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
        if (isEditMode && preservedInstallDate && !hasUserChangedDate) {
            // EDIT MODE: Restore the preserved date (ignore AJAX-calculated date)
            console.log('SFA Production: Edit mode - restoring preserved date:', preservedInstallDate);
            $installField.val(preservedInstallDate);
        } else if (hasUserChangedDate) {
            // User manually changed the date - respect their choice
            console.log('SFA Production: User changed date - preserving user choice:', currentInstallDateBeforeAjax);
            $installField.val(currentInstallDateBeforeAjax);
        } else {
            // NEW ENTRY MODE: Set to calculated minimum
            var installDateFormatted = formatDateDisplay(schedule.installation_minimum);
            $installField.val(installDateFormatted);
            console.log('SFA Production: New entry - setting date to calculated minimum:', installDateFormatted);
        }

        // Handle production date fields
        if ($prodStartField && $prodStartField.length) {
            if (isEditMode && preservedProdStartDate) {
                // Edit mode: preserve original date
                $prodStartField.val(preservedProdStartDate);
                console.log('SFA Production: Preserving prod start date:', preservedProdStartDate);
            } else if (!isEditMode || !$prodStartField.val()) {
                // New entry or empty: set to calculated date
                $prodStartField.val(formatDateDisplay(schedule.production_start));
            }
        }
        if ($prodEndField && $prodEndField.length) {
            if (isEditMode && preservedProdEndDate) {
                // Edit mode: preserve original date
                $prodEndField.val(preservedProdEndDate);
                console.log('SFA Production: Preserving prod end date:', preservedProdEndDate);
            } else if (!isEditMode || !$prodEndField.val()) {
                // New entry or empty: set to calculated date
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

})(jQuery);
