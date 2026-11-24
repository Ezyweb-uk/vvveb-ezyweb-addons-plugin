// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

/* Checkout billing and shipping regions start unselected (ezyweb_addons checkout_region_unselected).
   Core fills billing_region_id and shipping_region_id with the site address region when the customer has
   not chosen one, and its region rule selects that. These rules run after the core rule (they are inserted
   later, closer to the select) and set the selected region to the one the customer posted, or the one
   saved in their checkout session (billing_address or shipping_address region_id), or none, so the theme
   placeholder shows. The theme placeholder option needs the selected attribute. */

#billing_region_id|before = <?php
	$_ezyCheckout = \Vvveb\System\Session::getInstance()->get('checkout');
	$region_id    = $_POST['billing_address']['region_id'] ?? ($_ezyCheckout['billing_address']['region_id'] ?? false);
?>

#shipping_region_id|before = <?php
	$_ezyCheckout = \Vvveb\System\Session::getInstance()->get('checkout');
	$region_id    = $_POST['shipping_address']['region_id'] ?? ($_ezyCheckout['shipping_address']['region_id'] ?? false);
?>
