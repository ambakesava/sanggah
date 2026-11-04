import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api.ts'
import type { Ceremony } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'

export default function Home() {
  const { lang, tx } = useLang()
  const [rows, setRows] = useState<Ceremony[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    api<{ data: Ceremony[] }>(`/api/ceremonies?lang=${lang}`)
      .then((res) => setRows(res.data.slice(0, 3)))
      .catch(() => setError(tx('API belum terjangkau. Jalankan backend di port 8000.', 'The API is not reachable. Start the backend on port 8000.')))
  }, [lang, tx])

  const modules = [
    { n: '01', to: '/scan', title: tx('Pindai & jelaskan', 'Scan and explain'), body: tx('Foto objek budaya. Jika model ragu, Anda yang memilih.', 'Photograph a cultural object. If the model is unsure, you choose.') },
    { n: '02', to: '/tanya', title: tx('Tanya Dewasa Ayu', 'Ask Dewasa Ayu'), body: tx('Bahasa sehari-hari masuk. Tanggal dihitung mesin pawukon, bukan model.', 'Everyday language in. Dates come from the pawukon engine, not the model.') },
    { n: '03', to: '/upacara', title: tx('Upacara', 'Ceremonies'), body: tx('Hari raya terkurasi plus catatan jalan. Bukan lalu lintas langsung.', 'Curated holy days plus road notes. Not a live traffic feed.') },
  ]

  return (
    <main>
      <p className="max-w-xl text-lg leading-relaxed">
        {tx(
          'Sanggah membantu wisatawan dan warga membaca yang mereka lihat, tanpa menggantikan pemangku.',
          'Sanggah helps visitors and residents read what they are looking at, without replacing a pemangku.',
        )}
      </p>
      <div className="mt-6 space-y-3">
        {modules.map((item) => (
          <Link key={item.n} to={item.to} className="block rounded-2xl border border-line bg-card p-4 shadow-[4px_4px_0_#1c1612]">
            <p className="font-display text-gold">{item.n}</p>
            <h2 className="font-display text-2xl">{item.title}</h2>
            <p className="mt-1 text-sm leading-relaxed">{item.body}</p>
          </Link>
        ))}
      </div>
      <section className="mt-8" aria-live="polite">
        <h2 className="font-display text-2xl">{tx('Yang terdekat', 'Coming up')}</h2>
        {error && <p className="mt-2 text-sm text-brick">{error}</p>}
        <ul className="mt-3 divide-y divide-line rounded-2xl border border-line bg-card">
          {rows.map((row) => (
            <li key={row.slug}>
              <Link to={`/upacara/${row.slug}`} className="tap flex items-baseline justify-between gap-3 px-4 py-3">
                <span className="font-semibold">{row.name}</span>
                <span className="text-sm text-moss">{row.date_start}</span>
              </Link>
            </li>
          ))}
          {rows.length === 0 && !error && <li className="px-4 py-3 text-sm">{tx('Memuat…', 'Loading…')}</li>}
        </ul>
      </section>
    </main>
  )
}
