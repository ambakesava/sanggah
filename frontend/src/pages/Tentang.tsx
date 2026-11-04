import { useLang } from '../lib/i18n.tsx'

export default function Tentang() {
  const { tx } = useLang()
  return (
    <main className="space-y-4 text-sm leading-relaxed">
      <h2 className="font-display text-3xl">{tx('Tentang', 'About')}</h2>
      <p>
        {tx(
          'Sanggah adalah pendamping, bukan otoritas adat. Keputusan dewasa ayu yang mengikat tetap pada pemangku atau sulinggih. Praktik berbeda menurut desa, kala, dan patra.',
          'Sanggah is a companion, not a customary authority. A binding dewasa ayu decision stays with a pemangku or sulinggih. Practice differs by place, time, and circumstance.',
        )}
      </p>
      <p>
        {tx(
          'Penjelasan objek diambil dari kartu terkurasi yang bersumber. Jika model bahasa dipakai, ia hanya boleh menulis dari potongan sumber itu dan setiap kalimat faktual harus bersitasi. Tanggal Bali tidak pernah dihasilkan oleh model.',
          'Object explanations come from sourced curated cards. When a language model is used, it may write only from those source chunks, and every factual sentence must carry a citation. Balinese dates are never produced by the model.',
        )}
      </p>
      <p>
        {tx(
          'Lima belas kelas: candi bentar, kori agung, meru, padmasana, canang sari, gebogan, penjor, lamak, ogoh-ogoh, barong, rangda, kain poleng, tedung, kulkul, dan busana adat.',
          'Fifteen classes: candi bentar, kori agung, meru, padmasana, canang sari, gebogan, penjor, lamak, ogoh-ogoh, barong, rangda, poleng cloth, tedung, kulkul, and ceremonial dress.',
        )}
      </p>
      <p>
        {tx(
          'Tim Weekly Limit: I Nyoman Kailash Kesava-Hrsikesa dan Ferdinand Majesty Hendriknas Wangania.',
          'Team Weekly Limit: I Nyoman Kailash Kesava-Hrsikesa and Ferdinand Majesty Hendriknas Wangania.',
        )}
      </p>
    </main>
  )
}
