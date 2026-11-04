import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'

type Day = {
  date: string
  pawukon_day: number
  wuku: string
  saptawara: string
  pancawara: string
  triwara: string
  sasih: string | null
  penanggal_pangelong: string | null
  rerahinan: string[]
  recommended?: boolean
  rules: { code: string; name: string; effect: string }[]
  ceremonies: { slug: string; name: string }[]
}
type AskResponse = {
  intent: { intent: string; activity: string | null; date_from?: string | null; date_to?: string | null }
  engine: { days: Day[]; rules_version: string } | null
  answer: string
  answer_mode: string
  needs_form: boolean
  disclaimer: string
}

const chips = [
  ['Upacara apa tanggal 17 Juni 2026?', 'Is there a ceremony on 17 June 2026?'],
  ['Hari baik buka usaha minggu depan?', 'A good day to open a shop next week?'],
  ['Kapan Galungan berikutnya?', 'When is the next Galungan?'],
  ['Kapan hari terbaik terdekat untuk nikah?', 'When is the nearest good day to get married?'],
]

export default function Tanya() {
  const { lang, tx } = useLang()
  const [question, setQuestion] = useState('')
  const [log, setLog] = useState<{ q: string; a: AskResponse }[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ activity: 'buka_usaha', from: '', to: '' })

  async function send(text: string, override?: Record<string, unknown>) {
    setBusy(true)
    setError('')
    try {
      const data = await api<AskResponse>('/api/ask', {
        method: 'POST',
        body: JSON.stringify({ question: text, lang, intent_override: override }),
      })
      setLog((prev) => [...prev, { q: text, a: data }])
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main>
      <h2 className="font-display text-3xl">{tx('Tanya Dewasa Ayu', 'Ask Dewasa Ayu')}</h2>
      <p className="mt-2 text-sm leading-relaxed">
        {tx('Model hanya membaca maksud pertanyaan. Wuku, wewaran, dan hari raya dihitung mesin kalender.', 'The model only reads what you are asking. Wuku, wewaran, and holy days are computed by the calendar engine.')}
      </p>
      <div className="mt-3 flex flex-wrap gap-2">
        {chips.map(([id, en]) => (
          <button key={id} type="button" className="rounded-full border border-line bg-card px-3 py-2 text-left text-sm" onClick={() => send(tx(id, en))}>
            {tx(id, en)}
          </button>
        ))}
      </div>
      <form className="mt-4 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (question.trim()) send(question.trim()) }}>
        <input className="tap min-w-0 flex-1 rounded-xl border border-line bg-card px-3 text-base" value={question} onChange={(e) => setQuestion(e.target.value)} maxLength={500} placeholder={tx('Tulis pertanyaan', 'Write a question')} />
        <button className="tap rounded-xl bg-ink px-4 text-paper" type="submit" disabled={busy}>{tx('Tanya', 'Ask')}</button>
      </form>
      {error && <p className="mt-3 text-sm text-brick" role="alert">{error}</p>}
      <div className="mt-4 space-y-4" aria-live="polite">
        {log.map((item, index) => (
          <article key={index} className="rounded-2xl border border-line bg-card p-4">
            <p className="text-sm font-semibold">{item.q}</p>
            <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed">{item.a.answer}</p>
            {item.a.engine?.days?.slice(0, 8).map((day) => (
              <Link key={day.date} to={`/kalender/${day.date}`} className="mt-2 block rounded-xl border border-line px-3 py-2 text-sm">
                <span className="font-semibold">{day.date}</span> · {day.saptawara} {day.pancawara} · {day.wuku}
                {day.rerahinan.length > 0 && <span> · {day.rerahinan.join(', ')}</span>}
                {day.recommended && <span className="ml-2 text-moss">{tx('kandidat', 'candidate')}</span>}
              </Link>
            ))}
            <p className="mt-2 text-xs text-moss">{item.a.disclaimer}</p>
          </article>
        ))}
      </div>
      <form className="mt-6 rounded-2xl border border-dashed border-gold p-4" onSubmit={(e) => { e.preventDefault(); send(tx('Formulir hari baik', 'Good-day form'), { intent: 'hari_baik', activity: form.activity, date_from: form.from, date_to: form.to }) }}>
        <h3 className="font-display text-xl">{tx('Jika model tidak membaca tanggal', 'If the model cannot read the date')}</h3>
        <div className="mt-3 grid gap-2 sm:grid-cols-3">
          <select className="tap rounded-xl border border-line bg-card px-2" value={form.activity} onChange={(e) => setForm({ ...form, activity: e.target.value })}>
            <option value="buka_usaha">{tx('Buka usaha', 'Open a business')}</option>
            <option value="pindah_rumah">{tx('Pindah rumah', 'Move house')}</option>
            <option value="nikah">{tx('Nikah', 'Get married')}</option>
          </select>
          <input className="tap rounded-xl border border-line px-2" type="date" required value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
          <input className="tap rounded-xl border border-line px-2" type="date" required value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
        </div>
        <button className="tap mt-3 rounded-xl bg-moss px-4 text-paper" type="submit">{tx('Hitung mesin', 'Run the engine')}</button>
      </form>
    </main>
  )
}
