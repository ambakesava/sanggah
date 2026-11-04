import { useEffect, useState } from 'react'
import { api, compressImage } from '../lib/api.ts'
import type { Citation, Explanation } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'
import { ApiError } from '../lib/api.ts'

type Candidate = { slug: string; name: string; confidence: number }
type ScanOk = {
  status: 'identified' | 'needs_confirmation'
  message?: string
  object?: { slug: string; name: string; confidence: number; visual_cues: string[] }
  candidates?: Candidate[]
  explanation?: Explanation
  photo_stored: boolean
  consent_id: string | null
  request_id?: string
}

const labels = {
  meaning: ['Makna', 'Meaning'],
  etiquette: ['Etika', 'Etiquette'],
  dress_code: ['Busana', 'Dress'],
  prohibitions: ['Larangan', 'Do not'],
} as const

export default function Scan() {
  const { lang, tx } = useLang()
  const [store, setStore] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [result, setResult] = useState<ScanOk | null>(null)
  const [explanation, setExplanation] = useState<Explanation | null>(null)
  const [question, setQuestion] = useState('')
  const [objects, setObjects] = useState<{ slug: string; name: string }[]>([])
  const [manual, setManual] = useState('')
  const [consentId, setConsentId] = useState<string | null>(null)

  useEffect(() => {
    api<{ data: { slug: string; name: string }[] }>(`/api/objects?lang=${lang}`)
      .then((res) => setObjects(res.data))
      .catch(() => setObjects([]))
  }, [lang])

  async function onFile(file: File | undefined) {
    if (!file) return
    setBusy(true)
    setError('')
    setResult(null)
    setExplanation(null)
    try {
      const blob = await compressImage(file)
      const body = new FormData()
      const named = new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })
      body.append('image', named)
      body.append('lang', lang)
      body.append('store_photo', store ? '1' : '0')
      const data = await api<ScanOk>('/api/scan', { method: 'POST', body })
      setResult(data)
      setExplanation(data.explanation ?? null)
      setConsentId(data.consent_id)
    } catch (err) {
      const apiErr = err as ApiError
      const fallback = (apiErr.body as { error?: { message?: string } } | undefined)?.error?.message
      setError(fallback || tx('Foto tidak terkirim.', 'The photo could not be sent.'))
    } finally {
      setBusy(false)
    }
  }

  async function explain(slug: string, followUp?: string) {
    setBusy(true)
    setError('')
    try {
      const data = await api<Explanation & { request_id?: string }>('/api/explain', {
        method: 'POST',
        body: JSON.stringify({ object_slug: slug, lang, question: followUp || null }),
      })
      setExplanation(data)
      setResult((prev) => prev && prev.status === 'needs_confirmation'
        ? { ...prev, status: 'identified', object: { slug, name: objects.find((o) => o.slug === slug)?.name || slug, confidence: prev.candidates?.find((c) => c.slug === slug)?.confidence || 0, visual_cues: [] } }
        : prev)
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function feedback(rating: 'helpful' | 'wrong') {
    const slug = result?.object?.slug
    await api('/api/feedback', {
      method: 'POST',
      body: JSON.stringify({ feature: 'explain', rating, object_slug: slug, lang }),
    }).catch(() => setError(tx('Umpan balik gagal tersimpan.', 'Feedback could not be saved.')))
  }

  async function revoke() {
    if (!consentId) return
    await api(`/api/consents/${consentId}`, { method: 'DELETE' })
    setConsentId(null)
  }

  const activeSlug = result?.object?.slug

  return (
    <main>
      <h2 className="font-display text-3xl">{tx('Pindai objek', 'Scan an object')}</h2>
      <p className="mt-2 text-sm leading-relaxed">
        {tx('JPEG, PNG, atau WebP, paling besar 8 MB. Lima belas kelas. Di bawah keyakinan 0,6, Sanggah bilang tidak yakin.', 'JPEG, PNG, or WebP, up to 8 MB. Fifteen classes. Below 0.6 confidence, Sanggah says it is not sure.')}
      </p>
      <label className="mt-4 flex items-start gap-3 text-sm">
        <input type="checkbox" className="mt-1 h-5 w-5" checked={store} onChange={(e) => setStore(e.target.checked)} />
        <span>
          {tx('Izinkan simpan foto. Mati secara bawaan. Foto yang disimpan tidak memuat EXIF.', 'Allow the photo to be stored. Off by default. Stored photos have no EXIF.')}{' '}
          <a className="underline" href="/privasi">{tx('Privasi', 'Privacy')}</a>
        </span>
      </label>
      <div className="mt-4 grid grid-cols-2 gap-3">
        <label className="tap flex cursor-pointer items-center justify-center rounded-2xl bg-brick px-3 text-center font-semibold text-paper">
          {tx('Kamera', 'Camera')}
          <input className="sr-only" type="file" accept="image/*" capture="environment" onChange={(e) => onFile(e.target.files?.[0])} />
        </label>
        <label className="tap flex cursor-pointer items-center justify-center rounded-2xl border border-ink bg-card px-3 text-center font-semibold">
          {tx('Galeri', 'Gallery')}
          <input className="sr-only" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => onFile(e.target.files?.[0])} />
        </label>
      </div>
      <p className="mt-3 text-xs text-moss" aria-live="polite">
        {busy ? tx('Memproses, sekitar beberapa detik…', 'Working, this can take a few seconds…') : tx('Tanpa kunci API, nama berkas seperti penjor.jpg dipakai sebagai petunjuk demo.', 'Without an API key, a filename such as penjor.jpg is a demo hint.')}
      </p>
      {error && <p className="mt-3 rounded-xl bg-brick/10 px-3 py-2 text-sm" role="alert">{error}</p>}

      {result?.status === 'needs_confirmation' && (
        <section className="mt-4 rounded-2xl border border-line bg-card p-4">
          <p className="font-semibold">{tx('Tidak yakin', 'Not sure')}</p>
          <p className="mt-1 text-sm">{result.message}</p>
          <div className="mt-3 flex flex-col gap-2">
            {result.candidates?.map((c) => (
              <button key={c.slug} type="button" className="tap rounded-xl border border-line px-3 text-left" onClick={() => explain(c.slug)}>
                {c.name} · {Math.round(c.confidence * 100)}%
              </button>
            ))}
          </div>
        </section>
      )}

      <label className="mt-4 block text-sm font-semibold">
        {tx('Pilih manual', 'Choose manually')}
        <select className="tap mt-1 w-full rounded-xl border border-line bg-card px-3" value={manual} onChange={(e) => { setManual(e.target.value); if (e.target.value) explain(e.target.value) }}>
          <option value="">{tx('15 kelas', '15 classes')}</option>
          {objects.map((o) => <option key={o.slug} value={o.slug}>{o.name}</option>)}
        </select>
      </label>

      {explanation && (explanation.sections || explanation.answer) && (
        <article className="mt-5 rounded-2xl border border-line bg-card p-4">
          {result?.object && (
            <p className="text-sm text-moss">{result.object.name} · {tx('keyakinan', 'confidence')} {Math.round(result.object.confidence * 100)}%</p>
          )}
          {explanation.source_mode === 'curated' && (
            <p className="mt-1 text-xs uppercase tracking-wide text-gold">{tx('Kartu terkurasi, mode terbatas', 'Curated card, limited mode')}</p>
          )}
          {explanation.status === 'no_verified_source' && <p className="mt-2">{explanation.answer}</p>}
          {explanation.sections && (Object.keys(labels) as (keyof typeof labels)[]).map((key) => (
            <section key={key} className="mt-3">
              <h3 className="font-display text-xl">{tx(labels[key][0], labels[key][1])}</h3>
              <p className="text-sm leading-relaxed">{explanation.sections?.[key]}</p>
            </section>
          ))}
          {explanation.answer && explanation.status !== 'no_verified_source' && <p className="mt-3 text-sm leading-relaxed">{explanation.answer}</p>}
          <Citations items={explanation.citations} />
          <p className="mt-3 text-xs leading-relaxed text-moss">{explanation.disclaimer}</p>
          {activeSlug && (
            <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); explain(activeSlug, question) }}>
              <input className="tap min-w-0 flex-1 rounded-xl border border-line px-3 text-base" value={question} onChange={(e) => setQuestion(e.target.value)} maxLength={500} placeholder={tx('Tanya lanjutan', 'Ask a follow-up')} />
              <button className="tap rounded-xl bg-ink px-3 text-paper" type="submit">{tx('Kirim', 'Send')}</button>
            </form>
          )}
          <div className="mt-3 flex gap-2">
            <button type="button" className="tap rounded-full border border-line px-3 text-sm" onClick={() => feedback('helpful')}>{tx('Membantu', 'Helpful')}</button>
            <button type="button" className="tap rounded-full border border-line px-3 text-sm" onClick={() => feedback('wrong')}>{tx('Salah', 'Wrong')}</button>
          </div>
        </article>
      )}
      {consentId && (
        <button type="button" className="tap mt-3 text-sm underline" onClick={revoke}>
          {tx('Cabut izin dan hapus foto', 'Revoke consent and delete the photo')}
        </button>
      )}
    </main>
  )
}

function Citations({ items }: { items: Citation[] }) {
  if (!items?.length) return null
  return (
    <ol className="mt-4 list-decimal space-y-1 pl-5 text-xs text-moss">
      {items.map((item) => (
        <li key={item.n}>
          [{item.n}] {item.title}{item.publisher ? `, ${item.publisher}` : ''}{' '}
          {item.url && <a className="underline" href={item.url}>{item.url}</a>}
        </li>
      ))}
    </ol>
  )
}
