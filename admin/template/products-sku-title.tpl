// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

[data-v-products] [data-v-product] td.product > a[data-v-product-url]|after = <?php if (($product['sku'] ?? '') !== '') echo '<div class="ezy-sku text-muted small">SKU: ' . htmlspecialchars((string)$product['sku']) . '</div>';?>
