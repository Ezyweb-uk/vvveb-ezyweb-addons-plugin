// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

head > link[rel="canonical"]|href = <?php
	$_ezyScheme = \Vvveb\isSecure() ? 'https' : 'http';
	$_ezyPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
	echo htmlspecialchars($_ezyScheme . ':' . '/' . '/' . ($_SERVER['HTTP_HOST'] ?? '') . $_ezyPath, ENT_QUOTES);
?>
