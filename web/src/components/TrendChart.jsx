import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';
import { useEffect, useRef } from 'react';
import { useChartTheme } from '../lib/useChartTheme.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip);

const FONT = { family: "'IBM Plex Sans', system-ui, sans-serif", size: 12 };

/** Does any other dataset selected by `which` have a positive value on this day? */
function hasValue(ctx, which) {
  return ctx.chart.data.datasets.some((ds, d) => which(d) && (ds.data[ctx.dataIndex] ?? 0) > 0);
}

/**
 * One trend chart: stacked columns or lines over days, drawn with the house
 * mark specs (<=24px columns with a 4px rounded top, 2px surface gap between
 * stacked segments, 2px lines, hairline solid grid). The legend is HTML
 * (outside the canvas) so it uses text tokens and is readable by assistive
 * tech; the caller supplies a table view as the accessible equivalent.
 *
 * series: [{ key, label, color: 'series1'|'series2'|'rest'|'critical', values: (number|null)[] }]
 */
export function TrendChart({ kind = 'bar', labels, series, format = (v) => String(v), summary, height = 240 }) {
  const canvasRef = useRef(null);
  const chartRef = useRef(null);
  const theme = useChartTheme();

  useEffect(() => {
    const isBar = kind === 'bar';
    const datasets = series.map((s) => {
      const color = theme[s.color];
      return isBar
        ? {
            label: s.label,
            data: s.values,
            backgroundColor: color,
            // A surface-coloured bottom edge is the 2px gap to a segment below;
            // only the topmost non-empty segment of a day gets the rounded end.
            borderColor: theme.surface,
            borderWidth: (ctx) => (hasValue(ctx, (d) => d < ctx.datasetIndex) ? { bottom: 2 } : 0),
            borderSkipped: false,
            borderRadius: (ctx) => (hasValue(ctx, (d) => d > ctx.datasetIndex) ? 0 : { topLeft: 4, topRight: 4 }),
            maxBarThickness: 24,
            stack: 'days',
          }
        : {
            label: s.label,
            data: s.values,
            borderColor: color,
            backgroundColor: color,
            borderWidth: 2,
            borderCapStyle: 'round',
            borderJoinStyle: 'round',
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBorderColor: theme.surface,
            pointBorderWidth: 2,
            spanGaps: false, // a missing day is a gap, never an invented line
            tension: 0,
          };
    });

    const config = {
      type: kind,
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4 } },
        scales: {
          x: {
            stacked: isBar,
            grid: { display: false },
            border: { color: theme.grid },
            ticks: { color: theme.axis, font: FONT, maxRotation: 0, autoSkip: true, autoSkipPadding: 12 },
          },
          y: {
            stacked: isBar,
            beginAtZero: true,
            grace: '5%',
            grid: { color: theme.grid, lineWidth: 1 },
            border: { display: false },
            ticks: { color: theme.axis, font: FONT, precision: 0, maxTicksLimit: 5, callback: (v) => format(v) },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: theme.tooltip,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.textSecondary,
            bodyColor: theme.text,
            titleFont: { ...FONT, weight: '400' },
            bodyFont: { ...FONT, weight: '600' },
            padding: 10,
            usePointStyle: true,
            boxWidth: 12,
            boxHeight: 2,
            // Values lead: "12  Failed runs"; days without data say so.
            callbacks: {
              label: (ctx) => ` ${ctx.raw == null ? 'no data' : format(ctx.raw)}  ${ctx.dataset.label}`,
              labelPointStyle: () => ({ pointStyle: 'line', rotation: 0 }),
            },
          },
        },
      },
    };

    if (chartRef.current) {
      chartRef.current.data = config.data;
      chartRef.current.options = config.options;
      chartRef.current.update('none');
    } else {
      chartRef.current = new Chart(canvasRef.current, config);
    }
  }, [kind, labels, series, format, theme]);

  useEffect(
    () => () => {
      chartRef.current?.destroy();
      chartRef.current = null;
    },
    [],
  );

  return (
    <div className="trend">
      {series.length > 1 && (
        <ul className="trend__legend">
          {series.map((s) => (
            <li key={s.key}>
              <span className={`trend__key trend__key--${kind}`} style={{ background: theme[s.color] }} aria-hidden="true" />
              {s.label}
            </li>
          ))}
        </ul>
      )}
      <div className="trend__canvas" style={{ height }}>
        <canvas ref={canvasRef} role="img" aria-label={summary} />
      </div>
    </div>
  );
}
