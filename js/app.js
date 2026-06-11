// UnD – main application module
// Handles county dropdown, filters, chart rendering, and data/SVG exports.

import { renderBar, renderLine, renderPie } from './charts.js';
import { renderMap } from './map.js';

// ── Module state ──────────────────────────────────────────────
let geojsonData = null;       // cached Romania GeoJSON (loaded once)
let allCounties = [];         // [{code, name}] from API
let excludedSet = new Set();  // county codes to exclude; empty = show all

// ── GeoJSON ───────────────────────────────────────────────────
async function loadGeoJSON() {
  const res = await fetch('data/ro-counties.geojson');
  geojsonData = await res.json();
}

// ── County dropdown ───────────────────────────────────────────
function buildCountyList(filter = '') {
  const list  = document.getElementById('county-list');
  const lower = filter.toLowerCase();
  list.innerHTML = '';

  allCounties
    .filter(c => !lower || c.name.toLowerCase().includes(lower) || c.code.toLowerCase().includes(lower))
    .forEach(c => {
      const lbl = document.createElement('label');
      lbl.className = 'county-item';

      const cb = document.createElement('input');
      cb.type    = 'checkbox';
      cb.value   = c.code;
      cb.checked = !excludedSet.has(c.code);
      cb.addEventListener('change', () => {
        if (cb.checked) excludedSet.delete(c.code);
        else            excludedSet.add(c.code);
        // if every county is unchecked, reset to "all"
        if (excludedSet.size === allCounties.length) excludedSet.clear();
        updateCountySummary();
      });

      lbl.append(cb, ` ${c.code} – ${c.name}`);
      list.appendChild(lbl);
    });
}

function updateCountySummary() {
  const el    = document.getElementById('county-summary');
  const shown = allCounties.length - excludedSet.size;
  if (excludedSet.size === 0)
    el.textContent = 'Toate județele';
  else if (shown <= 3)
    el.textContent = allCounties.filter(c => !excludedSet.has(c.code)).map(c => c.code).join(', ');
  else
    el.textContent = `${shown} județe selectate`;
}

async function populateCounties() {
  const json = await fetch('api/data.php?group_by=county').then(r => r.json());
  allCounties = json.counties || [];
  buildCountyList();
}

// Dropdown open / close
const trigger = document.getElementById('county-trigger');
const panel   = document.getElementById('county-panel');

trigger.addEventListener('click', () => {
  const willOpen = panel.style.display === 'none';
  panel.style.display = willOpen ? 'block' : 'none';
  if (willOpen) document.getElementById('county-search').focus();
});

// Close when clicking outside the dropdown
document.addEventListener('click', e => {
  if (!document.getElementById('county-dropdown').contains(e.target))
    panel.style.display = 'none';
});

document.getElementById('county-search').addEventListener('input', e => buildCountyList(e.target.value));

document.getElementById('county-all').addEventListener('click', () => {
  excludedSet.clear();
  buildCountyList(document.getElementById('county-search').value);
  updateCountySummary();
});

document.getElementById('county-none').addEventListener('click', () => {
  allCounties.forEach(c => excludedSet.add(c.code));
  buildCountyList(document.getElementById('county-search').value);
  updateCountySummary();
});

// ── Filters ───────────────────────────────────────────────────
function checkedValues(groupId) {
  return Array.from(document.querySelectorAll(`#${groupId} input:checked`)).map(i => i.value);
}

function getFilters() {
  // excludedSet holds county codes to hide; convert to an "include" list for the API
  const county = excludedSet.size === 0
    ? ''
    : allCounties.filter(c => !excludedSet.has(c.code)).map(c => c.code).join(',');

  return {
    county,
    age:        checkedValues('fg-age').join(','),
    edu:        checkedValues('fg-edu').join(','),
    env:        checkedValues('fg-env').join(','),
    year_from:  document.getElementById('f-year-from').value,
    month_from: document.getElementById('f-month-from').value,
    year_to:    document.getElementById('f-year-to').value,
    month_to:   document.getElementById('f-month-to').value,
  };
}

function buildQuery(extra = {}) {
  const params = { ...getFilters(), ...extra };
  // Strip empty values so the URL stays clean
  return new URLSearchParams(
    Object.fromEntries(Object.entries(params).filter(([, v]) => v !== ''))
  ).toString();
}

// ── Charts ────────────────────────────────────────────────────
function setLoading(...ids) {
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '<div class="state-msg">Se încarcă...</div>';
  });
}

async function updateCharts() {
  setLoading('chart-bar', 'chart-line', 'chart-pie');
  document.getElementById('map').innerHTML =
    '<div class="state-msg" style="height:100%;display:flex;align-items:center;justify-content:center">Se încarcă harta...</div>';

  const pieGroup = document.getElementById('f-group-pie').value;
  const pieLabel = document.getElementById('f-group-pie').selectedOptions[0]?.text || '';

  const [byCounty, byMonth, byGroup] = await Promise.all([
    fetch(`api/data.php?${buildQuery({ group_by: 'county'  })}`).then(r => r.json()),
    fetch(`api/data.php?${buildQuery({ group_by: 'month'   })}`).then(r => r.json()),
    fetch(`api/data.php?${buildQuery({ group_by: pieGroup  })}`).then(r => r.json()),
  ]);

  renderBar('chart-bar',   byCounty.data);
  renderLine('chart-line', byMonth.data);
  renderPie('chart-pie',   byGroup.data, { title: pieLabel });

  if (!geojsonData) await loadGeoJSON();

  // Build county-code → avg_value map for the choropleth
  const countyValues = {};
  (byCounty.counties || []).forEach(meta => {
    const row = byCounty.data.find(d => d.label === meta.name);
    if (row) countyValues[meta.code] = +row.avg_value;
  });
  renderMap(geojsonData, countyValues);
}

// ── Exports ───────────────────────────────────────────────────
function exportSVG(containerId, filename) {
  const svgEl = document.querySelector(`#${containerId} svg`);
  if (!svgEl) { alert('Graficul nu este disponibil.'); return; }

  let src = new XMLSerializer().serializeToString(svgEl);
  if (!src.includes('xmlns='))
    src = src.replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"');

  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(new Blob([src], { type: 'image/svg+xml;charset=utf-8' }));
  a.download = filename;
  a.click();
}

// ── Event wiring ──────────────────────────────────────────────
document.getElementById('btn-apply').addEventListener('click', updateCharts);
document.getElementById('f-group-pie').addEventListener('change', updateCharts);

document.getElementById('btn-export-csv')
  .addEventListener('click', () => window.open('api/export.php?' + buildQuery({ format: 'csv' })));
document.getElementById('btn-export-json')
  .addEventListener('click', () => window.open('api/export.php?' + buildQuery({ format: 'json' })));

document.getElementById('btn-export-bar-svg')
  .addEventListener('click', () => exportSVG('chart-bar',  'somaj-judete.svg'));
document.getElementById('btn-export-line-svg')
  .addEventListener('click', () => exportSVG('chart-line', 'somaj-timp.svg'));
document.getElementById('btn-export-pie-svg')
  .addEventListener('click', () => exportSVG('chart-pie',  'somaj-distributie.svg'));
document.getElementById('btn-export-map-svg')
  .addEventListener('click', () => exportSVG('map',        'somaj-harta.svg'));
document.getElementById('btn-export-pdf')
  .addEventListener('click', () => window.print());

// ── Bootstrap ─────────────────────────────────────────────────
await populateCounties();
await updateCharts();
