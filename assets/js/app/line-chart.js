// Small SVG line chart for replayed measurements (no library).
// - Draws only the points it is given; never fills gaps or smooths values away.
// - New points can be appended without rebuilding the component.
// - Hover/focus shows the exact value and time of the nearest real point.

// The chart itself always reads left → right (time axis), also in Arabic; only its texts change.
import { t, LOCALE, unitLabel } from './api.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const PADDING = { top: 16, right: 16, bottom: 26, left: 40 };
const MAX_DRAWN_POINTS = 1200;

export class LineChart {
    constructor(container, { unit = '', height = 220 } = {}) {
        this.container = container;
        this.unit = unit;
        this.height = height;
        this.points = [];
        this.band = null;

        this.svg = document.createElementNS(SVG_NS, 'svg');
        this.svg.classList.add('line-chart');
        this.svg.setAttribute('role', 'img');
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'chart-tooltip';
        this.tooltip.hidden = true;

        container.replaceChildren(this.svg, this.tooltip);
        this.svg.addEventListener('pointermove', (event) => this.showNearest(event));
        this.svg.addEventListener('pointerleave', () => this.hideTooltip());

        new ResizeObserver(() => this.render()).observe(container);
    }

    setData(points, { unit, green } = {}) {
        this.points = points.slice();
        if (unit !== undefined) this.unit = unit;
        this.band = green ?? this.band;
        this.render();
    }

    append(points) {
        if (!points.length) return;
        this.points.push(...points);
        this.render();
    }

    render() {
        const width = this.container.clientWidth || 600;
        const { height } = this;
        this.svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        this.svg.replaceChildren();
        this.width = width;

        if (!this.points.length) {
            this.svg.setAttribute('aria-label', t('chart.empty'));
            return;
        }

        // Scales
        const values = this.points.map((p) => p.value);
        let min = Math.min(...values);
        let max = Math.max(...values);
        if (this.band) { min = Math.min(min, this.band[0]); max = Math.max(max, this.band[1]); }
        const step = niceStep((max - min) / 3 || 5);
        min = Math.floor(min / step) * step;
        max = Math.ceil(max / step) * step;
        if (max === min) max = min + step;

        const t0 = new Date(this.points[0].t).getTime();
        const t1 = new Date(this.points[this.points.length - 1].t).getTime();
        const span = Math.max(1, t1 - t0);
        const innerW = width - PADDING.left - PADDING.right;
        const innerH = height - PADDING.top - PADDING.bottom;
        this.x = (time) => PADDING.left + ((new Date(time).getTime() - t0) / span) * innerW;
        this.y = (v) => PADDING.top + (1 - (v - min) / (max - min)) * innerH;

        // Monitoring range band (from the configured thresholds)
        if (this.band) {
            const top = this.y(Math.min(this.band[1], max));
            const bottom = this.y(Math.max(this.band[0], min));
            this.svg.append(el('rect', { class: 'chart-band', x: PADDING.left, y: top, width: innerW, height: Math.max(0, bottom - top) }));
        }

        // Grid + y labels
        for (let v = min; v <= max + 1e-9; v += step) {
            const y = this.y(v);
            this.svg.append(el('line', { class: 'chart-grid', x1: PADDING.left, x2: width - PADDING.right, y1: y, y2: y }));
            this.svg.append(el('text', { class: 'chart-label', x: PADDING.left - 8, y: y + 4, 'text-anchor': 'end' }, formatAxis(v)));
        }

        // X labels: start and end time of the data shown
        const timeLabel = (time) => new Date(time).toLocaleTimeString(LOCALE, { hour: 'numeric', minute: '2-digit' });
        this.svg.append(el('text', { class: 'chart-label', x: PADDING.left, y: height - 6 }, timeLabel(this.points[0].t)));
        this.svg.append(el('text', { class: 'chart-label', x: width - PADDING.right, y: height - 6, 'text-anchor': 'end' }, timeLabel(this.points[this.points.length - 1].t)));

        // Line + area. Long series are thinned for drawing only (hover still uses every point).
        const stride = Math.ceil(this.points.length / MAX_DRAWN_POINTS);
        const drawn = this.points.filter((_, index) => index % stride === 0 || index === this.points.length - 1);
        const segments = splitAtGaps(drawn, 3000 * stride);

        const gradientId = `chart-gradient-${Math.random().toString(36).slice(2, 8)}`;
        const defs = el('defs');
        const gradient = el('linearGradient', { id: gradientId, x1: 0, x2: 0, y1: 0, y2: 1 });
        gradient.append(el('stop', { offset: '0%', class: 'chart-gradient-top' }), el('stop', { offset: '100%', class: 'chart-gradient-bottom' }));
        defs.append(gradient);
        this.svg.append(defs);

        segments.forEach((segment) => {
            const line = segment.map((p, i) => `${i ? 'L' : 'M'}${this.x(p.t).toFixed(1)},${this.y(p.value).toFixed(1)}`).join('');
            const baseY = this.y(min);
            const area = `${line}L${this.x(segment[segment.length - 1].t).toFixed(1)},${baseY}L${this.x(segment[0].t).toFixed(1)},${baseY}Z`;
            this.svg.append(el('path', { class: 'chart-area', d: area, fill: `url(#${gradientId})` }));
            this.svg.append(el('path', { class: 'chart-line', d: line }));
        });

        const last = this.points[this.points.length - 1];
        this.svg.setAttribute('aria-label', t('chart.summary', {
            n: this.points.length, min: formatAxis(Math.min(...values)), max: formatAxis(Math.max(...values)),
            latest: formatAxis(last.value), unit: unitLabel(this.unit),
        }));

        this.marker = el('circle', { class: 'chart-marker', r: 5, cx: -10, cy: -10 });
        this.svg.append(this.marker);
    }

    showNearest(event) {
        if (!this.points.length) return;
        const rect = this.svg.getBoundingClientRect();
        const px = ((event.clientX - rect.left) / rect.width) * this.width;
        let nearest = this.points[0];
        let best = Infinity;
        for (const point of this.points) {
            const distance = Math.abs(this.x(point.t) - px);
            if (distance < best) { best = distance; nearest = point; }
        }
        const cx = this.x(nearest.t);
        const cy = this.y(nearest.value);
        this.marker.setAttribute('cx', cx);
        this.marker.setAttribute('cy', cy);
        this.marker.classList.add('is-visible');

        this.tooltip.hidden = false;
        this.tooltip.innerHTML = `<strong>${formatAxis(nearest.value)} ${escapeText(unitLabel(this.unit))}</strong><span>${new Date(nearest.t).toLocaleTimeString(LOCALE)} · ${escapeText(t('dashboard.rec_offset', { time: formatClock(nearest.offset) }))}</span>`;
        this.tooltip.style.left = `${(cx / this.width) * 100}%`;
        this.tooltip.style.top = `${(cy / this.height) * 100}%`;
    }

    hideTooltip() {
        this.tooltip.hidden = true;
        this.marker?.classList.remove('is-visible');
    }
}

function escapeText(value) {
    return String(value).replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[char]));
}

function el(name, attributes = {}, text) {
    const node = document.createElementNS(SVG_NS, name);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
    if (text !== undefined) node.textContent = text;
    return node;
}

// Breaks the line wherever samples are further apart than expected (missing seconds in the
// recording, or a replay restart), so missing data is never bridged by a drawn line.
function splitAtGaps(points, maxGapMs) {
    const segments = [[]];
    points.forEach((point, index) => {
        if (index && new Date(point.t) - new Date(points[index - 1].t) > maxGapMs) segments.push([]);
        segments[segments.length - 1].push(point);
    });
    return segments.filter((segment) => segment.length);
}

function niceStep(raw) {
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalized = raw / magnitude;
    return (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10) * magnitude;
}

function formatAxis(value) {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function formatClock(seconds) {
    const total = Math.floor(seconds ?? 0);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
