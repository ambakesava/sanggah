import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'
import type { Lang } from '../lib/i18n.tsx'

type Day = {
  date: string
  wuku: string
  saptawara: string
  pancawara: string
  triwara: string
  sasih: string | null
  penanggal_pangelong: string | null
  rerahinan: string[]
  rerahinan_labels?: string[]
  recommended?: boolean
  rules: { code: string; name: string; effect: string }[]
}
type AskResponse = {
  intent: { intent: string; activity: string | null }
  engine: { days: Day[]; rules_version: string } | null
  answer: string
  answer_mode: string
  needs_form: boolean
  disclaimer: string
  followups?: string[]
  reply_lang?: Lang
}
type Turn = { role: 'user' | 'assistant'; text: string; data?: AskResponse }

const chips = [
  ['Kapan hari baik untuk nikah?', 'When is a good day to get married?'],
  ['Hari baik untuk ngaben terdekat', 'Nearest good day for ngaben'],
  ['Kapan hari baik untuk potong gigi?', 'When is a good day for tooth filing?'],
  ['Hari baik buka usaha minggu depan?', 'A good day to open a shop next week?'],
  ['Kapan Galungan berikutnya?', 'When is the next Galungan?'],
]

const months = {
  id: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
  en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
}

function formatDate(iso: string, lang: Lang): string {
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) return iso
  return `${day} ${months[lang][month - 1]} ${year}`
}

function holyNames(day: Day): string {
  const labels = (day.rerahinan_labels ?? []).filter(Boolean)
  if (labels.length) return labels.join(', ')
  return day.rerahinan.map((name) => name.replaceAll('_', ' ')).join(', ')
}

export default function Tanya() {
  const { lang, tx } = useLang()
  const [question, setQuestion] = useState('')
  const [turns, setTurns] = useState<Turn[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const bottom = useRef<HTMLDivElement>(null)
  const lastAssistant = turns.reduce((found, turn, index) => (turn.role === 'assistant' ? index : found), -1)

  useEffect(() => {
    bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [turns, busy])

  async function send(text: string) {
    const trimmed = text.trim()
    if (!trimmed || busy) return
    const history = turns.slice(-6).map((turn) => ({ role: turn.role, content: turn.text }))
    setQuestion('')
    setBusy(true)
    setError('')
    setTurns((prev) => [...prev, { role: 'user', text: trimmed }])
    try {
      const data = await api<AskResponse>('/api/ask', {
        method: 'POST',
        body: JSON.stringify({ question: trimmed, lang, history }),
      })
      setTurns((prev) => [...prev, { role: 'assistant', text: data.answer, data }])
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="flex min-h-[70vh] flex-col">
      <h2 className="font-display text-3xl">{tx('Tanya Dewasa Ayu', 'Ask Dewasa Ayu')}</h2>
      <p className="mt-2 text-sm leading-relaxed text-moss">
        {tx(
          'Tanyakan dalam bahasa sehari-hari. Tanggalnya dihitung dari wuku dan sasih, lalu dijelaskan seperti obrolan.',
          'Ask in everyday language. The dates come from wuku and sasih, then get explained like a chat.',
        )}
      </p>
      {turns.length === 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {chips.map(([id, en]) => (
            <button key={id} type="button" className="rounded-full border border-line bg-card px-3 py-2 text-left text-sm" onClick={() => send(tx(id, en))}>
              {tx(id, en)}
            </button>
          ))}
        </div>
      )}

      <div className="mt-4 flex flex-1 flex-col gap-3" aria-live="polite">
        {turns.length === 0 && (
          <p className="text-sm text-moss">{tx('Pilih pertanyaan di atas, atau tulis sendiri di bawah.', 'Pick a question above, or write your own below.')}</p>
        )}
        {turns.map((turn, index) => (
          <TurnView key={index} turn={turn} onFollowup={index === lastAssistant ? send : undefined} />
        ))}
        {busy && (
          <div className="flex justify-start">
            <div className="rounded-2xl rounded-bl-md border border-line bg-card px-4 py-3" aria-label={tx('Sedang mengetik', 'Typing')}>
              <span className="inline-flex gap-1">
                <span className="h-2 w-2 animate-pulse rounded-full bg-moss" />
                <span className="h-2 w-2 animate-pulse rounded-full bg-moss [animation-delay:150ms]" />
                <span className="h-2 w-2 animate-pulse rounded-full bg-moss [animation-delay:300ms]" />
              </span>
            </div>
          </div>
        )}
        <div ref={bottom} />
      </div>

      {error && <p className="mt-3 text-sm text-brick" role="alert">{error}</p>}

      <form
        className="mt-4 flex items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          send(question)
        }}
      >
        <textarea
          className="tap min-h-11 max-h-32 min-w-0 flex-1 resize-none rounded-2xl border border-line bg-card px-3 py-2 text-base"
          value={question}
          rows={1}
          maxLength={500}
          placeholder={tx('Tulis pertanyaan', 'Write a question')}
          onChange={(event) => setQuestion(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault()
              send(question)
            }
          }}
        />
        <button className="tap rounded-2xl bg-ink px-4 text-paper" type="submit" disabled={busy || !question.trim()}>
          {tx('Kirim', 'Send')}
        </button>
      </form>
      <p className="mt-6 text-xs leading-relaxed text-moss">
        {tx('Praktik bisa beda tiap desa. Ini pendamping, bukan keputusan adat.', 'Practice can differ by village. This is a companion, not a customary ruling.')}
      </p>
    </main>
  )
}

function TurnView({ turn, onFollowup }: { turn: Turn; onFollowup?: (text: string) => void }) {
  const { lang, tx } = useLang()
  const chipLang = turn.data?.reply_lang || lang
  if (turn.role === 'user') {
    return (
      <div className="flex justify-end">
        <p className="max-w-[85%] rounded-2xl rounded-br-md bg-ink px-4 py-3 text-sm leading-relaxed text-paper">{turn.text}</p>
      </div>
    )
  }
  const recommended = (turn.data?.engine?.days ?? []).filter((day) => day.recommended).slice(0, 3)
  return (
    <div className="flex justify-start">
      <div className="max-w-[92%] rounded-2xl rounded-bl-md border border-line bg-card px-4 py-3">
        <p className="whitespace-pre-wrap text-sm leading-relaxed">{turn.text}</p>
        {recommended.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {recommended.map((day) => (
              <span key={day.date} className="rounded-full border border-line bg-paper px-3 py-1 text-xs">
                {formatDate(day.date, chipLang)} · {day.saptawara} {day.pancawara}
              </span>
            ))}
          </div>
        )}
        {recommended.length > 0 && (
          <details className="mt-2">
            <summary className="cursor-pointer text-xs font-semibold text-moss">{tx('Lihat detail', 'See detail')}</summary>
            <div className="mt-2 space-y-2">
              {recommended.map((day) => (
                <div key={day.date} className="rounded-xl bg-paper px-3 py-2 text-xs leading-relaxed">
                  <p className="font-semibold">{formatDate(day.date, chipLang)} · {day.saptawara} {day.pancawara} · {tx('wuku', 'wuku')} {day.wuku}</p>
                  <p>{day.triwara}{day.sasih ? ` · sasih ${day.sasih}` : ''}{day.penanggal_pangelong ? ` · ${day.penanggal_pangelong}` : ''}</p>
                  {holyNames(day) && <p>{holyNames(day)}</p>}
                  {day.rules.filter((rule) => rule.effect === 'good').length > 0 && (
                    <p>{day.rules.filter((rule) => rule.effect === 'good').map((rule) => rule.name).join(', ')}</p>
                  )}
                  <Link className="mt-1 inline-block underline" to={`/kalender/${day.date}`}>{tx('Buka di kalender', 'Open in the calendar')}</Link>
                </div>
              ))}
            </div>
          </details>
        )}
        {onFollowup && (turn.data?.followups?.length ?? 0) > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {turn.data?.followups?.map((chip) => (
              <button key={chip} type="button" className="rounded-full border border-line bg-paper px-3 py-1 text-left text-xs" onClick={() => onFollowup(chip)}>
                {chip}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
