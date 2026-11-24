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

namespace Vvveb\Plugins\EzywebAddons\Controller;

use function Vvveb\__;
use Vvveb\Controller\Base;
use function Vvveb\setSetting;
use Vvveb\System\CacheManager;
use Vvveb\System\Db;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

class Settings extends Base {
	private $namespace = 'ezyweb_addons';

	public function save() {
		$post = $this->request->post['settings'] ?? [];

		if (is_array($post)) {
			$settings = [
				'order_sku'            => empty($post['order_sku']) ? 0 : 1,
				'admin_products_limit' => max(0, min(500, (int)($post['admin_products_limit'] ?? 0))),
				'shop_products_limit'  => max(0, min(100, (int)($post['shop_products_limit'] ?? 0))),
				'default_lang_redirect' => empty($post['default_lang_redirect']) ? 0 : 1,
				'canonical'             => empty($post['canonical']) ? 0 : 1,
				'admin_products_sku'    => max(0, min(2, (int)($post['admin_products_sku'] ?? 0))),
				'app_js_fix'            => empty($post['app_js_fix']) ? 0 : 1,
				'stock_block'           => empty($post['stock_block']) ? 0 : 1,
				'stock_block_statuses'  => implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($post['stock_block_statuses'] ?? [])))))),
				'block_offline'         => empty($post['block_offline']) ? 0 : 1,
				'admin_partial_codes'   => empty($post['admin_partial_codes']) ? 0 : 1,
				'admin_partial_search'  => empty($post['admin_partial_search']) ? 0 : 1,
				'search_results_fix'    => empty($post['search_results_fix']) ? 0 : 1,
				'shop_partial_search'   => empty($post['shop_partial_search']) ? 0 : 1,
				'subcategory_products'  => empty($post['subcategory_products']) ? 0 : 1,
				'checkout_region_unselected' => empty($post['checkout_region_unselected']) ? 0 : 1,
				'region_optional'       => empty($post['region_optional']) ? 0 : 1,
				'alphabetical_locations' => empty($post['alphabetical_locations']) ? 0 : 1,
				'edit_save_fix'         => empty($post['edit_save_fix']) ? 0 : 1,
				'admin_access_guard'    => empty($post['admin_access_guard']) ? 0 : 1,
			];

			foreach ($settings as $key => $value) {
				setSetting($this->namespace, $key, $value, $this->global['site_id']);
			}

			//shop listings are page cached
			CacheManager::delete();
			CacheManager::clearPageCache();

			$this->view->success[] = __('Settings saved!');
		}

		return $this->index();
	}

	/* stock statuses for the "count as out of stock" checkboxes, in the admin language */
	private function stockStatuses(): array {
		$db       = Db::getInstance();
		$prefix   = defined('DB_PREFIX') ? DB_PREFIX : '';
		$language = (int)($this->global['language_id'] ?? 0);
		$sql      = 'SELECT stock_status_id, name, language_id FROM ' . $prefix . 'stock_status ORDER BY stock_status_id';
		$result   = $db->query($sql);
		$rows     = $result ? ($db->fetchAll($result) ?: []) : [];
		$statuses = [];

		//one name per status: the admin language when there is one, otherwise the first found
		foreach ($rows as $row) {
			$id = (int)$row['stock_status_id'];

			if (! isset($statuses[$id]) || (int)$row['language_id'] === $language) {
				$statuses[$id] = (string)$row['name'];
			}
		}

		return $statuses;
	}

	public function index() {
		$this->view->settings      = \EzywebAddons::settings();
		$this->view->stockStatuses = $this->stockStatuses();
		$this->view->stockBlocked  = array_map('intval', explode(',', (string)($this->view->settings['stock_block_statuses'] ?? '')));
	}
}
