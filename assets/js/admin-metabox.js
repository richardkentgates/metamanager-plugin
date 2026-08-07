/**
 * admin-metabox.js — post SEO metabox behaviour.
 *
 * Features:
 *   1. Collapsible sections (▶ buttons)
 *   2. Schema type → field panel expansion
 *
 * Expects mmMetabox.sep and mmMetabox.sitetitle to be localised by PHP.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// ── 1. Collapsible sections ───────────────────────────────────────
		$(document).on('click', '.gcm-toggle-section', function () {
			var $btn    = $(this);
			var $target = $('#' + $btn.data('target'));
			if (!$target.length) return;
			var open = $target.is(':visible');
			$target.slideToggle(150);
			$btn.text($btn.text().replace(open ? '▼' : '▶', open ? '▶' : '▼'));
		});

		// ── 2. Schema type → field panel expansion ────────────────────────
		var $schemaTypeSelect = $('#mm_meta_schema_type');
		var $schemaPanels     = $('.gcm-schema-fields-panel');

		function updateSchemaPanel() {
			var chosen = $schemaTypeSelect.val();
			$schemaPanels.hide();
			if (chosen) {
				$schemaPanels.filter('[data-schema-type="' + chosen + '"]').show();
			}
		}

		$schemaTypeSelect.on('change', updateSchemaPanel);
		// Sync on initial load so panel matches a pre-selected type after JS runs.
		updateSchemaPanel();
	});
}(jQuery));
