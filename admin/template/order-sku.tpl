// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

// SKU line under the product name in the admin order products table
// a rule value must be ONE <?php ?> block, so the HTML is echoed
[data-v-order] [data-v-order-product] .price|before = <?php if (!empty($product['sku'])) echo '<div class="sku text-muted small">SKU: ' . Vvveb\escHtml($product['sku']) . '</div>'; ?>
