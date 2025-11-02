(function ($) {
  'use strict';

  function onShippingSettingsPage() {
    const url = new URL(window.location.href);
    return url.searchParams.get('tab') === 'shipping';
  }

  function stateOptionsHtml(selectedArr) {
    const states = (window.SarkkartWBS && SarkkartWBS.states) ? SarkkartWBS.states : {};
    const selected = Array.isArray(selectedArr) ? selectedArr.map(s => String(s).toUpperCase()) : [];
    return Object.keys(states).map(code => {
      const isSel = selected.includes(String(code).toUpperCase()) ? 'selected' : '';
      return `<option value="${code}" ${isSel}>${states[code]} (${code})</option>`;
    }).join('');
  }

  function buildRow(rule = {}) {
    const defaults = {
      name: '',
      min_weight: '',
      max_weight: '',
      min_subtotal: '',
      max_subtotal: '',
      min_qty: '',
      max_qty: '',
      shipping_class: 'any',
      base: '',
      per_kg: '',
      percent: '',
      states: [],     // array of codes
      postcodes: []   // array of strings/regex
    };
    const r = Object.assign({}, defaults, rule);

    const pcsCSV = Array.isArray(r.postcodes) ? r.postcodes.join(',') : (r.postcodes || '');

    const $row = $(`
      <tr>
        <td><input type="text" class="s-wbs name" value="${r.name}"></td>
        <td><input type="number" step="0.001" class="s-wbs min_weight" value="${r.min_weight}"></td>
        <td><input type="number" step="0.001" class="s-wbs max_weight" value="${r.max_weight}"></td>
        <td><input type="number" step="0.01" class="s-wbs min_subtotal" value="${r.min_subtotal}"></td>
        <td><input type="number" step="0.01" class="s-wbs max_subtotal" value="${r.max_subtotal}"></td>
        <td><input type="number" step="1" class="s-wbs min_qty" value="${r.min_qty}"></td>
        <td><input type="number" step="1" class="s-wbs max_qty" value="${r.max_qty}"></td>
        <td><input type="text" class="s-wbs shipping_class" value="${r.shipping_class}"></td>
        <td><input type="number" step="0.01" class="s-wbs base" value="${r.base}"></td>
        <td><input type="number" step="0.01" class="s-wbs per_kg" value="${r.per_kg}"></td>
        <td><input type="number" step="0.01" class="s-wbs percent" value="${r.percent}"></td>
        <td>
          <select multiple class="s-wbs states" size="5"></select>
          <div class="s-wbs-mini">Leave empty to ignore state filter.</div>
        </td>
        <td>
          <input type="text" class="s-wbs postcodes" placeholder="^11.*,^12(0|1).*" value="${pcsCSV}">
          <div class="s-wbs-mini">Comma-separated or regex patterns.</div>
        </td>
        <td><button type="button" class="button button-small s-wbs-remove">Remove</button></td>
      </tr>
    `);

    // Fill the states multi-select
    const $states = $row.find('select.s-wbs.states');
    $states.html(stateOptionsHtml(r.states));

    return $row;
  }

  function readRow($tr) {
    const rule = {};
    $tr.find('input.s-wbs').each(function () {
      const $inp = $(this);
      const classes = $inp.attr('class').split(' ');
      const key = classes[classes.length - 1];
      rule[key] = $inp.val();
    });

    // Convert numeric-looking strings to numbers where useful (but allow blanks)
    ['min_weight','max_weight','min_subtotal','max_subtotal','min_qty','max_qty','base','per_kg','percent'].forEach(k => {
      if (rule[k] === '') return;
      rule[k] = (k === 'min_qty' || k === 'max_qty') ? parseInt(rule[k], 10) : parseFloat(rule[k]);
      if (isNaN(rule[k])) rule[k] = '';
    });

    // States (multi-select)
    const states = [];
    $tr.find('select.s-wbs.states option:selected').each(function () {
      states.push($(this).val());
    });
    rule['states'] = states;

    // Postcodes (CSV -> array)
    const pcs = ($tr.find('input.s-wbs.postcodes').val() || '')
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);
    rule['postcodes'] = pcs;

    return rule;
  }

  function readTable($table) {
    const arr = [];
    $table.find('tbody tr').each(function () {
      arr.push(readRow($(this)));
    });
    return arr;
  }

  function writeJson($textarea, data) {
    $textarea.val(JSON.stringify(data, null, 2)).trigger('change');
  }

  function initTable($textarea) {
    const holder = $('<div class="s-wbs-table-wrap"></div>');
    const toolbar = $(`
      <div class="s-wbs-toolbar">
        <button type="button" class="button button-secondary s-wbs-add">Add Rule</button>
        <button type="button" class="button button-link s-wbs-add-1kg">Quick: 0–1kg ₹49</button>
        <button type="button" class="button button-link s-wbs-add-1to5kg">Quick: 1–5kg ₹79 + ₹20/kg</button>
        <span class="desc">All configurable here; the JSON field is hidden and auto-managed.</span>
      </div>
    `);

    const table = $(`
      <table class="s-wbs-table widefat">
        <thead>
          <tr>
            <th>Name</th>
            <th>Min W (kg)</th>
            <th>Max W (kg)</th>
            <th>Min Subtotal</th>
            <th>Max Subtotal</th>
            <th>Min Qty</th>
            <th>Max Qty</th>
            <th>Ship Class</th>
            <th>Base</th>
            <th>Per kg</th>
            <th>% of Subtotal</th>
            <th>States</th>
            <th>Postcodes</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    `);

    holder.append(toolbar).append(table);
    $textarea.before(holder);

    // Prefill table from JSON
    let data = [];
    try {
      data = JSON.parse($textarea.val() || '[]');
      if (!Array.isArray(data)) data = [];
    } catch (e) { data = []; }

    const $tbody = table.find('tbody');
    data.forEach(r => $tbody.append(buildRow(r)));

    const sync = () => writeJson($textarea, readTable(table));

    holder.on('click', '.s-wbs-add', function () {
      $tbody.append(buildRow());
      sync();
    });

    holder.on('click', '.s-wbs-add-1kg', function () {
      $tbody.append(buildRow({ name: '0–1kg', min_weight: 0, max_weight: 1, base: 49, per_kg: 0, percent: 0, shipping_class: 'any' }));
      sync();
    });

    holder.on('click', '.s-wbs-add-1to5kg', function () {
      $tbody.append(buildRow({ name: '1–5kg', min_weight: 1.0001, max_weight: 5, base: 79, per_kg: 20, percent: 0, shipping_class: 'any' }));
      sync();
    });

    holder.on('click', '.s-wbs-remove', function () {
      $(this).closest('tr').remove();
      sync();
    });

    holder.on('input change', 'input.s-wbs, select.s-wbs', sync);
  }

  $(function () {
    if (!onShippingSettingsPage()) return;

    // Find the hidden rules JSON textarea (Woo prefixes with instance id)
    $('textarea[name^="woocommerce_sarkkart_wbs_"][name$="[rules_json]"]').each(function () {
      initTable($(this));
    });
  });

})(jQuery);
