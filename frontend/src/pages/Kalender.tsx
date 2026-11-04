import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'

type Day = Record<string, unknown> & {
  date: string
  pawukon_day: number
  wuku: string
  saptawara: string
  pancawara: string
  triwara: string
  caturwara: string
  sadwara: string
  astawara: string
  sangawara: string
  dasawara: string
  sasih: string | null
  penanggal_pangelong: string | null
  rerahinan: string[]
  rules?: { name: string; effect: string }[]
}

export default function Kalender() {
  const { date: param } = useParams()
  const { lang, tx } = useLang()
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Makassar' })
  const [date, setDate] = useState(param || today)
  const [day, setDay] = useState<Day | null>(null)
  const [disclaimer, setDisclaimer] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    if (param) setDate(param)
  }, [param])

  useEffect(() => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return
    api<{ data: Day; disclaimer: string }>(`/api/calendar/${date}?lang=${lang}`)
      .then((res) => { setDay(res.data); setDisclaimer(res.disclaimer); setError('') })
      .catch((err: Error) => setError(err.message))
  }, [date, lang])

  const fields: [string, string][] = day ? [
    ['Pawukon', String(day.pawukon_day)],
    ['Wuku', day.wuku],
    ['Saptawara', day.saptawara],
    ['Pancawara', day.pancawara],
    ['Triwara', day.triwara],
    ['Caturwara', day.caturwara],
    ['Sadwara', day.sadwara],
    ['Astawara', day.astawara],
    ['Sangawara', day.sangawara],
    ['Dasawara', day.dasawara],
    ['Sasih', day.sasih || '—'],
    ['Penanggal', day.penanggal_pangelong || '—'],
  ] : []

  return (
    <main>
      <h2 className="font-display text-3xl">{tx('Kalender Bali', 'Balinese calendar')}</h2>
      <label className="mt-3 block text-sm font-semibold">
        {tx('Tanggal', 'Date')}
        <input className="tap mt-1 w-full rounded-xl border border-line bg-card px-3 text-base" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </label>
      {error && <p className="mt-3 text-sm text-brick">{error}</p>}
      {day && (
        <dl className="mt-4 grid grid-cols-2 gap-2">
          {fields.map(([key, value]) => (
            <div key={key} className="rounded-xl border border-line bg-card px-3 py-2">
              <dt className="text-xs uppercase tracking-wide text-gold">{key}</dt>
              <dd className="font-semibold">{value}</dd>
            </div>
          ))}
        </dl>
      )}
      {day && (
        <p className="mt-4 text-sm">
          {tx('Rerahinan', 'Holy days')}: {day.rerahinan.length ? day.rerahinan.join(', ') : tx('tidak ada pada tanggal ini', 'none on this date')}
        </p>
      )}
      {day?.rules && day.rules.length > 0 && (
        <ul className="mt-2 text-sm">
          {day.rules.map((rule) => <li key={rule.name}>{rule.name} · {rule.effect}</li>)}
        </ul>
      )}
      {disclaimer && <p className="mt-4 text-xs leading-relaxed text-moss">{disclaimer}</p>}
      <DateRangeForm />
    </main>
  )
}

function DateRangeForm() {
  const { lang, tx } = useLang()
  const [form, setForm] = useState({ activity: 'buka_usaha', from: '', to: '' })
  const [answer, setAnswer] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const data = await api<{ answer: string }>('/api/ask', {
        method: 'POST',
        body: JSON.stringify({
          question: tx('Cari hari baik pada rentang ini', 'Find a good day in this range'),
          lang,
          intent_override: { intent: 'hari_baik', activity: form.activity, date_from: form.from, date_to: form.to },
        }),
      })
      setAnswer(data.answer)
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="mt-8 rounded-2xl border border-dashed border-gold p-4" onSubmit={submit}>
      <h3 className="font-display text-xl">{tx('Cari hari baik pada rentang tanggal', 'Find a good day in a date range')}</h3>
      <div className="mt-3 grid gap-2 sm:grid-cols-3">
        <select className="tap rounded-xl border border-line bg-card px-2" value={form.activity} onChange={(event) => setForm({ ...form, activity: event.target.value })}>
          <option value="buka_usaha">{tx('Buka usaha', 'Open a business')}</option>
          <option value="pindah_rumah">{tx('Pindah rumah', 'Move house')}</option>
          <option value="nikah">{tx('Nikah', 'Get married')}</option>
          <option value="potong_gigi">{tx('Potong gigi', 'Tooth filing')}</option>
          <option value="potong_rambut">{tx('Potong rambut', 'Hair cutting')}</option>
          <option value="membangun">{tx('Membangun', 'Build a house')}</option>
          <option value="ngaben">{tx('Ngaben', 'Ngaben')}</option>
          <option value="menanam">{tx('Menanam', 'Planting')}</option>
        </select>
        <input className="tap rounded-xl border border-line px-2" type="date" required value={form.from} onChange={(event) => setForm({ ...form, from: event.target.value })} />
        <input className="tap rounded-xl border border-line px-2" type="date" required value={form.to} onChange={(event) => setForm({ ...form, to: event.target.value })} />
      </div>
      <button className="tap mt-3 rounded-xl bg-moss px-4 text-paper" type="submit" disabled={busy}>{tx('Hitung', 'Calculate')}</button>
      {error && <p className="mt-3 text-sm text-brick">{error}</p>}
      {answer && <p className="mt-3 text-sm leading-relaxed">{answer}</p>}
    </form>
  )
}
