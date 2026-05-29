import { useState, useEffect } from 'react'
import { fetchChanges } from '../api'
import { useLocale } from '../i18n'

const TAG_CLS = {
  new:            'ds-tag ds-tag-new',
  renewal:        'ds-tag ds-tag-renewal',
  genuine_remove: 'ds-tag ds-tag-genuine',
  pending:        'ds-tag ds-tag-pending',
}

export default function DailyStatus() {
  const { t } = useLocale()
  const [titleDate, setTitleDate] = useState(null)   // null = loading
  const [added, setAdded]         = useState(null)
  const [removed, setRemoved]     = useState(null)

  useEffect(() => {
    fetchChanges(2, 'all', 'raw')
      .then((data) => {
        if (!data.length) {
          setAdded([])
          setRemoved([])
          return
        }
        const latestDate = data[0].change_date
        setTitleDate(latestDate)
        const today = data.filter((r) => r.change_date === latestDate)
        setAdded(today.filter((r) => r.change_type === 'added'))
        setRemoved(today.filter((r) => r.change_type === 'removed'))
      })
      .catch(console.error)
  }, [])

  const title = titleDate
    ? t('dailyStatus.titleDate', { date: titleDate })
    : t('dailyStatus.title')

  const renderList = (list, cls) => {
    if (list === null)
      return <span className="ds-empty">{t('dailyStatus.loading')}<span className="dot-anim" /></span>
    if (!list.length)
      return <span className="ds-empty">{t('dailyStatus.noChanges')}</span>
    return list.map((r) => (
      <div key={r.callsign + r.change_date} className={`ds-item ${cls}`}>
        <span>{r.callsign}</span>
        <span className={TAG_CLS[r.category] ?? 'ds-tag'}>
          {t('category.' + r.category) ?? r.category}
        </span>
      </div>
    ))
  }

  return (
    <div className="card">
      <div className="card-header">
        <div className="card-title">{title}</div>
      </div>
      <div className="daily-status">
        <div className="ds-half">
          <div className="ds-label">{t('dailyStatus.added')}</div>
          <div className="ds-list">{renderList(added, 'added')}</div>
        </div>
        <div className="ds-half">
          <div className="ds-label">{t('dailyStatus.removed')}</div>
          <div className="ds-list">{renderList(removed, 'removed')}</div>
        </div>
      </div>
    </div>
  )
}
