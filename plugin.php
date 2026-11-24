<?php

/**
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
 *
 */

/*
Name: Ezyweb Addons
Slug: ezyweb-addons
Category: tools
Url: https://github.com/Ezyweb-uk/vvveb-ezyweb-addons-plugin
Description: Small admin and shop adjustments: SKU on admin order details, products per page in admin Products, products per page on shop listings, SKU in admin Products list, default language redirect, canonical tag, add to cart popup fix, blocking out-of-stock or disabled products from the cart, partial matches in the admin Products filters, product results in the shop search, shop search on part of a SKU or model, saving status, price and other fields of products and posts created by other admins, protecting admin site access and status, and alphabetical countries and regions at checkout.
Author: Ezyweb.uk
Author url: https://ezyweb.uk
Version: 0.20
Thumb: ezyweb-addons-e.svg
Settings: /admin/index.php?module=plugins/ezyweb-addons/settings
*/

use function Vvveb\getSetting;
use Vvveb\System\Core\FrontController;
use Vvveb\System\Core\Request;
use Vvveb\System\Cart\Cart as ShoppingCart;
use Vvveb\System\Core\View;
use Vvveb\System\Db;
use Vvveb\System\Event;
use Vvveb\System\Session;
use Vvveb\System\User\Admin;
use Vvveb\Sql\CategorySQL;
use Vvveb\System\Locale;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

class EzywebAddons {
	const NAMESPACE = 'ezyweb_addons';

	//feature defaults: everything off until enabled on the settings page
	const DEFAULTS = [
		'order_sku'              => 0, //show SKU under each product on admin order details
		'admin_products_limit'   => 0, //admin > Products > Products per page, 0 = Vvveb default (10)
		'shop_products_limit'    => 0, //shop listing pages per page, 0 = theme default (data-v-limit)
		'default_lang_redirect'  => 0, //301 /en/page (default language prefix) and unknown language prefixes to /page
		'canonical'              => 0, //fill the theme's empty <link rel="canonical"> with the page url
		'admin_products_sku'     => 0, //admin > Products list: 0 off, 1 under the product name, 2 own column
		'app_js_fix'             => 0, //fix app.js ajax links and the add to cart popup buttons
		'stock_block'            => 0, //0 allow ordering out-of-stock products, 1 block them
		'stock_block_statuses'   => '3', //comma separated stock_status_id values treated as out of stock (3 = Out Of Stock)
		'block_offline'          => 0, //block disabled (status off) products from the cart
		'admin_partial_codes'    => 0, //admin > Products filters: SKU, model, barcode, UPC, EAN, ISBN match part of the value
		'admin_partial_search'   => 0, //admin > Products search box: part of the name, SKU or model
		'search_results_fix'     => 0, //shop search page and live search: show product results (1.0.9.0 never shows them)
		'shop_partial_search'    => 0, //shop search also finds part of a name, description, SKU or model
		'subcategory_products'   => 0, //category pages also list the products of all sub-categories
		'checkout_region_unselected' => 0, //checkout billing and shipping regions start unselected (placeholder) instead of the site region
		'region_optional'        => 0, //checkout region only required when the country has (active) regions
		'alphabetical_locations' => 0, //checkout and account address forms list countries and regions alphabetically (core lists them by id)
		'edit_save_fix'          => 0, //save status, price etc. of products/posts created by other admins (1.0.9.0 only saves their content)
		'admin_access_guard'     => 0, //keep admins' site access, status and role unless changed on the admin user form by an admin who can edit other admins
	];

	//session key for stock messages that must survive a redirect (eg. buy now -> empty checkout -> cart)
	const STOCK_SESSION = 'ezyweb_addons_stock_messages';

	//front end controllers whose main product list is the shop listing
	const LISTING_MODULES = ['product/index', 'product/category', 'product/tag', 'product/manufacturer', 'product/vendor'];

	private array $settings;

	//settings cached for the static helpers called from compiled templates
	private static ?array $current = null;

	public static function settings(): array {
		if (self::$current !== null) {
			return self::$current;
		}

		$settings = getSetting(self::NAMESPACE, null, []);

		return (is_array($settings) ? $settings : []) + self::DEFAULTS;
	}

	private static function clamp(mixed $value, int $max): int {
		return max(0, min($max, (int)$value));
	}

	/* SKU on admin order details */
	private function orderSku(): void {
		View::getInstance()->getTemplateEngineInstance()->loadTemplateFile(__DIR__ . '/admin/template/order-sku.tpl');
	}

	/* admin > Products > Products per page */
	private function adminProductsLimit(Request $request): void {
		$limit = self::clamp($this->settings['admin_products_limit'], 500);

		//admin Base reads ?limit= before plugins are loaded, so it can't be set here directly;
		//instead the list is redirected to the same url with &limit=N&ezy_limit=1.
		//ezy_limit marks a limit added by this plugin, so when the setting changes, urls already
		//carrying an old plugin limit (reload, Back, page links) are updated to the new number.
		//a ?limit= without the marker was chosen by hand and still wins; actions/posts are left alone
		if (! empty($request->get['action']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
			return;
		}

		$query    = $_GET;
		$ours     = ! empty($query['ezy_limit']);
		$hasLimit = isset($query['limit']) && $query['limit'] !== '';

		if ($hasLimit && ! $ours) {
			return; //chosen by hand
		}

		if ($limit) {
			if ($hasLimit && (int)$query['limit'] == $limit) {
				return; //already current
			}

			$query['limit']     = $limit;
			$query['ezy_limit'] = 1;
			unset($query['page']); //page numbers change with page size
		} else {
			if (! $ours) {
				return; //feature off and nothing of ours in the url
			}

			//feature turned off: drop our limit so Vvveb's default applies again
			unset($query['limit'], $query['ezy_limit'], $query['page']);
		}

		$path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

		if ($path) {
			header('Location: ' . $path . '?' . http_build_query($query), true, 302);

			exit;
		}
	}

	/* shop listing products per page */
	private function shopProductsLimit(): void {
		$limit = self::clamp($this->settings['shop_products_limit'], 100);

		if (! $limit) {
			return;
		}

		//runs at the end of the products component constructor, after url options are resolved
		Event::on('Vvveb\Component\Products', '__construct', __CLASS__, function ($options) use ($limit) {
			//eg. product/index (shop), product/category or product/category/index
			$module   = strtolower(FrontController::getModuleName());
			$isListing = in_array($module, self::LISTING_MODULES)
				|| in_array(preg_replace('@/index$@', '', $module), self::LISTING_MODULES);

			//only the main list on listing pages, not related/variant products or home page blocks
			if ($isListing && empty($options['related']) && empty($options['variant'])) {
				$request = Request::getInstance();
				$urlLimit = self::clamp($request->get['limit'] ?? 0, 100);
				$options['limit'] = $urlLimit ?: $limit;
			}

			return [$options];
		});
	}

	/*
	 * Category pages also list the products of all sub-categories (any depth), each product once.
	 * Merged from the separate subcategory-products plugin v0.1. Core 1.0.9.0 passes only the
	 * clicked category to the products component, and getAll() joins product_to_taxonomy_item,
	 * so several category ids would list a product once per category. The main list gets the
	 * product ids instead, which the component honours with source autocomplete.
	 */
	private static ?array $categoryChildren = null;

	private static function categoryDescendants(int $id, int $siteId, int $languageId): array {
		if (self::$categoryChildren === null) {
			self::$categoryChildren = [];
			$results = (new CategorySQL())->getCategories([
				'type'        => 'categories',
				'post_type'   => 'product',
				'site_id'     => $siteId,
				'language_id' => $languageId,
			]);

			foreach ($results['categories'] ?? [] as $category) {
				self::$categoryChildren[(int)$category['parent_id']][] = (int)$category['taxonomy_item_id'];
			}
		}

		$ids   = [];
		$stack = self::$categoryChildren[$id] ?? [];

		while ($stack) {
			$child = array_pop($stack);

			if (isset($ids[$child]) || $child == $id) {
				continue; //guard against a parent loop
			}

			$ids[$child] = $child;

			foreach (self::$categoryChildren[$child] ?? [] as $grandChild) {
				$stack[] = $grandChild;
			}
		}

		return array_values($ids);
	}

	private static function categoryProductIds(array $categoryIds): array {
		$db     = Db::getInstance();
		$in     = implode(',', array_map('intval', $categoryIds));
		$result = $db->query('SELECT DISTINCT product_id FROM ' . DB_PREFIX . "product_to_taxonomy_item WHERE taxonomy_item_id IN ($in)");
		$rows   = $result ? $db->fetchAll($result) : [];

		return array_map(fn ($row) => (int)$row['product_id'], $rows ?: []);
	}

	private function subcategoryProducts(): void {
		Event::on('Vvveb\Component\Products', '__construct', __CLASS__ . 'subcat', function ($options) {
			$request    = Request::getInstance();
			$categoryId = $request->get['category_id'] ?? null;
			$requested  = $options['taxonomy_item_id'] ?? null;
			$module     = preg_replace('@/index$@', '', strtolower(FrontController::getModuleName()));

			//only the main product list on a category page, not other product blocks on it
			if ($module != 'product/category' || ! $categoryId || ! $requested || is_array($requested) || $requested != $categoryId
				|| ! empty($options['related']) || ! empty($options['variant']) || ! empty($options['product_id'])) {
				return [$options];
			}

			$descendants = self::categoryDescendants((int)$categoryId, (int)($options['site_id'] ?? 1), (int)($options['language_id'] ?? 1));

			if (! $descendants) {
				return [$options]; //no sub-categories, core already lists the right products
			}

			$productIds = self::categoryProductIds(array_merge([(int)$categoryId], $descendants));

			if (! $productIds) {
				return [$options]; //genuinely empty, keep the normal no products message
			}

			unset($options['taxonomy_item_id']);
			$options['product_id'] = array_fill_keys($productIds, 1);
			$options['source']     = 'autocomplete'; //makes the component keep product_id

			return [$options];
		});
	}

	/*
	 * Slug of the site's default language. Falls back to the site language_id and then to the
	 * language marked as default when the global default slug is empty (seen on upgraded sites).
	 */
	private static function defaultLanguageSlug(array $global): string {
		$default = (string)($global['default_language'] ?? '');

		if ($default !== '') {
			return $default;
		}

		$languages = Locale::availableLanguages();
		$defaultId = $global['site']['language_id'] ?? $global['default_language_id'] ?? 0;

		foreach ($languages as $slug => $language) {
			if ($defaultId && $language['language_id'] == $defaultId) {
				return (string)$slug;
			}
		}

		foreach ($languages as $slug => $language) {
			if (! empty($language['default'])) {
				return (string)$slug;
			}
		}

		return '';
	}

	/* true when the bundled seo plugin is active, it adds its own canonical holder tag */
	private static function seoPluginActive(): bool {
		$siteId = defined('SITE_ID') ? SITE_ID : 1;

		return (\Vvveb\getConfig("plugins.$siteId.seo.status") ?? '') === 'active';
	}

	/* 301 /en/page to /page when "Include url slug" is off, also unknown prefixes such as /xx/page */
	private function defaultLanguageRedirect(): void {
		Event::on('Vvveb\Controller\Base', 'init:after', __CLASS__, function ($global) {
			$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

			//posts are left alone, the language/currency switcher posts to the current url
			if (($method !== 'GET' && $method !== 'HEAD') || ! empty($global['site']['default_lang_slug'])) {
				return [$global];
			}

			$lang = Request::getInstance()->get['language'] ?? null;

			if (! $lang) {
				return [$global];
			}

			$default   = self::defaultLanguageSlug($global);
			$isDefault = ($default !== '' && $lang === $default);
			$isUnknown = ! isset(Locale::availableLanguages()[$lang]);

			//an active non default language such as /fr/page is a real translation, keep it
			if (! $isDefault && ! $isUnknown) {
				return [$global];
			}

			$uri    = $_SERVER['REQUEST_URI'] ?? '/';
			$prefix = rtrim('/' . trim($global['path'] ?? '', '/'), '/');
			$regex  = '#^' . preg_quote($prefix, '#') . '/' . preg_quote($lang, '#') . '(?:/|(?=\?|$))#';
			$target = preg_replace($regex, $prefix . '/', $uri, 1);

			if ($target && $target !== $uri) {
				header('Location: ' . $target, true, 301);

				exit;
			}

			return [$global];
		});
	}

	/*
	 * Fill the theme's empty <link rel="canonical" href=""> with the page url (no query string).
	 * Loaded on compile:after (priority 2000, after the seo plugin) because themes copy <head> from
	 * index.html with data-v-save-global, which would overwrite rules loaded earlier.
	 */
	private function canonical(): void {
		$seoActive = self::seoPluginActive();

		Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__, function ($template, $filename, $tplFile, $templateEngine, $view) use ($seoActive) {
			if ($seoActive) {
				//uses the seo plugin's canonical url when it has one, then clears it so the seo
				//plugin's own holder tag can't add a second canonical; works without the holder too
				$templateEngine->loadTemplateFile(__DIR__ . '/app/template/canonical-seo.tpl');
			} else {
				//core patched to fill the canonical itself: leave it to core
				$core = DIR_ROOT . 'app' . DS . 'template' . DS . 'common.tpl';

				if (! (is_file($core) && str_contains((string)file_get_contents($core), 'link[rel="canonical"]'))) {
					$templateEngine->loadTemplateFile(__DIR__ . '/app/template/canonical.tpl');
				}
			}

			return [$template, $filename, $tplFile, $templateEngine, $view];
		}, 2000);
	}

	/* SKU in admin > Products list, under the product name or in its own column */
	private function adminProductsSku(): void {
		$mode = (int)$this->settings['admin_products_sku'];
		$file = [1 => 'products-sku-title.tpl', 2 => 'products-sku-column.tpl'][$mode] ?? null;

		if ($file) {
			View::getInstance()->getTemplateEngineInstance()->loadTemplateFile(__DIR__ . '/admin/template/' . $file);
		}
	}

	/* url of public/app-js-fix.js, versioned by file time so browsers load a new copy after updates */
	public static function appJsFixUrl(): string {
		$file    = __DIR__ . '/public/app-js-fix.js';
		$version = is_file($file) ? filemtime($file) : 0;

		return (defined('PUBLIC_PATH') ? PUBLIC_PATH : '/') . 'plugins/ezyweb-addons/app-js-fix.js?v=' . $version;
	}

	/*
	 * app.js fixes (Vvveb 1.0.9.0): trailing slash in VvvebApp.path when bundled by minify (//cart
	 * popup links), navigateTo() "element is not defined", and the duplicate click listener.
	 * The script is added at the end of <body>, after app.js, with data-no-minify so minify keeps it separate.
	 */
	private function appJsFix(): void {
		Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__ . 'appjs', function ($template, $filename, $tplFile, $templateEngine, $view) {
			$templateEngine->loadTemplateFile(__DIR__ . '/app/template/app-js-fix.tpl');

			return [$template, $filename, $tplFile, $templateEngine, $view];
		}, 2000);
	}

	/* ---------- shop search: full-text search terms ---------- */

	/*
	 * Vvveb passes the search text straight into the full-text query. On SQLite (FTS5) characters
	 * such as - ' " : ( ) * ^ are query syntax, so "drs-5678" fails with "no such column: 5678" and
	 * the search page stops part way (the request filter also turns ' into &#039;). MySQL boolean
	 * mode reads - as "exclude". The index splits words on these characters anyway, so they are
	 * replaced by spaces: "drs-5678" searches for drs and 5678.
	 */
	public static function fulltextSafe(string $search): string {
		$search = html_entity_decode($search, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$search = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $search) ?? '';

		return trim(preg_replace('/\s+/u', ' ', $search) ?? '');
	}

	private function fulltextSearchTerms(): void {
		foreach (['Vvveb\Component\Products', 'Vvveb\Component\Posts'] as $class) {
			Event::on($class, '__construct', __CLASS__ . 'fulltext', function ($options) {
				if (isset($options['search']) && is_string($options['search']) && $options['search'] !== '') {
					$safe = self::fulltextSafe($options['search']);

					//nothing searchable left (eg. "-"): a word that matches nothing
					$options['search'] = $safe !== '' ? $safe : 'ezywebnomatch';
				}

				return [$options];
			}, 900); //before the shop partial search listener (default priority 1000)
		}
	}

	/* ---------- shop search: also match part of a SKU or model ---------- */

	/*
	 * Product ids where every word of the search appears (any case, any part) in the product's
	 * name, description, SKU or model. Words are matched separately, like the full-text search.
	 */
	private static function shopSearchIds(string $search, ?int $languageId): array {
		$search = html_entity_decode($search, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$words  = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($search)) ?: [], 'strlen')), 0, 6);

		if (! $words) {
			return [];
		}

		$db         = Db::getInstance();
		$prefix     = defined('DB_PREFIX') ? DB_PREFIX : '';
		$conditions = [];

		foreach ($words as $word) {
			$conditions[] = '(' . self::containsSql($db, 'pc.name', $word)
				. ' OR ' . self::containsSql($db, 'pc.content', $word)
				. ' OR ' . self::containsSql($db, 'p.sku', $word)
				. ' OR ' . self::containsSql($db, 'p.model', $word) . ')';
		}

		$sql = 'SELECT DISTINCT p.product_id FROM ' . $prefix . 'product p'
			. ' LEFT JOIN ' . $prefix . 'product_content pc ON pc.product_id = p.product_id'
			. ($languageId ? ' AND pc.language_id = ' . (int)$languageId : '')
			. ' WHERE ' . implode(' AND ', $conditions);

		$result = $db->query($sql);
		$ids    = [];

		if ($result) {
			foreach ($db->fetchAll($result) ?: [] as $row) {
				$ids[] = (int)$row['product_id'];
			}
		}

		return $ids;
	}

	/*
	 * Every shop product search (search page, live search in the modal and the header box) goes
	 * through the products component with a search option, which Vvveb sends to the full-text index:
	 * whole words of the name and description, never the SKU or model. Before the query runs, the
	 * matching ids are looked up here and passed as product_id (the component uses product_id when
	 * source is autocomplete, its default) and the full-text search is removed. Status, site,
	 * category and the other options still apply. The search words are put back into the results by
	 * prepareProductLists(), because themes show the product results only when products.search is set.
	 */
	private function shopPartialSearch(): void {
		Event::on('Vvveb\Component\Products', '__construct', __CLASS__ . 'shopsearch', function ($options) {
			$search = trim((string)($options['search'] ?? ''));

			if ($search === '' || ! empty($options['related']) || ! empty($options['variant'])) {
				return [$options];
			}

			$languageId = isset($options['language_id']) ? (int)$options['language_id'] : null;
			$ids        = self::shopSearchIds($search, $languageId ?: null);

			$options['ezy_search']   = $search;
			$options['source']       = 'autocomplete';
			$options['product_id']   = array_fill_keys($ids ?: [0], 1); //product 0: no matches, an empty list would list everything
			$options['search']       = null;
			$options['search_boolean'] = null;

			return [$options];
		});
	}

	/* ---------- shop search: show product results (Vvveb 1.0.9.0) ---------- */

	/*
	 * Core app/template/components/products.tpl sets $products to the list of items, but themes test
	 * the component data on it: data-v-if="products.count > 0" and "products.search && products.count > 0"
	 * (search page and live search). Those compile to $products['count'], which never exists, so no
	 * product results are ever shown. Run at the start of <body>, after the components are loaded:
	 * each products list becomes a list that still loops over the products but also answers
	 * ['count'], ['search'] and the other component values.
	 */
	public static function prepareProductLists(object $view): void {
		if (! isset($view->_component['products']) || ! is_array($view->_component['products'])) {
			return;
		}

		$settings = self::settings();

		foreach ($view->_component['products'] as $index => $component) {
			//shop partial search removed the search option before the query: put the words back
			$instance = is_array($component) ? ($component['_instance'] ?? null) : null;

			if (is_object($instance)) {
				$options = (function () { return $this->options ?? []; })->call($instance);

				if (! empty($options['ezy_search'])) {
					$view->_component['products'][$index]['search'] = $options['ezy_search'];
					$component['search']                             = $options['ezy_search'];
				}
			}

			if (empty($settings['search_results_fix'])) {
				continue;
			}

			if (! is_array($component) || (isset($component['product']) && ! is_array($component['product']))) {
				continue; //already wrapped, or not a list
			}

			$items = $component['product'] ?? [];
			$meta  = $component;
			unset($meta['product'], $meta['_instance']);

			$view->_component['products'][$index]['product'] = new EzywebAddonsProductList($items, $meta);
		}
	}

	private function prepareProductListsTemplate(): void {
		Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__ . 'search', function ($template, $filename, $tplFile, $templateEngine, $view) {
			$templateEngine->loadTemplateFile(__DIR__ . '/app/template/search-fix.tpl');

			return [$template, $filename, $tplFile, $templateEngine, $view];
		}, 2000);
	}

	/* ---------- admin > Products: partial matches in the filters ---------- */

	//filter fields Vvveb matches exactly (admin/sql/*/product.sql getAll uses product.<field> = :<field>)
	const PARTIAL_CODE_FIELDS = ['sku', 'model', 'barcode', 'upc', 'ean', 'isbn'];

	//original filter values, put back before the page renders (filter fields and page links)
	private ?array $originalFilter = null;

	/* LOWER(column) LIKE '%value%' with the value escaped for the database and for LIKE wildcards */
	private static function containsSql(object $db, string $column, string $value): string {
		//Vvveb's request filter html-escapes get values (o'brien arrives as o&#039;brien), names are stored as typed
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
		$value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

		return 'LOWER(' . $column . ') LIKE \'%' . $db->escape($value) . '%\' ESCAPE \'!\'';
	}

	/*
	 * Vvveb matches SKU, model, barcode, UPC, EAN and ISBN exactly, and the search box uses a
	 * whole-word full-text index. The SQL can't be changed from a plugin, so the matching product
	 * ids are looked up here with LIKE '%value%' and passed to the list as filter[product_id]
	 * (getAll supports it); the other filters, sorting and paging still apply. The typed values
	 * are restored before render, so the filter fields and page links keep them.
	 */
	private function adminPartialFilters(Request $request): void {
		$codes  = ! empty($this->settings['admin_partial_codes']);
		$search = ! empty($this->settings['admin_partial_search']);
		$filter = $request->get['filter'] ?? null;

		if ((! $codes && ! $search) || ! is_array($filter)) {
			return;
		}

		$db         = Db::getInstance();
		$prefix     = defined('DB_PREFIX') ? DB_PREFIX : '';
		$conditions = [];
		$used       = [];

		if ($codes) {
			foreach (self::PARTIAL_CODE_FIELDS as $field) {
				$value = trim((string)($filter[$field] ?? ''));

				if ($value !== '') {
					$conditions[] = self::containsSql($db, 'p.' . $field, $value);
					$used[]       = $field;
				}
			}
		}

		if ($search) {
			$value = trim((string)($filter['search'] ?? ''));

			if ($value !== '') {
				$conditions[] = '(' . self::containsSql($db, 'pc.name', $value)
					. ' OR ' . self::containsSql($db, 'p.sku', $value)
					. ' OR ' . self::containsSql($db, 'p.model', $value) . ')';
				$used[] = 'search';
			}
		}

		if (! $conditions) {
			return;
		}

		$sql = 'SELECT DISTINCT p.product_id FROM ' . $prefix . 'product p'
			. ' LEFT JOIN ' . $prefix . 'product_content pc ON pc.product_id = p.product_id'
			. ' WHERE ' . implode(' AND ', $conditions);

		$result = $db->query($sql);
		$ids    = [];

		if ($result) {
			foreach ($db->fetchAll($result) ?: [] as $row) {
				$ids[] = (int)$row['product_id'];
			}
		}

		$this->originalFilter = $filter;

		foreach ($used as $field) {
			unset($filter[$field]);
		}

		//no matches: product_id 0 gives an empty list (an empty array would be dropped and list everything)
		$filter['product_id'] = $ids ?: [0];

		$request->get['filter'] = $filter;

		//put the typed values back after the list is loaded, before the filter fields and page links are rendered
		Event::on('Vvveb\System\Core\View', 'render', __CLASS__ . 'partial', function ($template, $tplFile, $templateEngine, $view) use ($request) {
			if ($this->originalFilter !== null) {
				$request->get['filter'] = $this->originalFilter;
				//shown as typed: the request filter html-escaped them and the template escapes them again
				$view->filter           = array_map(fn ($v) => is_string($v) ? html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v, array_filter($this->originalFilter));
			}

			return [$template, $tplFile, $templateEngine, $view];
		});
	}

	/* ---------- out-of-stock and disabled products ---------- */

	/* true when either cart blocking feature is switched on */
	public static function stockActive(): bool {
		$settings = self::settings();

		return ! empty($settings['stock_block']) || ! empty($settings['block_offline']);
	}

	/* stock_status_id values that count as out of stock, eg. [3] */
	private static function blockedStatuses(): array {
		$list = array_map('intval', explode(',', (string)(self::settings()['stock_block_statuses'] ?? '')));

		return array_values(array_filter($list));
	}

	/*
	 * Why a product can't be ordered: 'offline', 'out_of_stock' or '' (it can).
	 * $product is a product row (status, stock_status_id, subtract_stock, stock_quantity);
	 * missing fields are skipped. $variantQuantity overrides stock_quantity for a chosen variant.
	 */
	public static function unavailableReason(array $product, ?int $variantQuantity = null): string {
		if (! $product) {
			return '';
		}

		$settings = self::settings();

		if (! empty($settings['block_offline']) && isset($product['status']) && (int)$product['status'] !== 1) {
			return 'offline';
		}

		if (! empty($settings['stock_block'])) {
			if (isset($product['stock_status_id']) && in_array((int)$product['stock_status_id'], self::blockedStatuses(), true)) {
				return 'out_of_stock';
			}

			$quantity = $variantQuantity ?? (isset($product['stock_quantity']) ? (int)$product['stock_quantity'] : null);

			//quantity only counts when stock tracking (Subtract stock) is on for the product
			if (! empty($product['subtract_stock']) && $quantity !== null && $quantity < 1) {
				return 'out_of_stock';
			}
		}

		return '';
	}

	/* used by app/template/stock.tpl to disable buttons */
	public static function unavailable(array $product): bool {
		return self::unavailableReason($product) !== '';
	}

	/* quantity input max on the product page: the stock quantity, only while blocking tracked stock */
	public static function quantityMax(array $product): int {
		if (empty(self::settings()['stock_block']) || empty($product['subtract_stock'])) {
			return 0;
		}

		return max(0, (int)($product['stock_quantity'] ?? 0));
	}

	/* replaces a listing's Add to cart link when the product can't be ordered */
	public static function outOfStockButton(array $product): string {
		$label = self::unavailableReason($product) === 'offline' ? \Vvveb\__('Unavailable') : \Vvveb\__('Out of stock');

		return '<a href="#" class="btn btn-secondary btn-sm w-100 disabled" aria-disabled="true" tabindex="-1">'
			. '<span class="button-text">' . htmlspecialchars($label) . '</span></a>';
	}

	/* product rows with the fields unavailableReason() needs, keyed by product_id */
	private static function productRows(array $productIds): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

		if (! $ids) {
			return [];
		}

		$db     = Db::getInstance();
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$rows   = [];
		$result = $db->query('SELECT product_id, status, stock_status_id, subtract_stock, stock_quantity FROM ' . $prefix . 'product WHERE product_id IN (' . implode(',', $ids) . ')');

		if ($result) {
			foreach ($db->fetchAll($result) ?: [] as $row) {
				$rows[(int)$row['product_id']] = $row;
			}
		}

		return $rows;
	}

	/* stock quantity of a product variant, null when there's no such variant */
	private static function variantQuantity(int $variantId): ?int {
		if ($variantId < 1) {
			return null;
		}

		$db     = Db::getInstance();
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$result = $db->query('SELECT stock_quantity FROM ' . $prefix . 'product_variant WHERE product_variant_id = ' . $variantId);
		$rows   = $result ? ($db->fetchAll($result) ?: []) : [];

		return isset($rows[0]['stock_quantity']) ? (int)$rows[0]['stock_quantity'] : null;
	}

	/* reason a product id can't be ordered; products missing from the database count as offline */
	private static function productReason(int $productId, int $variantId = 0): string {
		$rows = self::productRows([$productId]);

		if (! isset($rows[$productId])) {
			return empty(self::settings()['block_offline']) ? '' : 'offline';
		}

		return self::unavailableReason($rows[$productId], self::variantQuantity($variantId));
	}

	private static function stockMessage(string $reason, string $name = ''): string {
		if ($name !== '') {
			return $reason === 'offline'
				? sprintf(\Vvveb\__('%s is no longer available and was removed from your cart.'), $name)
				: sprintf(\Vvveb\__('%s is out of stock and was removed from your cart.'), $name);
		}

		return $reason === 'offline'
			? \Vvveb\__('This product is no longer available.')
			: \Vvveb\__('This product is out of stock and can\'t be added to the cart.');
	}

	/* adds a message to the page notifications (type "stock") */
	private static function addStockError(string $message): void {
		$view                    = View::getInstance();
		$errors                  = is_array($view->errors ?? null) ? $view->errors : [];
		$errors['stock'][]       = $message;
		$view->errors            = $errors;
	}

	/* the shopping cart, created with the same options as the cart and checkout controllers */
	private static function cart(array $global): ShoppingCart {
		$options = array_intersect_key(
			$global['site'] ?? [],
			array_flip(['weight_type_id', 'length_type_id', 'currency_id', 'country_id'])
		);

		return ShoppingCart::getInstance($global + $options);
	}

	/* removes cart items that can't be ordered any more; returns the messages */
	private static function removeUnavailableItems(ShoppingCart $cart): array {
		$items = $cart->getAll();

		if (! $items) {
			return [];
		}

		$rows     = self::productRows(array_column($items, 'product_id'));
		$messages = [];

		foreach ($items as $key => $item) {
			$productId = (int)($item['product_id'] ?? 0);
			$row       = $rows[$productId] ?? null;

			if ($row === null) {
				$reason = empty(self::settings()['block_offline']) ? '' : 'offline';
			} else {
				$reason = self::unavailableReason($row, self::variantQuantity((int)($item['product_variant_id'] ?? 0)));
			}

			if ($reason !== '') {
				$cart->remove((string)$key);
				$messages[] = self::stockMessage($reason, (string)($item['name'] ?? ''));
			}
		}

		return $messages;
	}

	/*
	 * Checkout region only required when the country has regions (switch region_optional).
	 * Core validation requires billing/shipping region_id "not empty", and its rule only rejects
	 * '', false and null, so for a country with no active regions (the same regions core's regions
	 * endpoint lists, status 1) an empty posted region is set to 0, "no particular region", which
	 * tax and shipping zones already use for "the whole country". public/region-optional.js hides
	 * the field and drops required in the browser.
	 */
	private static array $countryRegionCount = [];

	public static function countryHasRegions(int $countryId): bool {
		if (! isset(self::$countryRegionCount[$countryId])) {
			$db     = Db::getInstance();
			$result = $db->query('SELECT count(*) AS n FROM ' . DB_PREFIX . 'region WHERE country_id = ' . $countryId . ' AND status = 1');
			$rows   = $result ? $db->fetchAll($result) : [];
			self::$countryRegionCount[$countryId] = (int)($rows[0]['n'] ?? 0);
		}

		return self::$countryRegionCount[$countryId] > 0;
	}

	private function regionOptional(): void {
		Event::on('Vvveb\Controller\Base', 'init:after', __CLASS__ . 'regionopt', function ($global) {
			$module = preg_replace('@/index$@', '', strtolower(FrontController::getModuleName()));
			$request = Request::getInstance();

			if ($module === 'checkout/checkout' && ! empty($request->post)) {
				foreach (['billing_address', 'shipping_address'] as $key) {
					$address = $request->post[$key] ?? null;

					if (! is_array($address) || empty($address['country_id'])) {
						continue;
					}

					$region = $address['region_id'] ?? '';

					if (($region === '' || $region === null) && ! self::countryHasRegions((int)$address['country_id'])) {
						$request->post[$key]['region_id'] = '0';
					}
				}
			}

			return [$global];
		});

		Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__ . 'regionoptjs', function ($template, $filename, $tplFile, $templateEngine, $view) {
			$templateEngine->loadTemplateFile(__DIR__ . '/app/template/region-optional.tpl');

			return [$template, $filename, $tplFile, $templateEngine, $view];
		}, 2000);
	}

	/* ---------- countries and regions in alphabetical order (switch alphabetical_locations) ----------
	 *
	 * Core's country and region getAll() sort by id (status first), so countries and regions added or
	 * renamed later, and parts of the install data (e.g. the Northern Irish counties and Cumbria after
	 * Wrexham), are out of order. The checkout and account address forms get their lists sorted by name
	 * just before the page is rendered, and the regions endpoint used when the country changes
	 * (checkout/checkout action regions, JSON) is re-sorted by a response filter. Core builds both lists
	 * as before; only the order changes. Admin lists keep core's order (see the core patch in the notes).
	 */
	private static $collator = null;

	private static function compareNames(string $a, string $b): int {
		if (self::$collator === null) {
			self::$collator = class_exists('Collator') ? new \Collator('root') : false;

			if (self::$collator) {
				//ignore case and accents: Aaland, Åland and aaland sort together
				self::$collator->setStrength(\Collator::PRIMARY);
			}
		}

		if (self::$collator) {
			return self::$collator->compare($a, $b);
		}

		//no intl extension: compare without accents, in any case
		$fold = function ($s) {
			$ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;

			return strtolower($ascii !== false ? $ascii : $s);
		};

		return strnatcmp($fold($a), $fold($b));
	}

	/* sort rows (arrays with a 'name') by name; a list stays a list, keyed rows keep their keys */
	public static function sortByName(array $rows): array {
		$cmp = fn ($a, $b) => self::compareNames((string)(is_array($a) ? ($a['name'] ?? '') : $a), (string)(is_array($b) ? ($b['name'] ?? '') : $b));

		if (array_is_list($rows)) {
			usort($rows, $cmp);
		} else {
			uasort($rows, $cmp);
		}

		return $rows;
	}

	private function alphabeticalLocations(): void {
		//regions endpoint (checkout and account address forms call it when the country changes)
		Event::on('Vvveb\Controller\Base', 'init:after', __CLASS__ . 'alpha', function ($global) {
			$module  = preg_replace('@/index$@', '', strtolower(FrontController::getModuleName()));
			$request = Request::getInstance();

			if ($module === 'checkout/checkout' && ($request->get['action'] ?? '') === 'regions') {
				\Vvveb\System\Core\Response::getInstance()->addFilter(function ($output) {
					$regions = is_string($output) ? json_decode($output, true) : null;

					if (! is_array($regions) || ! $regions) {
						return $output;
					}

					return json_encode(self::sortByName($regions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				});
			}

			return [$global];
		});

		//lists drawn with the page: checkout and the account pages (address form)
		Event::on('Vvveb\System\Core\View', 'render', __CLASS__ . 'alpha', function ($template, $tplFile, $templateEngine, $view) {
			$module = strtolower(FrontController::getModuleName());

			if (str_starts_with($module, 'checkout/') || str_starts_with($module, 'user/')) {
				foreach (['countries', 'regions'] as $list) {
					if (isset($view->$list) && is_array($view->$list)) {
						$view->$list = self::sortByName($view->$list);
					}
				}
			}

			return [$template, $tplFile, $templateEngine, $view];
		});
	}

	/* region-optional.js, only on pages with a checkout region dropdown (checkout routes) */
	public static function regionOptionalFooter(): string {
		$module = preg_replace('@/index$@', '', strtolower(FrontController::getModuleName()));

		if (! str_starts_with($module, 'checkout/')) {
			return '';
		}

		$file = __DIR__ . '/public/region-optional.js';
		$url  = (defined('PUBLIC_PATH') ? PUBLIC_PATH : '/') . 'plugins/ezyweb-addons/region-optional.js?v=' . (is_file($file) ? filemtime($file) : 0);

		return '<script id="ezyweb-region-optional-js" data-no-minify src="' . htmlspecialchars($url, ENT_QUOTES) . '" defer></script>';
	}

	/* url of public/stock.js, versioned by file time */
	public static function stockJsUrl(): string {
		$file    = __DIR__ . '/public/stock.js';
		$version = is_file($file) ? filemtime($file) : 0;

		return (defined('PUBLIC_PATH') ? PUBLIC_PATH : '/') . 'plugins/ezyweb-addons/stock.js?v=' . $version;
	}

	/*
	 * Appended to <body>: the script that shows the message instead of the "added to cart" popup,
	 * and, on a blocked add to cart response, a marker the script reads the message from.
	 */
	public static function stockFooter(object $view): string {
		if (! self::stockActive()) {
			return '';
		}

		$html = '<script id="ezyweb-stock-js" data-no-minify src="' . htmlspecialchars(self::stockJsUrl(), ENT_QUOTES) . '"></script>';

		if (! empty($view->ezyStockMessage)) {
			$html .= '<template id="ezy-stock-blocked" data-message="' . htmlspecialchars((string)$view->ezyStockMessage, ENT_QUOTES) . '"></template>';
		}

		return $html;
	}

	/*
	 * Block out-of-stock and/or disabled products from the cart (Vvveb 1.0.9.0 never checks):
	 * - add to cart (ajax or form post, also cart/cart?product_id=): the add is skipped and a message shown
	 * - buy now (checkout?product_id=): the product isn't added
	 * - cart and checkout pages: items that can't be ordered any more are removed, with a message
	 * - product pages and listings: Add to cart / Buy now are disabled (app/template/stock.tpl)
	 */
	private function stock(): void {
		//add to cart: Vvveb\Controller\Cart\Cart::action() triggers this before Cart::add()
		Event::on('Vvveb\Controller\Cart\Cart', 'action', __CLASS__ . 'stock', function ($action, $productId, $key, $quantity, $option, $subscriptionPlanId) {
			if ($action === 'add' && $productId) {
				$request   = Request::getInstance();
				$variantId = (int)($request->request['product_variant_id'] ?? 0);
				$reason    = self::productReason((int)$productId, $variantId);

				if ($reason !== '') {
					$message                                = self::stockMessage($reason);
					View::getInstance()->ezyStockMessage    = $message;
					self::addStockError($message);
					$action                                 = 'ezyweb_blocked'; //no matching case, nothing is added
				}
			}

			return [$action, $productId, $key, $quantity, $option, $subscriptionPlanId];
		});

		//cart and checkout pages, before their controllers run
		Event::on('Vvveb\Controller\Base', 'init:after', __CLASS__ . 'stock', function ($global) {
			$module = strtolower(FrontController::getModuleName());
			$base   = preg_replace('@/index$@', '', $module);

			if ($base !== 'cart/cart' && $base !== 'checkout/checkout') {
				return [$global];
			}

			$request  = Request::getInstance();
			$session  = Session::getInstance();
			$messages = $session->get(self::STOCK_SESSION) ?: [];
			$cart     = self::cart($global);

			//buy now posts product_id to checkout, which adds it after this event
			if ($base === 'checkout/checkout' && ! empty($request->request['product_id'])) {
				$variantId = (int)($request->request['product_variant_id'] ?? 0);
				$reason    = self::productReason((int)$request->request['product_id'], $variantId);

				if ($reason !== '') {
					unset($request->request['product_id'], $request->post['product_id'], $request->get['product_id']);
					$messages[] = self::stockMessage($reason);
				}
			}

			$messages = array_merge($messages, self::removeUnavailableItems($cart));

			//an empty checkout redirects to the cart, so keep the messages for the cart page
			if ($base === 'checkout/checkout' && ! $cart->hasProducts()) {
				if ($messages) {
					$session->set(self::STOCK_SESSION, $messages);
				}

				return [$global];
			}

			$session->delete(self::STOCK_SESSION);

			foreach (array_unique($messages) as $message) {
				self::addStockError($message);
			}

			return [$global];
		});

		//buttons on product pages and listings, and the popup script
		Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__ . 'stock', function ($template, $filename, $tplFile, $templateEngine, $view) {
			$templateEngine->loadTemplateFile(__DIR__ . '/app/template/stock.tpl');

			return [$template, $filename, $tplFile, $templateEngine, $view];
		}, 2000);
	}

	/* Products and posts: save the main fields of items created by other admins (1.0.9.0)
	 *
	 * Core saveEditData() passes the saving admin's id to the edit sql, which then runs
	 * "UPDATE product|post ... WHERE id = :id AND admin_id = :admin_id". For an item created by anyone
	 * else that matches no rows, so status, price, stock etc. are not saved, while the content
	 * (name, description), categories and fields are, and the page still says "saved".
	 * After core has saved, redo only that UPDATE without the admin_id condition, keeping the owner.
	 */
	private function editSaveFix(): void {
		//the Edit trait is used by Vvveb\Controller\Content\Edit (posts, pages) and extended by Product\Product
		Event::on('Vvveb\Controller\Content\Edit', 'save', __CLASS__ . 'editsave', function ($post, $postId, $type) {
			$params = [$post, $postId, $type];

			if (! is_array($post) || ! $postId) {
				return $params;
			}

			$object = isset($post['product_content']) ? 'product' : (isset($post['post_content']) ? 'post' : '');

			if (! $object) {
				return $params;
			}

			//admins without edit_other_* can only save their own items, which core already saves fully
			if (! Admin::hasCapability($object == 'product' ? 'edit_other_products' : 'edit_other_posts')) {
				return $params;
			}

			$adminId = (int)(Admin::current()['admin_id'] ?? 0);
			$db      = Db::getInstance();
			$prefix  = defined('DB_PREFIX') ? DB_PREFIX : '';
			$result  = $db->query('SELECT admin_id FROM ' . $prefix . $object . ' WHERE ' . $object . '_id = ' . (int)$postId);
			$rows    = $result ? ($db->fetchAll($result) ?: []) : [];

			//new items and the admin's own items are saved by core
			if (! $rows || (int)($rows[0]['admin_id'] ?? 0) === $adminId) {
				return $params;
			}

			//only the main row: no categories, sites or fields, and an empty content list
			//(product edit filters it unconditionally), so nothing else is saved twice
			$row = $post;
			unset($row['admin_id']); //keep the original author

			\Vvveb\model($object)->edit([$object => $row, $object . '_id' => (int)$postId, $object . '_content' => []]);

			return $params;
		}, 1000);
	}

	/* Admin site access, status and role
	 *
	 * Keeps the stored site_access, status and role_id of an admin unless they are saved on the admin
	 * user form by an admin with the edit_other_admin capability. Every admin UPDATE goes through the
	 * database driver's execute event with the row in $params['admin'], so the stored values are put
	 * back into any other update of those columns.
	 */
	private static bool $adminGuardBusy = false;

	private function adminAccessGuard(): void {
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';

		$guard = function ($sql, $params) use ($prefix) {
			$result = [$sql, $params];

			if (self::$adminGuardBusy || ! is_array($params['admin'] ?? null) ||
				! preg_match('/^\s*UPDATE\s+[`"]?' . preg_quote($prefix . 'admin', '/') . '[`"]?\s/i', (string)$sql)) {
				return $result;
			}

			$fields = array_intersect(['site_access', 'status', 'role_id'], array_keys($params['admin']));

			if (! $fields) {
				return $result;
			}

			//the admin user form, saved by an admin allowed to manage other admins
			$module = strtolower((string)FrontController::getModuleName());
			$action = strtolower((string)(FrontController::getActionName() ?? ''));

			if ($module == 'admin/user' && $action == 'save' && Admin::hasCapability('edit_other_admin')) {
				return $result;
			}

			$db    = Db::getInstance();
			$where = [];

			if (isset($params['admin_id'])) {
				$where[] = 'admin_id = ' . (int)$params['admin_id'];
			}

			foreach (['email', 'username'] as $key) {
				if (isset($params[$key]) && is_scalar($params[$key])) {
					$where[] = $key . " = '" . $db->escape((string)$params[$key]) . "'";
				}
			}

			if (! $where) {
				return $result;
			}

			self::$adminGuardBusy = true;

			try {
				$res  = $db->query('SELECT site_access, status, role_id FROM ' . $prefix . 'admin WHERE ' . implode(' AND ', $where) . ' LIMIT 2');
				$rows = $res ? ($db->fetchAll($res) ?: []) : [];
			} finally {
				self::$adminGuardBusy = false;
			}

			//unknown or more than one admin: leave the update as it is
			if (count($rows) != 1) {
				return $result;
			}

			foreach ($fields as $field) {
				$params['admin'][$field] = $rows[0][$field] ?? ($field == 'site_access' ? '[]' : $params['admin'][$field]);
			}

			return [$sql, $params];
		};

		foreach (['Sqlite', 'Mysqli', 'Pgsql'] as $driver) {
			Event::on('Vvveb\\System\\Db\\' . $driver, 'execute', __CLASS__ . 'adminguard', $guard, 1000);
		}
	}

	public function admin(): void {
		$request = Request::getInstance();
		$module  = $request->get['module'] ?? '';

		if ($this->settings['edit_save_fix']) {
			$this->editSaveFix();
		}

		if ($this->settings['admin_access_guard']) {
			$this->adminAccessGuard();
		}

		if ($module == 'order/order' && $this->settings['order_sku']) {
			$this->orderSku();
		}

		if ($module == 'product/products') {
			$this->adminProductsLimit($request);
			$this->adminProductsSku();
			$this->adminPartialFilters($request);
		}
	}

	public function app(): void {
		self::$current = $this->settings;

		$this->shopProductsLimit();

		if ($this->settings['subcategory_products']) {
			$this->subcategoryProducts();
		}

		if ($this->settings['region_optional']) {
			$this->regionOptional();
		}

		if ($this->settings['alphabetical_locations']) {
			$this->alphabeticalLocations();
		}

		if ($this->settings['checkout_region_unselected']) {
			//checkout billing and shipping regions: only the customer's own choice is selected
			Event::on('Vvveb\System\Core\View', 'compile:after', __CLASS__ . 'region', function ($template, $filename, $tplFile, $templateEngine, $view) {
				$templateEngine->loadTemplateFile(__DIR__ . '/app/template/checkout-region.tpl');

				return [$template, $filename, $tplFile, $templateEngine, $view];
			}, 2000);
		}

		if ($this->settings['default_lang_redirect']) {
			$this->defaultLanguageRedirect();
		}

		if ($this->settings['canonical']) {
			$this->canonical();
		}

		if ($this->settings['app_js_fix']) {
			$this->appJsFix();
		}
		if (self::stockActive()) {
			$this->stock();
		}

		if ($this->settings['search_results_fix'] || $this->settings['shop_partial_search']) {
			$this->fulltextSearchTerms();
		}

		if ($this->settings['shop_partial_search']) {
			$this->shopPartialSearch();
		}

		if ($this->settings['search_results_fix'] || $this->settings['shop_partial_search']) {
			$this->prepareProductListsTemplate();
		}
	}

	public function __construct() {
		if (\Vvveb\isEditor()) {
			return;
		}

		$this->settings = self::settings();

		if (APP == 'admin') {
			$this->admin();
		} elseif (APP == 'app') {
			$this->app();
		}
	}
}

/*
 * A products list that loops and counts like the plain list of products, and also answers the
 * component values (count, search, limit, ...) that theme conditions read from it. Product keys
 * are product ids (numbers), so they never clash with the component value names.
 */
class EzywebAddonsProductList extends ArrayObject {
	private array $meta;

	public function __construct(array $items, array $meta) {
		parent::__construct($items);
		$this->meta = $meta;
	}

	public function offsetExists(mixed $key): bool {
		return parent::offsetExists($key) || (is_string($key) && array_key_exists($key, $this->meta));
	}

	public function offsetGet(mixed $key): mixed {
		if (parent::offsetExists($key)) {
			return parent::offsetGet($key);
		}

		return is_string($key) ? ($this->meta[$key] ?? null) : null;
	}
}

$ezywebAddons = new EzywebAddons();
