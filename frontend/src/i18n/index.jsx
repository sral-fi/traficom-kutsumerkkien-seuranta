import { createContext, useContext, useState } from 'react'
import fi from './fi'
import sv from './sv'
import en from './en'

const TRANSLATIONS = { fi, sv, en }

const LocaleContext = createContext(null)

/** Resolve a dot-separated key and substitute {{var}} placeholders. */
function resolve(locale, key, vars) {
  const parts = key.split('.')
  let val = TRANSLATIONS[locale]
  for (const p of parts) {
    if (val == null) return key
    val = val[p]
  }
  if (val == null) return key
  if (vars) {
    return String(val).replace(/\{\{(\w+)\}\}/g, (_, k) => vars[k] ?? '')
  }
  return String(val)
}

export function LocaleProvider({ children }) {
  const [locale, setLocale] = useState(
    () => localStorage.getItem('locale') ?? 'fi'
  )

  const setAndStore = (l) => {
    localStorage.setItem('locale', l)
    setLocale(l)
  }

  const t = (key, vars) => resolve(locale, key, vars)

  return (
    <LocaleContext.Provider value={{ locale, setLocale: setAndStore, t }}>
      {children}
    </LocaleContext.Provider>
  )
}

export const useLocale = () => useContext(LocaleContext)
