import { useState } from 'react'
import { fetchSearch } from '../api'
import { useLocale } from '../i18n'

const CAT_STATUS_CLS = {
  new:            'active',
  renewal:        'pending',
  genuine_remove: 'inactive',
  pending:        'pending',
}

export default function SearchCard() {
  const { t } = useLocale()
  const [query, setQuery]   = useState('')
  const [result, setResult] = useState(null)   // null = hidden, 'loading', or data
  const [error, setError]   = useState(null)

  const doSearch = async () => {
    const q = query.trim()
    if (!q || q.length < 3 || q.length > 12) return
    setResult('loading')
    setError(null)
    try {
      const d = await fetchSearch(q)
      setResult(d)
    } catch (e) {
      setError(e.message)
      setResult(null)
    }
  }

  const handleKey = (e) => { if (e.key === 'Enter') doSearch() }

  const renderResult = () => {
    if (error) {
      return <span style={{ color: 'var(--red)', fontFamily: 'var(--mono)', fontSize: '.8rem' }}>{t('search.error', { msg: error })}</span>
    }
    if (result === 'loading') {
      return <span style={{ color: 'var(--dim)', fontFamily: 'var(--mono)', fontSize: '.8rem' }}>{t('search.searching')}<span className="dot-anim" /></span>
    }
    if (!result) return null

    if (!result.found) {
      return <div className="sr-not-found">{t('search.notFound', { cs: result.callsign })}</div>
    }

    const statusText = result.active
      ? (result.status || t('search.statusActive'))
      : t('search.statusRemoved')
    const statusCls = result.active ? 'active' : 'inactive'

    const dateRow = result.active
      ? <div className="sr-row"><span className="sr-label">{t('search.labelDate')}</span><span className="sr-value">{result.snapshot_date || '–'}</span></div>
      : <div className="sr-row"><span className="sr-label">{t('search.labelRemoved')}</span><span className="sr-value inactive">{result.removed_date || '–'}</span></div>

    const firstSeenNote = result.first_seen
      ? <>
          <div className="sr-row"><span className="sr-label">{t('search.labelFirstSeen')}</span><span className="sr-value">{result.first_seen}</span></div>
          <div className="sr-note">{t('search.firstSeenNote')}</div>
        </>
      : <div className="sr-note">{t('search.noFirstSeenNote')}</div>

    return (
      <>
        <div className="sr-callsign">{result.callsign}</div>
        <div className="sr-row">
          <span className="sr-label">{t('search.labelStatus')}</span>
          <span className={`sr-value ${statusCls}`}>{statusText}</span>
        </div>
        {dateRow}
        {firstSeenNote}
        {result.changes?.length > 0 && (
          <div className="sr-changes">
            <div className="sr-changes-title">{t('search.historyTitle')}</div>
            {result.changes.slice(0, 10).map((c, i) => {
              const catKey = 'search.cat' + c.category.split('_').map(w => w[0].toUpperCase() + w.slice(1)).join('')
              const catText = t(catKey)
              const catCls  = CAT_STATUS_CLS[c.category] ?? ''
              const arrow = c.change_type === 'added' ? '▲' : '▼'
              const color = c.change_type === 'added' ? 'var(--green)' : 'var(--red)'
              return (
                <div key={i} className="sr-change-row">
                  <span style={{ color, minWidth: 14 }}>{arrow}</span>
                  <span style={{ color: 'var(--dim)', minWidth: 100 }}>{c.change_date}</span>
                  <span className={`sr-value ${catCls}`}>{catText}</span>
                </div>
              )
            })}
          </div>
        )}
      </>
    )
  }

  return (
    <div className="card">
      <div className="card-header">
        <div className="card-title">{t('search.title')}</div>
      </div>
      <div className="search-wrap">
        <input
          className="search-input"
          type="text"
          placeholder={t('search.placeholder')}
          maxLength={20}
          autoComplete="off"
          spellCheck="false"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={handleKey}
        />
        <button className="search-btn" onClick={doSearch}>{t('search.button')}</button>
      </div>
      {(result !== null || error) && (
        <div className="search-result">{renderResult()}</div>
      )}
    </div>
  )
}
