// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

head > link[rel="canonical"]|outerHTML = <?php
	$_ezyCanonical = $this->seo['canonical'] ?? '';

	if (! $_ezyCanonical) {
		$_ezyScheme    = \Vvveb\isSecure() ? 'https' : 'http';
		$_ezyPath      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$_ezyCanonical = $_ezyScheme . ':' . '/' . '/' . ($_SERVER['HTTP_HOST'] ?? '') . $_ezyPath;
	}

	echo '<link rel="canonical" href="' . htmlspecialchars((string) $_ezyCanonical, ENT_QUOTES) . '">';

	if (isset($this->seo) && is_array($this->seo)) {
		$this->seo['canonical'] = '';
	}
?>
