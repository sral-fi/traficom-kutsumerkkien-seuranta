import { useState, useEffect, useRef } from 'react'
import { fetchSummary, fetchStats, fetchChanges } from './api'
import { useLocale } from './i18n'
import KpiRow from './components/KpiRow'
import DailyStatus from './components/DailyStatus'
import SearchCard from './components/SearchCard'
import Charts from './components/Charts'
import ChangeLog from './components/ChangeLog'

// ── Klingon pIqaD easter egg ─────────────────────────────────────────────────
const PIQAD = {
  A:'\uF8D0', B:'\uF8D1', CH:'\uF8D2', D:'\uF8D3', E:'\uF8D4',
  GH:'\uF8D5', H:'\uF8D6', I:'\uF8D7', J:'\uF8D8', L:'\uF8D9',
  M:'\uF8DA', N:'\uF8DB', NG:'\uF8DC', O:'\uF8DD', P:'\uF8DE',
  Q:'\uF8DF', QH:'\uF8E0', R:'\uF8E1', S:'\uF8E2', T:'\uF8E3',
  TLH:'\uF8E4', U:'\uF8E5', V:'\uF8E6', W:'\uF8E7', Y:'\uF8E8',
  '0':'\uF8F0', '1':'\uF8F1', '2':'\uF8F2', '3':'\uF8F3', '4':'\uF8F4',
  '5':'\uF8F5', '6':'\uF8F6', '7':'\uF8F7', '8':'\uF8F8', '9':'\uF8F9',
}

function toPIqaD(text) {
  let result = '', i = 0
  const s = text.toUpperCase()
  while (i < s.length) {
    if (i + 3 <= s.length && PIQAD[s.slice(i, i + 3)]) { result += PIQAD[s.slice(i, i + 3)]; i += 3 }
    else if (i + 2 <= s.length && PIQAD[s.slice(i, i + 2)]) { result += PIQAD[s.slice(i, i + 2)]; i += 2 }
    else if (PIQAD[s[i]]) { result += PIQAD[s[i]]; i++ }
    else { result += s[i]; i++ }
  }
  return result
}

const KONAMI = ['ArrowUp','ArrowUp','ArrowDown','ArrowDown','ArrowLeft','ArrowRight','ArrowLeft','ArrowRight','b','a']

// ── App ───────────────────────────────────────────────────────────────────────
export default function App() {
  const { locale, setLocale, t } = useLocale()

  const [theme, setTheme] = useState(
    () => localStorage.getItem('theme') ?? 'dark'
  )
  const [summary, setSummary]         = useState(null)
  const [stats, setStats]             = useState([])
  const [changes, setChanges]         = useState([])
  const [currentDays, setCurrentDays] = useState(90)
  const [currentView, setCurrentView] = useState('clean')
  const [logView, setLogView]         = useState('clean')
  const [klingon, setKlingon]         = useState(false)

  // Apply theme attribute to <html> so CSS vars override works
  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('theme', theme)
  }, [theme])

  const toggleTheme = () => setTheme((th) => (th === 'dark' ? 'light' : 'dark'))

  // Konami code → Klingon mode
  const konamiBuf = useRef([])
  useEffect(() => {
    const handler = (e) => {
      konamiBuf.current = [...konamiBuf.current.slice(-9), e.key]
      if (konamiBuf.current.join(',') === KONAMI.join(',')) {
        setKlingon(true)
        setTimeout(() => setKlingon(false), 5000)
        konamiBuf.current = []
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  useEffect(() => { fetchSummary().then(setSummary).catch(console.error) }, [])
  useEffect(() => { fetchStats(currentDays, currentView).then(setStats).catch(console.error) }, [currentDays, currentView])
  useEffect(() => { fetchChanges(30, 'all', logView).then(setChanges).catch(console.error) }, [logView])

  const lastUpdate = summary?.latest?.stat_date
    ? t('header.updated', { date: summary.latest.stat_date })
    : t('header.loading')

  return (
    <>
      <header>
        <div className="hdr-top">
          <h1>
            <span className="hdr-prefix">
              {klingon ? toPIqaD('OF OG OH OI OJ') : t('header.prefix')}
            </span>
            {klingon
              ? toPIqaD('Suomalaisten radioamatoorikutsujen seuranta')
              : t('header.title')}
          </h1>
          <div className="hdr-right">
            <div className="hdr-controls">
              <div className="lang-toggle">
                {['fi', 'sv', 'en'].map((l) => (
                  <button
                    key={l}
                    className={locale === l ? 'active' : ''}
                    onClick={() => setLocale(l)}
                  >
                    {l.toUpperCase()}
                  </button>
                ))}
              </div>
              <button
                className="theme-btn"
                onClick={toggleTheme}
                title={theme === 'dark' ? t('theme.day') : t('theme.night')}
              >
                {theme === 'dark' ? '☀' : '🌙'}
              </button>
            </div>
            <span>{lastUpdate}</span>
            <span>{t('header.source')}</span>
          </div>
        </div>
      </header>

      <KpiRow summary={summary} />

      <div className="main">
        <DailyStatus />
        <SearchCard />
        <Charts
          stats={stats}
          currentDays={currentDays}
          currentView={currentView}
          onDaysChange={setCurrentDays}
          onViewChange={setCurrentView}
          theme={theme}
        />
        <ChangeLog
          changes={changes}
          logView={logView}
          onLogViewChange={setLogView}
        />
      </div>

      {klingon && (
        <div style={{
          position: 'fixed', bottom: 20, right: 20, background: '#1a0a00',
          color: 'var(--amber)', fontFamily: 'monospace', fontSize: '1.4rem',
          padding: '12px 20px', border: '1px solid var(--amber)',
          zIndex: 99999, boxShadow: '0 0 16px rgba(255,204,68,.4)',
          letterSpacing: '.1em',
        }}>
          {toPIqaD('NUQNEH')}
        </div>
      )}
    </>
  )
}
