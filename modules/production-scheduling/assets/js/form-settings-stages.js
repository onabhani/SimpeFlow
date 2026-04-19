/* global jQuery, sfaProdStagesI18n */
/**
 * Production Scheduling — Workflow Stages form settings UI.
 *
 * Handles:
 *  - WP color picker init
 *  - Add / remove stage rows
 *  - Live exclusivity: disable a step checkbox in all other rows once it's
 *    checked in any row, re-enable it when unchecked.
 *
 * Row identity uses the hidden `.sfa-prod-stage-id` input (a `stg_xxxxxx`
 * token). Stage name is used ONLY for the visible "(used by: …)" label;
 * ownership decisions never compare names, so two unnamed stages don't
 * collide.
 *
 * Localized strings arrive via wp_localize_script as `sfaProdStagesI18n`.
 */
(function ($) {
	'use strict';

	var i18n = (typeof sfaProdStagesI18n !== 'undefined' && sfaProdStagesI18n) || {};
	var UNNAMED_LABEL = i18n.unnamed || '(unnamed)';
	var USED_BY_PATTERN = i18n.usedBy || '(used by: %s)';

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

	function htmlEscape(text) {
		return $('<div>').text(text).html();
	}

	function formatUsedBy(name) {
		// Simple %s substitution on the localized pattern. Name is html-escaped
		// by the caller before going into innerHTML.
		return USED_BY_PATTERN.replace('%s', name);
	}

	function rowIdentity($row) {
		var id = ($row.find('.sfa-prod-stage-id').val() || '').trim();
		if (id) {
			return id;
		}
		// Fallback — should never happen after addStageRow(), but guards against
		// server-rendered rows whose hidden input is somehow empty.
		return 'row:' + $row.index();
	}

	function rowDisplayName($row) {
		var name = ($row.find('input[name$="[name]"]').val() || '').trim();
		return name || UNNAMED_LABEL;
	}

	function collectOwnedSteps() {
		// Map of step_id -> { ownerId, ownerName } based on current checked boxes.
		// First-checked-wins per step.
		var owners = {};
		$list.find('.sfa-prod-stage-row').each(function () {
			var $row   = $(this);
			var ownerId   = rowIdentity($row);
			var ownerName = rowDisplayName($row);
			$row.find('.sfa-prod-stage-step-checkbox:checked').each(function () {
				var sid = $(this).data('step-id');
				if (!Object.prototype.hasOwnProperty.call(owners, sid)) {
					owners[sid] = { id: ownerId, name: ownerName };
				}
			});
		});
		return owners;
	}

	function refreshExclusivity() {
		var owners = collectOwnedSteps();

		$list.find('.sfa-prod-stage-row').each(function () {
			var $row        = $(this);
			var currentId   = rowIdentity($row);
			$row.find('.sfa-prod-stage-step-checkbox').each(function () {
				var $cb     = $(this);
				var sid     = $cb.data('step-id');
				var owner   = owners[sid];
				var checked = $cb.is(':checked');
				var isOwnedElsewhere = !checked && owner && owner.id !== currentId;
				$cb.prop('disabled', isOwnedElsewhere);
				var $label = $cb.closest('label');
				$label.find('em.sfa-prod-step-owner').remove();
				if (isOwnedElsewhere) {
					$label.css('color', '#999').append(
						' <em class="sfa-prod-step-owner" style="font-size:11px;color:#999;">' +
						htmlEscape(formatUsedBy(owner.name)) +
						'</em>'
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

		$list.on('click', '.sfa-prod-remove-stage', function (e) {
			e.preventDefault();
			removeStageRow($(this).closest('.sfa-prod-stage-row'));
		});

		$list.on('change', '.sfa-prod-stage-step-checkbox', function () {
			refreshExclusivity();
		});

		$list.on('input', 'input[name$="[name]"]', function () {
			refreshExclusivity();
		});
	});
})(jQuery);
