const fmt = (n) => (n == null ? '–' : String(Math.round(Number(n))))

export default function KpiRow({ summary, lastUpdate }) {
  const l = summary?.latest
  const w = summary?.last_7_days

  return (
    <div className="kpi-row">
      <div className="kpi">
        <div className="kpi-label">Voimassa yhteensä</div>
        <div className="kpi-value c-blue">{l ? fmt(l.total) : '–'}</div>
        <div className="kpi-sub">viimeisin snapshot</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">Uudet kutsut (7 pv)</div>
        <div className="kpi-value c-green">{w ? '+' + fmt(w.new_7d) : '–'}</div>
        <div className="kpi-sub">aidosti uusia</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">Poistettu (7 pv)</div>
        <div className="kpi-value c-red">{w ? '−' + fmt(w.genuine_removes_7d) : '–'}</div>
        <div className="kpi-sub">vahvistettu poisto</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">Lupauusinnat (7 pv)</div>
        <div className="kpi-value c-amber">{w ? fmt(w.renewals_7d) : '–'}</div>
        <div className="kpi-sub">tilapäinen katoaminen</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">Odottaa (pending)</div>
        <div className="kpi-value" style={{ color: 'var(--dim)' }}>{w ? fmt(w.pending_removes_7d) : '–'}</div>
        <div className="kpi-sub">alle 7 pv poistettuja</div>
      </div>
    </div>
  )
}
