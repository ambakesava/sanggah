import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { API_BASE, api } from '../lib/api.ts'
import type { Ceremony } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'

export default function Upacara() {
  const { slug } = useParams()
  const { lang, tx } = useLang()
  const [rows, setRows] = useState<Ceremony[]>([])
  const [detail, setDetail] = useState<Ceremony | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    if (slug) {
      api<{ data: Ceremony }>(`/api/ceremonies/${slug}?lang=${lang}`)
        .then((res) => setDetail(res.data))
        .catch((err: Error) => setError(err.message))
      return
    }
    api<{ data: Ceremony[] }>(`/api/ceremonies?lang=${lang}&from=2026-01-01&to=2026-12-31`)
      .then((res) => setRows(res.data))
      .catch((err: Error) => setError(err.message))
  }, [slug, lang])

  if (slug && detail) {
    return (
      <main>
        <Link to="/upacara" className="text-sm underline">{tx('Semua upacara', 'All ceremonies')}</Link>
        <h2 className="mt-2 font-display text-3xl">{detail.name}</h2>
        <p className="text-sm text-moss">{detail.date_start}{detail.date_end && detail.date_end !== detail.date_start ? ` – ${detail.date_end}` : ''}</p>
        <p className="mt-3 leading-relaxed">{detail.summary}</p>
        <h3 className="mt-4 font-display text-xl">{tx('Untuk pengunjung', 'For visitors')}</h3>
        <p className="text-sm leading-relaxed">{detail.tips}</p>
        {detail.road_note && (
          <p className="mt-3 rounded-xl bg-gold/15 px-3 py-2 text-sm">
            <strong>{detail.road_note_label}. </strong>{detail.road_note}
          </p>
        )}
        <a className="tap mt-4 inline-flex items-center rounded-full bg-ink px-4 text-sm font-semibold text-paper" href={`${API_BASE}/api/ceremonies/${detail.slug}.ics`}>
          {tx('Tambah ke kalender', 'Add to calendar')}
        </a>
        <ul className="mt-4 text-xs text-moss">
          {detail.sources?.map((source) => (
            <li key={source.title}>{source.title}{source.url ? ` — ${source.url}` : ''}</li>
          ))}
        </ul>
      </main>
    )
  }

  return (
    <main>
      <h2 className="font-display text-3xl">{tx('Upacara 2026', 'Ceremonies in 2026')}</h2>
      <p className="mt-2 text-sm">{tx('Daftar terkurasi. Bukan jadwal lalu lintas langsung.', 'A curated list. Not a live traffic schedule.')}</p>
      {error && <p className="mt-3 text-sm text-brick">{error}</p>}
      <ul className="mt-4 divide-y divide-line rounded-2xl border border-line bg-card">
        {rows.map((row) => (
          <li key={row.slug}>
            <Link to={`/upacara/${row.slug}`} className="block px-4 py-3">
              <span className="font-semibold">{row.name}</span>
              <span className="mt-1 block text-sm text-moss">{row.date_start} · {row.summary}</span>
            </Link>
          </li>
        ))}
      </ul>
    </main>
  )
}
