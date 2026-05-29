const CAT_TAG = {
  new:            <span className="tag tag-new">uusi</span>,
  renewal:        <span className="tag tag-renewal">uusinta</span>,
  genuine_remove: <span className="tag tag-genuine">poistettu</span>,
  pending:        <span className="tag tag-pending">odottaa</span>,
}

function ChangeTable({ rows, csClass }) {
  if (!rows.length) {
    return (
      <tr><td colSpan={3} className="empty">Ei muutoksia</td></tr>
    )
  }
  return rows.map((r, i) => (
    <tr key={i}>
      <td className="dt">{r.change_date}</td>
      <td className={csClass}>{r.callsign}</td>
      <td>{CAT_TAG[r.category] ?? r.category}</td>
    </tr>
  ))
}

export default function ChangeLog({ changes, logView, onLogViewChange }) {
  const added   = changes.filter((r) => r.change_type === 'added')
  const removed = changes.filter((r) => r.change_type === 'removed')

  return (
    <div className="card">
      <div className="card-header">
        <div className="card-title">Muutosloki – viimeiset 30 pv</div>
        <div className="view-toggle">
          <button className={logView === 'clean' ? 'active' : ''} onClick={() => onLogViewChange('clean')}>Siivottu</button>
          <button className={logView === 'raw'   ? 'active' : ''} onClick={() => onLogViewChange('raw')}>Kaikki</button>
        </div>
      </div>
      <div className="changes-grid">
        <div className="change-section added">
          <h3>▲ Lisätyt / uudet</h3>
          <div className="scroll-table">
            <table>
              <thead><tr><th>Päivä</th><th>Kutsutunnus</th><th>Tyyppi</th></tr></thead>
              <tbody><ChangeTable rows={added} csClass="cs-added" /></tbody>
            </table>
          </div>
        </div>
        <div className="change-section removed">
          <h3>▼ Poistetut</h3>
          <div className="scroll-table">
            <table>
              <thead><tr><th>Päivä</th><th>Kutsutunnus</th><th>Tyyppi</th></tr></thead>
              <tbody><ChangeTable rows={removed} csClass="cs-removed" /></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}
