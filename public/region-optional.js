/*
 * Ezyweb Addons for Vvveb
 *
 * Copyright (C) 2026  Ezyweb.uk
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * ezyweb-addons: Region only required when the country has regions (switch region_optional).
 *
 * Watches every checkout region dropdown (select[data-v-regions]). When the selected country has
 * no regions (the list holds no option with a value), the Region field (its column) is hidden and
 * no longer required; when it has regions, the field shows and is required again. The server side
 * of the plugin posts region 0 ("no region") for such a country, so core validation passes.
 * Works with any theme; core checkout.js rebuilds the list on a country change, which the
 * MutationObserver picks up.
 */
(function () {
	'use strict';

	function hasRegions(select) {
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].value !== '') return true;
		}
		return false;
	}

	function field(select) {
		return select.closest('[class*="col-"]') || select.parentElement;
	}

	function update(select) {
		var has = hasRegions(select);
		var box = field(select);

		if (has) {
			if (select.dataset.ezyRequired) select.required = true;
			if (box) box.hidden = false;
		} else {
			select.required = false;
			select.value = '';
			if (box) box.hidden = true;
		}
	}

	function init() {
		document.querySelectorAll('select[data-v-regions]').forEach(function (select) {
			if (select.required) select.dataset.ezyRequired = '1';
			update(select);
			new MutationObserver(function () { update(select); }).observe(select, { childList: true });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
