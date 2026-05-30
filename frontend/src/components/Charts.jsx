import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Filler,
  Tooltip,
} from 'chart.js'
import { Line, Bar } from 'react-chartjs-2'
import { useLocale } from '../i18n'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip)

ChartJS.defaults.font.family = "'JetBrains Mono', monospace"
ChartJS.defaults.font.size   = 11

const RANGE_DAYS = [30, 90, 180, 365, 1095, 1825, 3650, 0]

const RANGE_LABEL_KEY = {
  30:   'charts.range30',
  90:   'charts.range90',
  180:  'charts.range180',
  365:  'charts.range365',
  1095: 'charts.range3y',
  1825: 'charts.range5y',
  3650: 'charts.range10y',
  0:    'charts.rangeAll',
}

export default function Charts({ stats, currentDays, currentView, onDaysChange, onViewChange, theme }) {
  const { t } = useLocale()

  // Theme-aware palette
  const C = theme === 'light'
    ? { green: '#006e48', red: '#bf1f3c', blue: '#025fa0', gridLine: '#ccd9e8', tickColor: '#4a6a86', tooltipBg: '#ffffff' }
    : { green: '#2dffb0', red: '#ff5572', blue: '#60cdff', gridLine: '#1e2e40', tickColor: '#7a9ab8', tooltipBg: '#162030' }

  const labels  = stats.map((r) => r.stat_date)
  const totals  = stats.map((r) => r.total)
  const added   = stats.map((r) => r.display_added)
  const removed = stats.map((r) => -r.display_removed)

  const totalData = {
    labels,
    datasets: [{
      label: t('charts.totalTitle'),
      data: totals,
      borderColor: C.blue,
      backgroundColor: theme === 'light' ? 'rgba(2,95,160,.08)' : 'rgba(96,205,255,.08)',
      borderWidth: 1.5,
      pointRadius: 0,
      pointHoverRadius: 4,
      fill: true,
      tension: 0.3,
    }],
  }

  const totalOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        mode: 'index', intersect: false,
        backgroundColor: C.tooltipBg,
        titleColor: C.tickColor,
        bodyColor: C.blue,
        borderColor: C.gridLine,
        borderWidth: 1,
      },
    },
    scales: {
      x: {
        grid: { color: C.gridLine },
        ticks: { maxTicksLimit: 10, color: C.tickColor },
      },
      y: {
        grid: { color: C.gridLine },
        ticks: { callback: (v) => String(v), color: C.tickColor },
      },
    },
  }

  const deltaData = {
    labels,
    datasets: [
      {
        label: t('charts.datasetAdded'),
        data: added,
        backgroundColor: theme === 'light' ? 'rgba(0,110,72,.55)' : 'rgba(45,255,176,.55)',
        borderWidth: 0,
        borderRadius: { topLeft: 2, topRight: 2, bottomLeft: 0, bottomRight: 0 },
        stack: 'same',
        barPercentage: 0.8,
        categoryPercentage: 0.9,
      },
      {
        label: t('charts.datasetRemoved'),
        data: removed,
        backgroundColor: theme === 'light' ? 'rgba(191,31,60,.55)' : 'rgba(255,85,114,.55)',
        borderWidth: 0,
        borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 2, bottomRight: 2 },
        stack: 'same',
        barPercentage: 0.8,
        categoryPercentage: 0.9,
      },
    ],
  }

  const deltaOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        mode: 'index',
        intersect: false,
        backgroundColor: C.tooltipBg,
        titleColor: C.tickColor,
        bodyColor: C.tickColor,
        borderColor: C.gridLine,
        borderWidth: 1,
        callbacks: { label: (ctx) => ctx.dataset.label + ': ' + Math.abs(ctx.raw) },
      },
    },
    scales: {
      x: { grid: { color: C.gridLine }, ticks: { maxTicksLimit: 10, color: C.tickColor }, stacked: true },
      y: { grid: { color: C.gridLine }, ticks: { callback: (v) => Math.abs(v), color: C.tickColor }, stacked: false },
    },
  }

  const rangeLabelKey = RANGE_LABEL_KEY

  return (
    <>
      {/* Total trend */}
      <div className="card">
        <div className="card-header">
          <div className="card-title">{t('charts.totalTitle')}</div>
          <div className="tab-group">
            <div className="tabs">
              {RANGE_DAYS.map((d) => (
                <button
                  key={d}
                  className={currentDays === d ? 'active' : ''}
                  onClick={() => onDaysChange(d)}
                >
                  {t(rangeLabelKey[d])}
                </button>
              ))}
            </div>
          </div>
        </div>
        <div className="chart-wrap">
          {stats.length > 0 && <Line data={totalData} options={totalOptions} />}
        </div>
      </div>

      {/* Daily delta */}
      <div className="card">
        <div className="card-header">
          <div className="card-title">{t('charts.deltaTitle')}</div>
          <div className="tab-group">
            <div className="view-toggle">
              <button className={currentView === 'clean' ? 'active' : ''} onClick={() => onViewChange('clean')}>{t('charts.viewClean')}</button>
              <button className={currentView === 'raw'   ? 'active' : ''} onClick={() => onViewChange('raw')}>{t('charts.viewRaw')}</button>
            </div>
          </div>
        </div>
        <div className="chart-wrap">
          {stats.length > 0 && <Bar data={deltaData} options={deltaOptions} />}
        </div>
        <div className="legend">
          <div className="legend-item">
            <div className="legend-dot" style={{ background: 'var(--green)' }} />
            {t('charts.legendAdded')}
          </div>
          <div className="legend-item">
            <div className="legend-dot" style={{ background: 'var(--red)' }} />
            {t('charts.legendRemoved')}
          </div>
          {currentView === 'raw' && (
            <div className="legend-item">
              <div className="legend-dot" style={{ background: 'var(--amber)' }} />
              {t('charts.legendRaw')}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
