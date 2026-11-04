import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../lib/api.ts'
import { useLang } from '../lib/i18n.tsx'

type CeremonyRow = {
  id: number
  slug: string
  name_id: string
  name_en: string
  date_start: string
  status: string
}
type SourceRow = { id: number; code: string; title: string }

const empty = {
  name_id: '', name_en: '', type: 'lokal', date_start: '', tips_id: '', tips_en: '',
  summary_id: '', summary_en: '', road_note_id: '', road_note_en: '', status: 'draft', source_id: '',
}

export default function Admin() {
  const { tx } = useLang()
  const [token, setToken] = useState(() => sessionStorage.getItem('sanggah.token') || '')
  const [email, setEmail] = useState('juri@sanggah.test')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [rows, setRows] = useState<CeremonyRow[]>([])
  const [sources, setSources] = useState<SourceRow[]>([])
  const [form, setForm] = useState(empty)

  async function load(active = token) {
    const [ceremonies, sourceRows] = await Promise.all([
      api<{ data: CeremonyRow[] }>('/api/admin/ceremonies', {}, active),
      api<{ data: SourceRow[] }>('/api/admin/sources', {}, active),
    ])
    setRows(ceremonies.data)
    setSources(sourceRows.data)
  }

  useEffect(() => {
    if (!token) return
    load(token).catch((err: Error) => setError(err.message))
  }, [token])

  async function login(event: FormEvent) {
    event.preventDefault()
    setError('')
    try {
      const res = await api<{ token: string }>('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })
      sessionStorage.setItem('sanggah.token', res.token)
      setToken(res.token)
    } catch (err) {
      setError((err as Error).message)
    }
  }

  async function create(event: FormEvent) {
    event.preventDefault()
    setError('')
    try {
      await api('/api/admin/ceremonies', {
        method: 'POST',
        body: JSON.stringify({
          ...form,
          source_ids: [Number(form.source_id)],
          date_end: form.date_start,
        }),
      }, token)
      setForm(empty)
      await load()
    } catch (err) {
      setError((err as Error).message)
    }
  }

  async function remove(id: number) {
    await api(`/api/admin/ceremonies/${id}`, { method: 'DELETE' }, token)
    await load()
  }

  if (!token) {
    return (
      <main>
        <h2 className="font-display text-3xl">Admin</h2>
        <form className="mt-4 space-y-3" onSubmit={login}>
          <input className="tap w-full rounded-xl border border-line bg-card px-3" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" />
          <input className="tap w-full rounded-xl border border-line bg-card px-3" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" placeholder={tx('Kata sandi demo', 'Demo password')} />
          <button className="tap rounded-xl bg-ink px-4 text-paper" type="submit">{tx('Masuk', 'Log in')}</button>
        </form>
        {error && <p className="mt-3 text-sm text-brick">{error}</p>}
      </main>
    )
  }

  return (
    <main>
      <div className="flex items-center justify-between">
        <h2 className="font-display text-3xl">{tx('Upacara', 'Ceremonies')}</h2>
        <button type="button" className="text-sm underline" onClick={() => { sessionStorage.removeItem('sanggah.token'); setToken('') }}>{tx('Keluar', 'Log out')}</button>
      </div>
      {error && <p className="mt-3 text-sm text-brick">{error}</p>}
      <ul className="mt-4 divide-y divide-line rounded-2xl border border-line bg-card">
        {rows.map((row) => (
          <li key={row.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
            <span>{row.date_start} · {row.name_id} · {row.status}</span>
            <button type="button" className="underline" onClick={() => remove(row.id)}>{tx('Hapus', 'Delete')}</button>
          </li>
        ))}
      </ul>
      <form className="mt-6 grid gap-2" onSubmit={create}>
        <h3 className="font-display text-xl">{tx('Tambah', 'Add')}</h3>
        <input className="tap rounded-xl border border-line px-3" required placeholder="Nama ID" value={form.name_id} onChange={(e) => setForm({ ...form, name_id: e.target.value })} />
        <input className="tap rounded-xl border border-line px-3" required placeholder="Name EN" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} />
        <input className="tap rounded-xl border border-line px-3" required type="date" value={form.date_start} onChange={(e) => setForm({ ...form, date_start: e.target.value })} />
        <select className="tap rounded-xl border border-line px-3" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
          <option value="hari_raya">hari_raya</option>
          <option value="rerahinan">rerahinan</option>
          <option value="lokal">lokal</option>
        </select>
        <textarea className="rounded-xl border border-line px-3 py-2" required placeholder="Tips ID" value={form.tips_id} onChange={(e) => setForm({ ...form, tips_id: e.target.value })} />
        <textarea className="rounded-xl border border-line px-3 py-2" required placeholder="Tips EN" value={form.tips_en} onChange={(e) => setForm({ ...form, tips_en: e.target.value })} />
        <input className="tap rounded-xl border border-line px-3" placeholder="Ringkasan ID" value={form.summary_id} onChange={(e) => setForm({ ...form, summary_id: e.target.value })} />
        <input className="tap rounded-xl border border-line px-3" placeholder="Summary EN" value={form.summary_en} onChange={(e) => setForm({ ...form, summary_en: e.target.value })} />
        <select className="tap rounded-xl border border-line px-3" required value={form.source_id} onChange={(e) => setForm({ ...form, source_id: e.target.value })}>
          <option value="">{tx('Sumber', 'Source')}</option>
          {sources.map((source) => <option key={source.id} value={source.id}>{source.title}</option>)}
        </select>
        <select className="tap rounded-xl border border-line px-3" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
          <option value="draft">draft</option>
          <option value="published">published</option>
        </select>
        <button className="tap rounded-xl bg-brick px-4 text-paper" type="submit">{tx('Simpan', 'Save')}</button>
      </form>
    </main>
  )
}
