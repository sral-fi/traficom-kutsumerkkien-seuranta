import { useLocale } from '../i18n'

const fmt = (n) => (n == null ? '–' : String(Math.round(Number(n))))

export default function KpiRow({ summary }) {
  const { t } = useLocale()
  const l = summary?.latest
  const w = summary?.last_7_days

  return (
    <div className="kpi-row">
      <div className="kpi">
        <div className="kpi-label">{t('kpi.total')}</div>
        <div className="kpi-value c-blue">{l ? fmt(l.total) : '–'}</div>
        <div className="kpi-sub">{t('kpi.totalSub')}</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">{t('kpi.new7d')}</div>
        <div className="kpi-value c-green">{w ? '+' + fmt(w.new_7d) : '–'}</div>
        <div className="kpi-sub">{t('kpi.new7dSub')}</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">{t('kpi.removed7d')}</div>
        <div className="kpi-value c-red">{w ? '−' + fmt(w.genuine_removes_7d) : '–'}</div>
        <div className="kpi-sub">{t('kpi.removed7dSub')}</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">{t('kpi.renewals7d')}</div>
        <div className="kpi-value c-amber">{w ? fmt(w.renewals_7d) : '–'}</div>
        <div className="kpi-sub">{t('kpi.renewals7dSub')}</div>
      </div>
      <div className="kpi">
        <div className="kpi-label">{t('kpi.pending7d')}</div>
        <div className="kpi-value" style={{ color: 'var(--dim)' }}>{w ? fmt(w.pending_removes_7d) : '–'}</div>
        <div className="kpi-sub">{t('kpi.pending7dSub')}</div>
      </div>
    </div>
  )
}
