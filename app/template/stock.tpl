// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

// Ezyweb Addons: out-of-stock and disabled products (loaded on View compile:after, priority 2000)
// Note: no apostrophes or double slashes in these comments, the tpl parser reads quotes inside them

// product page: Add to cart and Buy now buttons, $product is the product of the product component
[data-v-component-product] button[data-v-product-add_cart_url]|addNewAttribute = <?php if (\EzywebAddons::unavailable($product ?? [])) echo 'disabled';?>
[data-v-component-product] button[data-v-product-buy_url]|addNewAttribute = <?php if (\EzywebAddons::unavailable($product ?? [])) echo 'disabled';?>

// product page: quantity limited to the tracked stock while blocking
[data-v-component-product] input[name="quantity"]|addNewAttribute = <?php $_ezyMax = \EzywebAddons::quantityMax($product ?? []); if ($_ezyMax > 0) echo 'max="' . $_ezyMax . '"';?>

// listings, $prod is the current product of the products component: Add to cart link or a disabled Out of stock button
[data-v-component-products] a[data-v-product-add_cart_url]|before = <?php if (!\EzywebAddons::unavailable($prod ?? [])) { ?>
[data-v-component-products] a[data-v-product-add_cart_url]|after = <?php } else { echo \EzywebAddons::outOfStockButton($prod ?? []); } ?>

// popup script and, after a blocked add to cart, the message marker
body|append = <?php echo \EzywebAddons::stockFooter($this);?>
