import { useLocale } from '../i18n'

function ChangeTable({ rows, csClass }) {
  const { t } = useLocale()
  if (!rows.length) {
    return <tr><td colSpan={3} className="empty">{t('changelog.noChanges')}</td></tr>
  }
  return rows.map((r, i) => {
    const tagKey = {
      new:            'changelog.tagNew',
      renewal:        'changelog.tagRenewal',
      genuine_remove: 'changelog.tagGenuine',
      pending:        'changelog.tagPending',
    }[r.category]
    const tagCls = {
      new:            'tag tag-new',
      renewal:        'tag tag-renewal',
      genuine_remove: 'tag tag-genuine',
      pending:        'tag tag-pending',
    }[r.category] ?? 'tag'
    return (
      <tr key={i}>
        <td className="dt">{r.change_date}</td>
        <td className={csClass}>{r.callsign}</td>
        <td><span className={tagCls}>{tagKey ? t(tagKey) : r.category}</span></td>
      </tr>
    )
  })
}

export default function ChangeLog({ changes, logView, onLogViewChange }) {
  const { t } = useLocale()
  const added   = changes.filter((r) => r.change_type === 'added')
  const removed = changes.filter((r) => r.change_type === 'removed')

  return (
    <div className="card">
      <div className="card-header">
        <div className="card-title">{t('changelog.title')}</div>
        <div className="view-toggle">
          <button className={logView === 'clean' ? 'active' : ''} onClick={() => onLogViewChange('clean')}>{t('changelog.viewClean')}</button>
          <button className={logView === 'raw'   ? 'active' : ''} onClick={() => onLogViewChange('raw')}>{t('changelog.viewAll')}</button>
        </div>
      </div>
      <div className="changes-grid">
        <div className="change-section added">
          <h3>{t('changelog.sectionAdded')}</h3>
          <div className="scroll-table">
            <table>
              <thead><tr><th>{t('changelog.colDate')}</th><th>{t('changelog.colCallsign')}</th><th>{t('changelog.colType')}</th></tr></thead>
              <tbody><ChangeTable rows={added} csClass="cs-added" /></tbody>
            </table>
          </div>
        </div>
        <div className="change-section removed">
          <h3>{t('changelog.sectionRemoved')}</h3>
          <div className="scroll-table">
            <table>
              <thead><tr><th>{t('changelog.colDate')}</th><th>{t('changelog.colCallsign')}</th><th>{t('changelog.colType')}</th></tr></thead>
              <tbody><ChangeTable rows={removed} csClass="cs-removed" /></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}
