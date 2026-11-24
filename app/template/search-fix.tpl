// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

// Ezyweb Addons: shop search (loaded on View compile:after, priority 2000)
// Runs at the start of the body, after components are loaded and before any products list renders
body|prepend = <?php \EzywebAddons::prepareProductLists($this);?>
