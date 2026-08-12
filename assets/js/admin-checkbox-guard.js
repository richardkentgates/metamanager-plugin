/**
 * admin-checkbox-guard.js — Ensure unchecked checkboxes always submit a value.
 *
 * HTML checkboxes that are unchecked are NOT included in POST data.
 * When deep_sanitize_section processes the submitted data, it defaults
 * missing boolean keys to false — overwriting previously saved true values.
 *
 * Fix: On form submit, inject a hidden input (name=X, value="0") before each
 * unchecked checkbox. When the checkbox IS checked, PHP takes the last value
 * (the checkbox's "1") over the hidden input's "0".
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var forms = document.querySelectorAll('form[action="options.php"]');
		forms.forEach(function (form) {
			form.addEventListener('submit', function () {
				var checkboxes = form.querySelectorAll('input[type="checkbox"]');
				checkboxes.forEach(function (cb) {
					if (!cb.checked) {
						// Only add if a hidden guard doesn't already exist.
						if (cb.previousElementSibling &&
							cb.previousElementSibling.type === 'hidden' &&
							cb.previousElementSibling.name === cb.name) {
							return;
						}
						var guard = document.createElement('input');
						guard.type = 'hidden';
						guard.name = cb.name;
						guard.value = '0';
						cb.parentNode.insertBefore(guard, cb);
					}
				});
			});
		});
	});
}());
