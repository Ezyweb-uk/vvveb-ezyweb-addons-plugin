// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

[data-v-products] thead th[style="min-width:320px;"]|before = <?php echo '<th class="ezy-sku-col" style="width:140px;"><svg class="svg-icon" aria-hidden="true"><use href="' . PUBLIC_PATH . 'admin/default/img/icons.svg#barcode"></use></svg> <span>SKU</span></th>';?>

[data-v-products] [data-v-product] td.img|after = <?php echo '<td class="ezy-sku-col text-nowrap">' . htmlspecialchars((string)($product['sku'] ?? '')) . '</td>';?>
