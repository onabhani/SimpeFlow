/* Designer Workload: client-side form filter and card-to-list jump. No dependencies. */
(function () {
	'use strict';

	function applyFilter(root, form) {
		root.querySelectorAll('.sfa-dw-chip').forEach(function (chip) {
			chip.setAttribute('aria-pressed', String(chip.getAttribute('data-form') === form));
		});

		var total = 0;
		var counts = {};

		root.querySelectorAll('.sfa-dw-designer').forEach(function (section) {
			var visible = 0;
			section.querySelectorAll('tr[data-form]').forEach(function (row) {
				var show = form === 'all' || row.getAttribute('data-form') === form;
				row.hidden = !show;
				if (show) {
					visible++;
				}
			});
			var none = section.querySelector('.sfa-dw-none');
			if (none) {
				none.hidden = visible > 0;
			}
			var pill = section.querySelector('[data-count]');
			if (pill) {
				pill.textContent = String(visible);
			}
			counts[section.getAttribute('data-designer')] = visible;
			total += visible;
		});

		root.querySelectorAll('.sfa-dw-card').forEach(function (card) {
			var n = counts[card.getAttribute('data-designer')] || 0;
			var num = card.querySelector('[data-count]');
			if (num) {
				num.textContent = String(n);
			}
			var bar = card.querySelector('.sfa-dw-bar span');
			if (bar) {
				bar.style.width = (total ? Math.round(n / total * 100) : 0) + '%';
			}
		});
	}

	function init(root) {
		root.querySelectorAll('.sfa-dw-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				applyFilter(root, chip.getAttribute('data-form'));
			});
		});

		root.querySelectorAll('.sfa-dw-card').forEach(function (card) {
			card.addEventListener('click', function () {
				var section = root.querySelector('.sfa-dw-designer[data-designer="' + card.getAttribute('data-designer') + '"]');
				if (!section) {
					return;
				}
				section.open = true;
				var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				section.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
			});
		});
	}

	function boot() {
		document.querySelectorAll('.sfa-dw').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
