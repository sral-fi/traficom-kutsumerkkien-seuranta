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

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip)

ChartJS.defaults.color       = '#7a9ab8'
ChartJS.defaults.borderColor = '#2a3f55'
ChartJS.defaults.font.family = "'JetBrains Mono', monospace"
ChartJS.defaults.font.size   = 11

const C = { green: '#2dffb0', red: '#ff5572', blue: '#60cdff' }

const RANGE_DAYS = [30, 90, 180, 365]
const RANGE_LABELS = { 30: '30 pv', 90: '90 pv', 180: '180 pv', 365: '1 v' }

export default function Charts({ stats, currentDays, currentView, onDaysChange, onViewChange }) {
  const labels  = stats.map((r) => r.stat_date)
  const totals  = stats.map((r) => r.total)
  const added   = stats.map((r) => r.display_added)
  const removed = stats.map((r) => -r.display_removed)

  const totalData = {
    labels,
    datasets: [{
      label: 'Yhteensä',
      data: totals,
      borderColor: C.blue,
      backgroundColor: 'rgba(96,205,255,.08)',
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
      tooltip: { mode: 'index', intersect: false },
    },
    scales: {
      x: { grid: { color: '#1e2e40' }, ticks: { maxTicksLimit: 10 } },
      y: { grid: { color: '#1e2e40' }, ticks: { callback: (v) => String(v) } },
    },
  }

  const deltaData = {
    labels,
    datasets: [
      {
        label: 'Lisätyt',
        data: added,
        backgroundColor: 'rgba(45,255,176,.55)',
        borderWidth: 0,
        borderRadius: { topLeft: 2, topRight: 2, bottomLeft: 0, bottomRight: 0 },
        stack: 'same',
        barPercentage: 0.8,
        categoryPercentage: 0.9,
      },
      {
        label: 'Poistetut',
        data: removed,
        backgroundColor: 'rgba(255,85,114,.55)',
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
        callbacks: { label: (ctx) => ctx.dataset.label + ': ' + Math.abs(ctx.raw) },
      },
    },
    scales: {
      x: { grid: { color: '#1e2e40' }, ticks: { maxTicksLimit: 10 }, stacked: true },
      y: { grid: { color: '#1e2e40' }, ticks: { callback: (v) => Math.abs(v) }, stacked: false },
    },
  }

  return (
    <>
      {/* Total trend */}
      <div className="card">
        <div className="card-header">
          <div className="card-title">Kutsumerkkien kokonaismäärä</div>
          <div className="tab-group">
            <div className="tabs">
              {RANGE_DAYS.map((d) => (
                <button
                  key={d}
                  className={currentDays === d ? 'active' : ''}
                  onClick={() => onDaysChange(d)}
                >
                  {RANGE_LABELS[d]}
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
          <div className="card-title">Päivittäiset muutokset</div>
          <div className="tab-group">
            <div className="view-toggle">
              <button className={currentView === 'clean' ? 'active' : ''} onClick={() => onViewChange('clean')}>Siivottu</button>
              <button className={currentView === 'raw'   ? 'active' : ''} onClick={() => onViewChange('raw')}>Raakadata</button>
            </div>
          </div>
        </div>
        <div className="chart-wrap">
          {stats.length > 0 && <Bar data={deltaData} options={deltaOptions} />}
        </div>
        <div className="legend">
          <div className="legend-item">
            <div className="legend-dot" style={{ background: 'var(--green)' }} />
            Uudet / lisätyt
          </div>
          <div className="legend-item">
            <div className="legend-dot" style={{ background: 'var(--red)' }} />
            Poistetut (vahvistettu)
          </div>
          {currentView === 'raw' && (
            <div className="legend-item">
              <div className="legend-dot" style={{ background: 'var(--amber)' }} />
              Raaka – sisältää renewalit
            </div>
          )}
        </div>
      </div>
    </>
  )
}
