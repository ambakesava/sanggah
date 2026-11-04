import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

export type Lang = 'id' | 'en'

type Ctx = {
  lang: Lang
  setLang: (lang: Lang) => void
  tx: (id: string, en: string) => string
}

const LangContext = createContext<Ctx | null>(null)

function initialLang(): Lang {
  const saved = localStorage.getItem('sanggah.lang')
  if (saved === 'id' || saved === 'en') return saved
  return navigator.language.toLowerCase().startsWith('id') ? 'id' : 'en'
}

export function LangProvider({ children }: { children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>(initialLang)
  const setLang = (next: Lang) => {
    setLangState(next)
    localStorage.setItem('sanggah.lang', next)
  }
  useEffect(() => {
    document.documentElement.lang = lang
  }, [lang])
  const value = useMemo<Ctx>(() => ({
    lang,
    setLang,
    tx: (id, en) => (lang === 'en' ? en : id),
  }), [lang])
  return <LangContext.Provider value={value}>{children}</LangContext.Provider>
}

export function useLang(): Ctx {
  const ctx = useContext(LangContext)
  if (!ctx) throw new Error('useLang outside provider')
  return ctx
}
