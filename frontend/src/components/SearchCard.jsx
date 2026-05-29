import { useState } from 'react'
import { fetchSearch } from '../api'

const CAT_LABEL = {
  new:            { text: 'Uusi kutsumerkki',   cls: 'active'   },
  renewal:        { text: 'Lupauusinta',         cls: 'pending'  },
  genuine_remove: { text: 'Poistettu',           cls: 'inactive' },
  pending:        { text: 'Odottaa vahvistusta', cls: 'pending'  },
}

export default function SearchCard() {
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
      return <span style={{ color: 'var(--red)', fontFamily: 'var(--mono)', fontSize: '.8rem' }}>Virhe haussa: {error}</span>
    }
    if (result === 'loading') {
      return <span style={{ color: 'var(--dim)', fontFamily: 'var(--mono)', fontSize: '.8rem' }}>Haetaan<span className="dot-anim" /></span>
    }
    if (!result) return null

    if (!result.found) {
      return <div className="sr-not-found">Kutsumerkkiä <strong>{result.callsign}</strong> ei löydy tietokannasta.</div>
    }

    const statusInfo = result.active
      ? { text: result.status || 'VOIMASSA', cls: 'active' }
      : { text: 'POISTETTU', cls: 'inactive' }

    const dateRow = result.active
      ? <div className="sr-row"><span className="sr-label">Tieto päivältä</span><span className="sr-value">{result.snapshot_date || '–'}</span></div>
      : <div className="sr-row"><span className="sr-label">Poistettu</span><span className="sr-value inactive">{result.removed_date || '–'}</span></div>

    const firstSeenNote = result.first_seen
      ? <>
          <div className="sr-row"><span className="sr-label">Nähty 1. kerran</span><span className="sr-value">{result.first_seen}</span></div>
          <div className="sr-note">⚠ Ensimmäinen havainto seurantadatassa. Kutsut jotka ovat olleet voimassa ennen seurannan alkua eivät välttämättä näy oikealla päivämäärällä.</div>
        </>
      : <div className="sr-note">⚠ Ensimmäisen havainnon päivä ei ole tiedossa — kutsumerkki on ollut listalla ennen seurannan alkua.</div>

    return (
      <>
        <div className="sr-callsign">{result.callsign}</div>
        <div className="sr-row">
          <span className="sr-label">Tila</span>
          <span className={`sr-value ${statusInfo.cls}`}>{statusInfo.text}</span>
        </div>
        {dateRow}
        {firstSeenNote}
        {result.changes?.length > 0 && (
          <div className="sr-changes">
            <div className="sr-changes-title">Muutoshistoria (max 10 viimeistä)</div>
            {result.changes.slice(0, 10).map((c, i) => {
              const lbl   = CAT_LABEL[c.category] ?? { text: c.category, cls: '' }
              const arrow = c.change_type === 'added' ? '▲' : '▼'
              const color = c.change_type === 'added' ? 'var(--green)' : 'var(--red)'
              return (
                <div key={i} className="sr-change-row">
                  <span style={{ color, minWidth: 14 }}>{arrow}</span>
                  <span style={{ color: 'var(--dim)', minWidth: 100 }}>{c.change_date}</span>
                  <span className={`sr-value ${lbl.cls}`}>{lbl.text}</span>
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
        <div className="card-title">Kutsumerkkihaku</div>
      </div>
      <div className="search-wrap">
        <input
          className="search-input"
          type="text"
          placeholder="esim. OH2LAK"
          maxLength={20}
          autoComplete="off"
          spellCheck="false"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={handleKey}
        />
        <button className="search-btn" onClick={doSearch}>HAE</button>
      </div>
      {(result !== null || error) && (
        <div className="search-result">{renderResult()}</div>
      )}
    </div>
  )
}
