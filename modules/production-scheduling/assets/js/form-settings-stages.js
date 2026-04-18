/* global jQuery */
/**
 * Production Scheduling — Workflow Stages form settings UI.
 *
 * Handles:
 *  - WP color picker init
 *  - Add / remove stage rows
 *  - Live exclusivity: disable a step checkbox in all other rows once it's
 *    checked in any row, re-enable it when unchecked.
 */
(function ($) {
	'use strict';

	var $list     = null;
	var $template = null;
	var nextIndex = 0;

	function initColorPicker($input) {
		if (!$input.hasClass('wp-color-picker')) {
			$input.wpColorPicker();
		}
	}

	function randomStageId() {
		var hex = '';
		var alphabet = '0123456789abcdef';
		for (var i = 0; i < 6; i++) {
			hex += alphabet.charAt(Math.floor(Math.random() * 16));
		}
		return 'stg_' + hex;
	}

	function collectOwnedSteps() {
		// Map of step_id -> owning stage name, based on current checked boxes.
		var owners = {};
		$list.find('.sfa-prod-stage-row').each(function () {
			var $row = $(this);
			var name = ($row.find('input[name$="[name]"]').val() || '').trim() || '(unnamed)';
			$row.find('.sfa-prod-stage-step-checkbox:checked').each(function () {
				var sid = $(this).data('step-id');
				if (!owners.hasOwnProperty(sid)) {
					owners[sid] = name;
				}
			});
		});
		return owners;
	}

	function refreshExclusivity() {
		var owners = collectOwnedSteps();

		$list.find('.sfa-prod-stage-row').each(function () {
			var $row     = $(this);
			var rowName  = ($row.find('input[name$="[name]"]').val() || '').trim() || '(unnamed)';
			$row.find('.sfa-prod-stage-step-checkbox').each(function () {
				var $cb     = $(this);
				var sid     = $cb.data('step-id');
				var owner   = owners[sid];
				var checked = $cb.is(':checked');
				var isOwnedElsewhere = !checked && owner && owner !== rowName;
				$cb.prop('disabled', isOwnedElsewhere);
				var $label = $cb.closest('label');
				$label.find('em.sfa-prod-step-owner').remove();
				if (isOwnedElsewhere) {
					$label.css('color', '#999').append(
						' <em class="sfa-prod-step-owner" style="font-size:11px;color:#999;">(used by: ' +
						$('<div>').text(owner).html() +
						')</em>'
					);
				} else {
					$label.css('color', '');
				}
			});
		});
	}

	function addStageRow() {
		if (!$template.length) {
			return;
		}
		var html = $template.html().replace(/__INDEX__/g, String(nextIndex));
		var $new = $(html);
		$new.find('.sfa-prod-stage-id').val(randomStageId());
		$list.append($new);
		initColorPicker($new.find('.sfa-prod-stage-color'));
		nextIndex += 1;
		refreshExclusivity();
	}

	function removeStageRow($row) {
		$row.remove();
		refreshExclusivity();
	}

	$(function () {
		$list     = $('#sfa-prod-stages-list');
		$template = $('#sfa-prod-stage-row-template');
		if (!$list.length || !$template.length) {
			return;
		}

		// Initial index starts past the last server-rendered row.
		nextIndex = $list.find('.sfa-prod-stage-row').length;

		// Init color pickers on server-rendered rows.
		$list.find('.sfa-prod-stage-color').each(function () {
			initColorPicker($(this));
		});

		$('#sfa-prod-add-stage').on('click', function (e) {
			e.preventDefault();
			addStageRow();
		});

		$(document).on('click', '.sfa-prod-remove-stage', function (e) {
			e.preventDefault();
			removeStageRow($(this).closest('.sfa-prod-stage-row'));
		});

		$(document).on('change', '.sfa-prod-stage-step-checkbox', function () {
			refreshExclusivity();
		});

		$(document).on('input', 'input[name$="[name]"]', function () {
			refreshExclusivity();
		});
	});
})(jQuery);
