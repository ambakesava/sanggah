import { NavLink, Outlet, Route, Routes } from 'react-router-dom'
import Admin from './pages/Admin.tsx'
import Home from './pages/Home.tsx'
import Kalender from './pages/Kalender.tsx'
import Privasi from './pages/Privasi.tsx'
import Scan from './pages/Scan.tsx'
import Tentang from './pages/Tentang.tsx'
import Tanya from './pages/Tanya.tsx'
import Upacara from './pages/Upacara.tsx'
import { useLang } from './lib/i18n.tsx'

const links = [
  { to: '/scan', id: 'Pindai', en: 'Scan' },
  { to: '/tanya', id: 'Tanya', en: 'Ask' },
  { to: '/kalender', id: 'Kalender', en: 'Calendar' },
  { to: '/upacara', id: 'Upacara', en: 'Ceremonies' },
]

export default function App() {
  const { lang, setLang, tx } = useLang()
  return (
    <div className="mx-auto min-h-screen max-w-3xl px-4 pb-24 pt-6 md:pb-10">
      <header className="mb-6 flex items-start justify-between gap-3">
        <NavLink to="/" className="tap block">
          <p className="text-xs font-semibold uppercase tracking-[0.22em] text-gold">Weekly Limit</p>
          <h1 className="font-display text-4xl leading-none text-ink">Sanggah</h1>
          <p className="mt-1 text-sm text-moss">{tx('Pendamping budaya Bali', 'A companion for Balinese culture')}</p>
        </NavLink>
        <button
          type="button"
          className="tap rounded-full border border-line bg-card px-3 text-sm font-semibold"
          onClick={() => setLang(lang === 'id' ? 'en' : 'id')}
          aria-label={tx('Ganti bahasa', 'Change language')}
        >
          {lang === 'id' ? 'EN' : 'ID'}
        </button>
      </header>
      <nav className="mb-6 hidden gap-2 md:flex" aria-label="Utama">
        {links.map((link) => (
          <NavLink key={link.to} to={link.to} className={({ isActive }) => `tap rounded-full px-4 py-2 text-sm font-semibold ${isActive ? 'bg-ink text-paper' : 'bg-card text-ink'}`}>
            {tx(link.id, link.en)}
          </NavLink>
        ))}
      </nav>
      <Routes>
        <Route element={<Outlet />}>
          <Route path="/" element={<Home />} />
          <Route path="/scan" element={<Scan />} />
          <Route path="/tanya" element={<Tanya />} />
          <Route path="/kalender" element={<Kalender />} />
          <Route path="/kalender/:date" element={<Kalender />} />
          <Route path="/upacara" element={<Upacara />} />
          <Route path="/upacara/:slug" element={<Upacara />} />
          <Route path="/admin" element={<Admin />} />
          <Route path="/tentang" element={<Tentang />} />
          <Route path="/privasi" element={<Privasi />} />
        </Route>
      </Routes>
      <footer className="mt-10 flex flex-wrap gap-4 text-sm text-moss">
        <NavLink to="/tentang">{tx('Tentang', 'About')}</NavLink>
        <NavLink to="/privasi">{tx('Privasi', 'Privacy')}</NavLink>
        <NavLink to="/admin">Admin</NavLink>
      </footer>
      <nav className="fixed inset-x-0 bottom-0 z-10 grid grid-cols-4 border-t border-line bg-paper/95 backdrop-blur md:hidden" aria-label="Bawah">
        {links.map((link) => (
          <NavLink key={link.to} to={link.to} className={({ isActive }) => `tap flex items-center justify-center text-sm font-semibold ${isActive ? 'text-brick' : 'text-ink'}`}>
            {tx(link.id, link.en)}
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
