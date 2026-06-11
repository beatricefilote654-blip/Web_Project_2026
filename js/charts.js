// D3 chart components — bar, line, pie — all render to SVG for direct export.

const COLORS = ['#2563eb','#0ea5e9','#22c55e','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6','#f97316','#84cc16'];
const getColor = i => COLORS[i % COLORS.length];

// ── Shared tooltip ────────────────────────────────────────────
function getTip() {
  let el = document.getElementById('chart-tooltip');
  if (!el) {
    el = document.createElement('div');
    el.id = 'chart-tooltip';
    el.style.cssText = [
      'position:fixed','display:none','z-index:9999','pointer-events:none',
      'background:#1e293b','color:#f8fafc','border-radius:7px',
      'padding:7px 12px','font-size:13px','line-height:1.5',
      'box-shadow:0 4px 16px rgba(0,0,0,.25)',
      'max-width:200px','white-space:nowrap'
    ].join(';');
    document.body.appendChild(el);
  }
  return el;
}

function showTip(event, label, value) {
  const tip = getTip();
  tip.innerHTML = `<div style="font-weight:700;margin-bottom:2px">${label}</div><div style="color:#94a3b8;font-size:12px">Rata: <span style="color:#60a5fa;font-weight:600">${(+value).toFixed(2)}%</span></div>`;
  tip.style.display = 'block';
  moveTip(event);
}

function moveTip(event) {
  const tip = getTip();
  const x = event.clientX + 14;
  const y = event.clientY - 10;
  const tipW = tip.offsetWidth, tipH = tip.offsetHeight;
  tip.style.left = Math.max(8, x + tipW > window.innerWidth ? event.clientX - tipW - 10 : x) + 'px';
  tip.style.top  = Math.max(8, y + tipH > window.innerHeight ? event.clientY - tipH - 4 : y) + 'px';
}

function hideTip() { getTip().style.display = 'none'; }

// ── Bar chart ─────────────────────────────────────────────────
export function renderBar(containerId, data) {
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if (!data?.length) { container.innerHTML = '<div class="state-msg">Fără date</div>'; return; }

  const W = container.clientWidth || 480, H = 280;
  const m = { top: 12, right: 16, bottom: 72, left: 46 };
  const iW = W - m.left - m.right, iH = H - m.top - m.bottom;

  const svg = d3.select(container).append('svg').attr('width', W).attr('height', H).attr('xmlns', 'http://www.w3.org/2000/svg');
  const g = svg.append('g').attr('transform', `translate(${m.left},${m.top})`);

  const x = d3.scaleBand().domain(data.map(d => d.label)).range([0, iW]).padding(0.22);
  const y = d3.scaleLinear().domain([0, d3.max(data, d => +d.avg_value) * 1.12]).range([iH, 0]);

  // Grid lines
  g.append('g').attr('class', 'grid')
    .call(d3.axisLeft(y).ticks(5).tickSize(-iW).tickFormat(''))
    .call(g => { g.select('.domain').remove(); g.selectAll('line').attr('stroke', '#e2e8f0'); });

  // X axis
  g.append('g').attr('transform', `translate(0,${iH})`)
    .call(d3.axisBottom(x).tickSize(0))
    .call(g => g.select('.domain').attr('stroke', '#e2e8f0'))
    .selectAll('text')
      .attr('transform', 'rotate(-38)')
      .attr('text-anchor', 'end')
      .attr('font-size', 9)
      .attr('fill', '#64748b')
      .attr('dy', '0.3em')
      .attr('dx', '-0.4em');

  // Y axis
  g.append('g')
    .call(d3.axisLeft(y).ticks(5).tickFormat(d => d + '%'))
    .call(g => { g.select('.domain').remove(); g.selectAll('text').attr('font-size', 10).attr('fill', '#94a3b8'); });

  // Bars
  g.selectAll('.bar').data(data).enter().append('rect')
    .attr('x', d => x(d.label))
    .attr('y', d => y(+d.avg_value))
    .attr('width', x.bandwidth())
    .attr('height', d => iH - y(+d.avg_value))
    .attr('fill', (_, i) => getColor(i))
    .attr('rx', 3)
    .style('transition', 'opacity .15s')
    .on('mouseover', function(event, d) {
      d3.select(this).style('opacity', 0.75);
      showTip(event, d.label, d.avg_value);
    })
    .on('mousemove', moveTip)
    .on('mouseout', function() { d3.select(this).style('opacity', 1); hideTip(); });
}

// ── Line chart ────────────────────────────────────────────────
export function renderLine(containerId, data) {
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if (!data?.length) { container.innerHTML = '<div class="state-msg">Fără date</div>'; return; }

  const W = container.clientWidth || 480, H = 280;
  const m = { top: 12, right: 16, bottom: 44, left: 46 };
  const iW = W - m.left - m.right, iH = H - m.top - m.bottom;

  const svg = d3.select(container).append('svg').attr('width', W).attr('height', H).attr('xmlns', 'http://www.w3.org/2000/svg');
  const g = svg.append('g').attr('transform', `translate(${m.left},${m.top})`);

  const x = d3.scalePoint().domain(data.map(d => d.label)).range([0, iW]);
  const y = d3.scaleLinear().domain([0, d3.max(data, d => +d.avg_value) * 1.12]).range([iH, 0]);

  // Grid
  g.append('g')
    .call(d3.axisLeft(y).ticks(5).tickSize(-iW).tickFormat(''))
    .call(g => { g.select('.domain').remove(); g.selectAll('line').attr('stroke', '#e2e8f0'); });

  // Area fill
  const area = d3.area().x(d => x(d.label)).y0(iH).y1(d => y(+d.avg_value)).curve(d3.curveMonotoneX);
  g.append('path').datum(data)
    .attr('fill', COLORS[0]).attr('fill-opacity', 0.08)
    .attr('d', area);

  // Line
  const line = d3.line().x(d => x(d.label)).y(d => y(+d.avg_value)).curve(d3.curveMonotoneX);
  g.append('path').datum(data)
    .attr('fill', 'none').attr('stroke', COLORS[0]).attr('stroke-width', 2.5)
    .attr('d', line);

  // X axis
  g.append('g').attr('transform', `translate(0,${iH})`)
    .call(d3.axisBottom(x).tickSize(0))
    .call(g => g.select('.domain').attr('stroke', '#e2e8f0'))
    .selectAll('text')
      .attr('transform', 'rotate(-28)')
      .attr('text-anchor', 'end')
      .attr('font-size', 9)
      .attr('fill', '#64748b');

  // Y axis
  g.append('g')
    .call(d3.axisLeft(y).ticks(5).tickFormat(d => d + '%'))
    .call(g => { g.select('.domain').remove(); g.selectAll('text').attr('font-size', 10).attr('fill', '#94a3b8'); });

  // Invisible hover rects for easier targeting
  const bisect = d3.bisector(d => d.label).left;
  const focus = g.append('g').style('display', 'none');
  focus.append('line').attr('class', 'focus-line')
    .attr('stroke', '#94a3b8').attr('stroke-dasharray', '4,3').attr('stroke-width', 1).attr('y1', 0).attr('y2', iH);
  focus.append('circle').attr('r', 5).attr('fill', COLORS[0]).attr('stroke', '#fff').attr('stroke-width', 2);

  svg.append('rect')
    .attr('transform', `translate(${m.left},${m.top})`)
    .attr('width', iW).attr('height', iH)
    .attr('fill', 'none').attr('pointer-events', 'all')
    .on('mousemove', function(event) {
      const [mx] = d3.pointer(event);
      const labels = data.map(d => d.label);
      const xPos = x.range()[0];
      const step = iW / (labels.length - 1);
      const idx = Math.max(0, Math.min(labels.length - 1, Math.round(mx / step)));
      const d = data[idx];
      if (!d) return;
      focus.style('display', null);
      focus.select('.focus-line').attr('x1', x(d.label)).attr('x2', x(d.label));
      focus.select('circle').attr('cx', x(d.label)).attr('cy', y(+d.avg_value));
      showTip(event, d.label, d.avg_value);
    })
    .on('mouseout', () => { focus.style('display', 'none'); hideTip(); });

  // Dots
  g.selectAll('.dot').data(data).enter().append('circle')
    .attr('cx', d => x(d.label)).attr('cy', d => y(+d.avg_value))
    .attr('r', 3).attr('fill', COLORS[0]).attr('stroke', '#fff').attr('stroke-width', 1.5)
    .attr('pointer-events', 'none');
}

// ── Pie / donut chart ─────────────────────────────────────────
export function renderPie(containerId, data, { title = '' } = {}) {
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if (!data?.length) { container.innerHTML = '<div class="state-msg">Fără date</div>'; return; }

  const W = container.clientWidth || 480, H = 280;
  const R = Math.min(W * 0.45, H) / 2 - 16;

  const svg = d3.select(container).append('svg').attr('width', W).attr('height', H).attr('xmlns', 'http://www.w3.org/2000/svg');
  const g = svg.append('g').attr('transform', `translate(${W * 0.38},${H / 2})`);

  const pie   = d3.pie().value(d => +d.avg_value).sort(null);
  const arc   = d3.arc().innerRadius(R * 0.52).outerRadius(R);
  const arcHover = d3.arc().innerRadius(R * 0.52).outerRadius(R + 6);

  if (title) {
    g.append('text').attr('text-anchor', 'middle').attr('dy', '-0.15em').attr('font-size', 12).attr('font-weight', '700').attr('fill', '#1e293b').text(title);
    g.append('text').attr('text-anchor', 'middle').attr('dy', '1.1em').attr('font-size', 11).attr('fill', '#94a3b8')
      .text((() => { const total = d3.sum(data, d => +d.avg_value); return total.toFixed(1) + '%'; })());
  }

  g.selectAll('.arc').data(pie(data)).enter().append('path')
    .attr('d', arc)
    .attr('fill', (_, i) => getColor(i))
    .attr('stroke', '#fff').attr('stroke-width', 2)
    .style('transition', 'd .15s')
    .on('mouseover', function(event, d) {
      d3.select(this).attr('d', arcHover(d));
      showTip(event, d.data.label, d.data.avg_value);
    })
    .on('mousemove', moveTip)
    .on('mouseout', function(event, d) {
      d3.select(this).attr('d', arc(d));
      hideTip();
    });

  // Legend
  const legend = svg.append('g').attr('transform', `translate(${W * 0.68}, ${H / 2 - data.length * 11})`);
  data.forEach((d, i) => {
    const row = legend.append('g').attr('transform', `translate(0,${i * 22})`);
    row.append('rect').attr('width', 10).attr('height', 10).attr('rx', 2).attr('fill', getColor(i));
    row.append('text').attr('x', 15).attr('y', 9).attr('font-size', 11).attr('fill', '#475569').text(d.label);
  });
}
