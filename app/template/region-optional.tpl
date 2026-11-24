// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

/* Region only required when the country has regions (ezyweb_addons region_optional).
   Adds public/region-optional.js on pages with a checkout region dropdown. */

body|append = <?php echo \EzywebAddons::regionOptionalFooter();?>
