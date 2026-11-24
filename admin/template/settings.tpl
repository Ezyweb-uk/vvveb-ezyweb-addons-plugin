// Ezyweb Addons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

import(common.tpl)

// number fields
[data-v-setting]|value = <?php echo htmlspecialchars((string)($this->settings['@@__data-v-setting__@@'] ?? ''));?>

// radio choices, checked when the saved value matches the radio's value
[data-v-setting-radio]|addNewAttribute = <?php if ((string)($this->settings['@@__data-v-setting-radio__@@'] ?? '') === '@@__value__@@') echo 'checked';?>

// on/off switches
[data-v-setting-check]|addNewAttribute = <?php if (!empty($this->settings['@@__data-v-setting-check__@@'])) echo 'checked';?>

// stock statuses that count as out of stock, one checkbox each
#ezy-stock-statuses = <?php foreach (($this->stockStatuses ?? []) as $_ezyId => $_ezyName) { echo '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ezy-stock-status-' . (int)$_ezyId . '" name="settings[stock_block_statuses][]" value="' . (int)$_ezyId . '"' . (in_array((int)$_ezyId, $this->stockBlocked ?? [], true) ? ' checked' : '') . '><label class="form-check-label" for="ezy-stock-status-' . (int)$_ezyId . '">' . htmlspecialchars($_ezyName) . '</label></div>'; }?>
