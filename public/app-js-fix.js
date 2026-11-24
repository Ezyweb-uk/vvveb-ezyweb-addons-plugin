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
 * Ezyweb Addons: fixes for Vvveb 1.0.9.0 public/js/app.js, loaded after app.js.
 *
 * 1. VvvebApp.path/publicPath end with "/" when app.js is bundled by the minify plugin
 *    (served from /assets-cache/), so the add to cart popup links become https://domain//cart.
 * 2. navigateTo(url) uses an `element` variable it is never given, so every ajax link click
 *    throws "element is not defined"; and the click listener cancels the click even when the
 *    url can't be loaded with ajax (eg. the //cart links), so those links do nothing.
 * 3. app.js registers the same click listener twice, so navigateTo runs twice per click.
 */
(function () {
	"use strict";

	var app = window.VvvebApp;

	if (!app || window.ezywebAppJsFix) return;
	window.ezywebAppJsFix = true;

	["path", "publicPath"].forEach(function (key) {
		if (typeof app[key] === "string") app[key] = app[key].replace(/\/+$/, "");
	});

	if (typeof window.navigateTo !== "function" || !window.VvvebTheme || !VvvebTheme.ajax) return;

	var lastEvent = null;

	//ajax load an internal url, returns false when it can't be loaded with ajax
	function ajaxNavigate(url, element) {
		var hostRegex = new RegExp("^.*//" + window.location.host.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
		url = (url || "").replace(hostRegex, "");

		if (!url || url.indexOf("//") !== -1 || (element && element.target)
			|| (VvvebTheme.ajax.skipUrl.length && (VvvebTheme.ajax.skipUrl.includes(url) || VvvebTheme.ajax.skipUrl.includes(window.location.pathname)))
		) return false;

		var selector = (element && element.dataset.selector) || VvvebTheme.ajax.siteContainer;

		loadAjax(url, selector, function (response) {
			var title = response.title;
			document.title = title;
			var target   = document.querySelector(VvvebTheme.ajax.scrollContainer);
			var scrollTo = (element && element.dataset.scroll) || "start";
			if (target) target.scrollIntoView({behavior: "smooth", block: scrollTo, inline: scrollTo});
			window.history.pushState({url: url, selector: selector, title: title}, null, url);
		});

		return true;
	}

	window.navigateTo = function (url, element) {
		var e = window.event;

		//app.js has two identical click listeners, only handle each click once
		if (e && e === lastEvent) return;
		lastEvent = e || null;

		if (!element && e && e.target && e.target.closest) {
			element = e.target.closest(VvvebTheme.ajax.selector);
		}

		var newTab = e && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1);

		if (!newTab && ajaxNavigate(url, element)) return;

		//app.js cancels the click after calling navigateTo, so follow the link here
		var href = element ? element.href : url;

		if (!href) return;

		if (newTab || (element && element.target && element.target !== "_self")) {
			window.open(href, newTab ? "_blank" : element.target);
		} else {
			window.location.href = href;
		}
	};
})();
