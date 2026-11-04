import { useEffect, useState } from 'react'
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
    </main>
  )
}
