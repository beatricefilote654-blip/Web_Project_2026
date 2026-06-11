// Pure D3 SVG choropleth — no tiles, Romania only

export function initMap(containerId) {
  // nothing to init — renderMap handles everything
}

export function renderMap(geojsonData, countyValues, containerId = 'map') {
  const container = document.getElementById(containerId);
  container.innerHTML = '';

  const W = Math.max(container.clientWidth || 720, 320);
  const H = container.clientHeight || 420;

  const values = Object.values(countyValues).filter(v => v > 0);
  const minVal = Math.min(...values) || 0;
  const maxVal = Math.max(...values) || 10;

  // Color scale: light yellow → deep red
  const color = d3.scaleSequential()
    .domain([minVal, maxVal])
    .interpolator(d3.interpolateYlOrRd);

  const projection = d3.geoMercator().fitSize([W - 20, H - 40], geojsonData);
  const path = d3.geoPath().projection(projection);

  const svg = d3.select(container)
    .append('svg')
    .attr('viewBox', `0 0 ${W} ${H}`)
    .attr('preserveAspectRatio', 'xMidYMid meet')
    .attr('width', '100%').attr('height', '100%')
    .style('display', 'block')
    .attr('xmlns', 'http://www.w3.org/2000/svg');

  // Tooltip
  let tip = document.querySelector('.tooltip');
  if (!tip) { tip = document.createElement('div'); tip.className = 'tooltip'; document.body.appendChild(tip); }

  // Draw counties
  svg.selectAll('path')
    .data(geojsonData.features)
    .enter().append('path')
    .attr('d', path)
    .attr('fill', d => {
      const v = countyValues[d.properties.code];
      return v != null ? color(v) : '#e2e8f0';
    })
    .attr('stroke', '#fff')
    .attr('stroke-width', 1)
    .style('cursor', 'pointer')
    .on('mouseover', function(event, d) {
      d3.select(this).attr('stroke', '#334155').attr('stroke-width', 2);
      const v = countyValues[d.properties.code];
      tip.textContent = '';
      const strong = document.createElement('strong');
      strong.textContent = String(d.properties.name);
      tip.append(strong, document.createElement('br'),
                 document.createTextNode(v != null ? v.toFixed(2) + '%' : 'N/A'));
      tip.style.display = 'block';
    })
    .on('mousemove', function(event) {
      tip.style.left = (event.pageX + 14) + 'px';
      tip.style.top  = (event.pageY - 10) + 'px';
    })
    .on('mouseout', function() {
      d3.select(this).attr('stroke', '#fff').attr('stroke-width', 1);
      tip.style.display = 'none';
    });

  // County labels (only for larger counties)
  svg.selectAll('text')
    .data(geojsonData.features)
    .enter().append('text')
    .attr('transform', d => `translate(${path.centroid(d)})`)
    .attr('text-anchor', 'middle')
    .attr('dy', '.35em')
    .attr('font-size', 8)
    .attr('fill', '#334155')
    .attr('pointer-events', 'none')
    .text(d => d.properties.code);

  // Legend
  const legendW = 160, legendH = 12, steps = 8;
  const lg = svg.append('g').attr('transform', `translate(${W - legendW - 16}, ${H - 38})`);

  const defs = svg.append('defs');
  const grad = defs.append('linearGradient').attr('id', 'choropleth-grad');
  for (let i = 0; i <= steps; i++) {
    grad.append('stop')
      .attr('offset', (i / steps * 100) + '%')
      .attr('stop-color', color(minVal + (maxVal - minVal) * i / steps));
  }

  lg.append('rect')
    .attr('width', legendW).attr('height', legendH)
    .attr('rx', 3)
    .attr('fill', 'url(#choropleth-grad)')
    .attr('stroke', '#cbd5e1').attr('stroke-width', .5);

  lg.append('text').attr('x', 0).attr('y', legendH + 11).attr('font-size', 9).attr('fill', '#64748b').text(minVal.toFixed(1) + '%');
  lg.append('text').attr('x', legendW).attr('y', legendH + 11).attr('text-anchor', 'end').attr('font-size', 9).attr('fill', '#64748b').text(maxVal.toFixed(1) + '%');
  lg.append('text').attr('x', legendW / 2).attr('y', -4).attr('text-anchor', 'middle').attr('font-size', 9).attr('font-weight', '600').attr('fill', '#475569').text('Rata șomaj (%)');
}
