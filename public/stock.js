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
 * Ezyweb Addons: out-of-stock and disabled products.
 * When the server refuses an add to cart, its response contains
 * <template id="ezy-stock-blocked" data-message="...">. Show that message
 * instead of Vvveb's "was added to cart" popup.
 */
(function () {
	"use strict";

	function blockedMessage(data) {
		if (typeof data !== "string" || data.indexOf("ezy-stock-blocked") === -1) {
			return null;
		}

		var doc = new DOMParser().parseFromString(data, "text/html");
		var marker = doc.getElementById("ezy-stock-blocked");

		return marker ? (marker.getAttribute("data-message") || "") : null;
	}

	function showMessage(message) {
		var text = document.createElement("div");
		text.className = "text-danger";
		text.textContent = message;

		if (window.VvvebTheme && VvvebTheme.Alert && typeof VvvebTheme.Alert.show === "function") {
			VvvebTheme.Alert.show(text.outerHTML);
		} else {
			alert(message);
		}
	}

	function wrapCartAdd() {
		if (!window.VvvebTheme || !VvvebTheme.Cart || typeof VvvebTheme.Cart.add !== "function" || VvvebTheme.Cart.ezyStockWrapped) {
			return;
		}

		var originalAdd = VvvebTheme.Cart.add;

		VvvebTheme.Cart.add = function (productId, options, element, selector, callback) {
			return originalAdd.call(this, productId, options, element, selector, function (data) {
				var message = blockedMessage(data);

				if (message !== null) {
					showMessage(message || "This product can't be added to the cart.");
					return;
				}

				if (typeof callback === "function") {
					return callback.apply(this, arguments);
				}
			});
		};

		VvvebTheme.Cart.ezyStockWrapped = true;
	}

	wrapCartAdd();
	document.addEventListener("DOMContentLoaded", wrapCartAdd);
})();
