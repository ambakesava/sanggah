import { useLang } from '../lib/i18n.tsx'

export default function Privasi() {
  const { tx } = useLang()
  return (
    <main className="space-y-4 text-sm leading-relaxed">
      <h2 className="font-display text-3xl">{tx('Privasi', 'Privacy')}</h2>
      <p>
        {tx(
          'Foto tidak disimpan kecuali Anda menyalakan izin. Bawaan tombol itu mati. Berkas diunggah diproses di memori, dikodekan ulang menjadi JPEG agar data EXIF dan lokasi hilang, lalu berkas sementara PHP dibuang pada akhir permintaan.',
          'Photos are not stored unless you turn consent on. That switch is off by default. An upload is handled in memory, re-encoded to JPEG so EXIF and location are removed, and PHP deletes its temporary file at the end of the request.',
        )}
      </p>
      <p>
        {tx(
          'Jika izin menyala, JPEG tanpa EXIF disimpan di storage privat server dan sebuah nomor izin dikembalikan. Cabut izin dari halaman Pindai untuk menghapus berkas itu. MVP ini belum menjalankan penghapusan otomatis berdasarkan umur berkas.',
          'If consent is on, the EXIF-free JPEG is kept in the server private storage and a consent id is returned. Revoke it from the Scan page to delete the file. This MVP does not yet run an age-based deletion job.',
        )}
      </p>
      <p>
        {tx(
          'Yang dikirim ke penyedia AI, bila kunci API terpasang, hanyalah gambar terkompresi tanpa EXIF beserta teks instruksi. Nama, email, dan alamat IP tidak ikut. Tanpa kunci, permintaan dijawab penyedia tiruan di server sendiri.',
          'What is sent to an AI provider, when an API key is set, is the compressed image without EXIF plus the instruction text. Name, email, and IP address are not included. Without a key, a local mock provider answers.',
        )}
      </p>
      <p>
        {tx(
          'Log aplikasi tidak menyimpan gambar. Umpan balik tidak meminta nama. Alamat IP tidak ditulis mentah ke log; batas permintaan memakai IP di memori pembatas laju.',
          'Application logs do not keep images. Feedback does not ask for a name. Raw IP addresses are not written into the log; rate limits use the IP only inside the limiter.',
        )}
      </p>
    </main>
  )
}
