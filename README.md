# Sanggah

## Description

Sanggah adalah pendamping budaya Bali berbasis web untuk AI Web Innovation Challenge, Bali DigiFest 2026. Pengguna memotret objek budaya, bertanya tentang kalender Bali dengan bahasa sehari-hari, dan membaca hari raya yang dikurasi. Tanggal pawukon dihitung oleh mesin deterministik di PHP. Model bahasa tidak boleh menciptakan tanggal.

## Problem & Target User

Wisatawan dan generasi muda Bali sering tidak tahu nama objek yang dilihat, aturan busana di pura, atau letak suatu hari pada kalender pawukon dan sasih. Informasi tersebar, dan jawaban chatbot biasa mudah mengarang tanggal. Pengguna utama: wisatawan (Indonesia dan Inggris), warga yang ingin cek hari baik secara informatif, dan admin konten tim. Sanggah tidak menggantikan pemangku atau sulinggih.

## Key Features

- **Pindai & jelaskan.** Unggah JPEG, PNG, atau WebP (maks. 8 MB, dikompresi di peramban). Lima belas kelas. Keyakinan di bawah 0,6, kelas `unknown`, atau foto yang subjeknya orang menghasilkan status tidak yakin plus kandidat. Penjelasan punya makna, etika, busana, dan larangan, dengan sitasi.
- **Tanya Dewasa Ayu.** Model hanya mengurai maksud. Mesin pawukon 210 hari menghitung wuku dan sepuluh wewaran. Nyepi, Purnama, dan Tilem datang dari tabel `sasih_calendar` 2026, bukan dari model. Jawaban yang menambah tanggal di luar payload diganti templat deterministik.
- **Upacara.** Daftar dan detail publik, filter tanggal, catatan jalan berlabel informasi kurasi. Admin Sanctum membuat, mengubah, dan menghapus upacara.
- **Ketahanan.** Kode galat seragam, batas laju, cache respons AI, fallback Gemini dan Qwen, serta `MockProvider` bila tidak ada kunci API. Foto tidak disimpan kecuali izin dinyalakan.

## Tech Stack

- Frontend: React, Vite, TypeScript, Tailwind CSS. Direktori `frontend/`.
- Backend: Laravel 12, PHP 8.3+ (kompatibel dengan 8.5), Laravel Sanctum. Direktori `backend/`.
- Basis data: SQLite untuk pengembangan dan uji. MySQL untuk penyebaran bila diinginkan.
- AI: Gemini (`gemini-3.5-flash-lite`, dapat diganti) dan Qwen di Alibaba Cloud Model Studio internasional/Singapura (`qwen3-vl-flash`, endpoint OpenAI-compatible). Keduanya lewat `AiService`.

## AI Implementation

Panggilan model hanya terjadi di backend, lewat antarmuka `AiService`:

- `identify` mengklasifikasi foto ke salah satu slug atau `unknown`.
- `generate` mengurai maksud kalender dan, bila kunci ada, menulis penjelasan yang diikat konteks.
- `embed` ada pada antarmuka. MVP belum memakai basis vektor.

`ResilientAiService` memilih penyedia utama dari `AI_PROVIDER`. Jika yang satu gagal dan kunci penyedia lain terisi, panggilan diulang ke sana satu kali. Anggaran harian (`AI_DAILY_BUDGET_CALLS`) dan cache (pindaian 24 jam, penjelasan 7 hari) menjaga kuota. `AI_FORCE_FAIL=true` memaksa jalur galat agar juri bisa melihat fallback.

Tanpa `GEMINI_API_KEY` dan `QWEN_API_KEY`, `MockProvider` menjawab di server. Untuk demo pindai, nama berkas yang memuat slug (misalnya `penjor.jpg`) dikenali dengan keyakinan 0,91. Berkas lain menghasilkan tidak yakin. Mock tidak menghitung pawukon. Tanggal eksplisit dan frasa seperti "minggu depan" diselesaikan di PHP (zona Asia/Makassar).

Retrieval MVP bersifat leksikal atas potongan teks yang diunggah ke tabel `chunks`, lalu validator membuang kalimat model yang tidak bersitasi. Kartu statis di tabel `objects` tetap tampil bila model gagal atau tidak dipasang.

Ambang keyakinan 0,6 belum dikalibrasi pada set foto uji. Angka keyakinan model tidak terkalibrasi. Nilai ini target desain, bukan hasil ukur.

## Architecture / Data Flow

```
Pengguna
  -> React (Vite)
  -> Laravel /api
       -> validasi berkas, buang EXIF
       -> AiService (Gemini, jika gagal Qwen, atau Mock)
       -> potongan pengetahuan + validator sitasi
       -> CalendarEngine (murni) + sasih_calendar + calendar_rules
  -> MySQL atau SQLite
  -> JSON ke antarmuka
```

Mesin kalender tidak membaca jaringan. Epoch pawukon: 2020-07-05 = hari ke-1 (Redite Paing, wuku Sinta), selaras dengan siklus yang diringkas Dershowitz & Reingold dan tabel pawukon yang terbit. Sasih 2026 disalin dari daftar purnama-tilem kalenderbali.org. Tanggal Nyepi dan hari raya pawukon 2026 mengikuti surat Ditjen Bimas Hindu yang dikutip detikNews.

## Installation / Run Instructions

Perintah dijalankan dari folder yang disebutkan. Di Windows, PHP, Composer, dan Node harus ada di PATH.

Yang perlu terpasang:

- PHP 8.3 atau lebih baru, termasuk PHP 8.5. Ekstensi: `curl`, `mbstring`, `openssl`, `pdo_sqlite`, `pdo_mysql`, `gd`, `fileinfo`, `zip`, `intl`.
- Composer 2.
- Node.js 20 atau lebih baru (uji ini memakai Node 22) dan npm.

### SQLite (paling cepat)

```bat
cd backend
copy .env.example .env
composer install
composer setup
php artisan serve
```

`composer setup` menyalin `.env` bila belum ada, membuat `APP_KEY`, membuat `database/database.sqlite` bila belum ada, lalu migrasi dan seed. Di macOS atau Linux, ganti `copy` dengan `cp` bila Anda menyalin manual. `composer setup` sendiri memakai PHP, jadi berlaku di Windows.

Terminal kedua:

```bat
cd frontend
copy .env.example .env
npm install
npm run dev
```

Buka http://localhost:5173 . Biarkan `VITE_API_BASE_URL` kosong. Vite meneruskan `/api` ke http://127.0.0.1:8000 .

Uji backend:

```bat
cd backend
php artisan test
```

Bangun frontend:

```bat
cd frontend
npm run build
```

### MySQL

Buat basis data `sanggah`. Di `backend/.env`, beri komentar pada dua baris SQLite dan isi blok MySQL (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Lalu:

```bat
cd backend
php artisan migrate --seed
php artisan serve
```

### Petunjuk demo tanpa kunci API

- Pindai: unggah berkas bernama `penjor.jpg` atau pilih kelas secara manual. Berkas lain menampilkan tidak yakin.
- Tanya: "is there a ceremony on 17 June 2026?" harus menyebut Galungan, Buda Keliwon, wuku Dunggulan, hari pawukon 74.
- Nyepi 2026-03-19, Kuningan 2026-06-27, dan Pagerwesi 2026-11-04 ada di kalender dan di daftar upacara.

## Deployment URL

Belum ada URL publik. MVP ini dijalankan secara lokal.

## Deployment (planned)

- Backend: Laravel di Railway atau Render (PHP 8.3+, SQLite pada volume atau MySQL).
- Frontend: Vercel. Sebelum `npm run build`, isi `VITE_API_BASE_URL` dengan origin API, tanpa garis miring di akhir.
- CORS: isi `CORS_ALLOWED_ORIGINS` di backend dengan origin Vercel.
- Demo URL: `[URL_DEMO]`

Rahasia tetap di variabel lingkungan host, tidak di repositori. Frontend tidak memegang kunci API.

## Demo Account

Akun admin lokal untuk juri, dibuat oleh seeder:

- Email: `juri@sanggah.test`
- Kata sandi: `Sanggah-Juri-2026`

Ini kata sandi demo lokal, bukan rahasia produksi. Ganti sebelum penyebaran publik, dan kirim kredensial yang dipakai juri lewat formulir panitia. Masuk lewat halaman Admin.

## Known Limitations

- Belum ada URL penyebaran. Set foto uji vision dan angka akurasinya belum diukur. Jangan menganggap keyakinan model sebagai probabilitas terkalibrasi.
- Retrieval adalah pencocokan leksikal, bukan basis vektor. `vector_id` kosong. Endpoint ingest admin hanya menandai chunk sebagai `lexical-v1`.
- Daftar rerainan dicoba diambil langsung dari halaman `rerainan.php` di kalenderbali.org (situs itu tidak menyediakan API JSON). Cloudflare sering menolak permintaan dari server; kalau itu terjadi, tanggal pawukon dan surat Bimas Hindu tetap dipakai.
- Siwa Ratri pada surat Bimas Hindu jatuh 17 Januari 2026. kalenderbali.org menaruh Tilem Kepitu pada 18 Januari 2026. Keduanya dicatat, tidak digabung diam-diam.
- Aturan dewasa ayu hanya subset kecil yang bersumber (Ayu Nulus dan Sri Tumpuk untuk buka usaha; Amerta Bumi, Kamajaya, dan Derman Bagia untuk nikah; Ala Dahat; larangan kerja pada Nyepi). Bukan sistem wariga lengkap dan bukan keputusan.
- Etiquette Check (foto pakaian) di luar cakupan. Riwayat pindai pengguna dan draf tips AI untuk admin belum dibuat sebagai alur penuh. Umpan balik dan unduhan `.ics` sudah ada.
- Foto berizin dihapus saat izin dicabut. Belum ada pekerjaan terjadwal yang menghapus berkas berdasarkan umur.
- Ejaan sasih mengikuti kalenderbali.org untuk tahun ini (misalnya Kepitu, Kewulu, Kedasa). Ejaan lain seperti Kapitu atau Kadasa juga dipakai di sumber berbeda.

## Third-party Resources / Credits

- Laravel, Sanctum, React, Vite, Tailwind CSS, dan PHPUnit. Lisensi masing-masing paket.
- Algoritma pawukon: Nachum Dershowitz & Edward M. Reingold, *Calendrical Calculations* (Cambridge University Press, 2018), dengan pemeriksaan silang ringkasan tabel di Wikipedia "Pawukon calendar".
- Tanggal hari raya 2026: surat Ditjen Bimas Hindu Kemenag RI No. B-253/DJ.VI/Dt.VI.I.3/BA.03/09/2025, sebagaimana dikutip detikNews, 5 Januari 2026.
- Purnama dan Tilem 2026: kalenderbali.org. Subset Ala-Ayuning Dewasa (Ayu Nulus, Amerta Bumi, Kamajaya, Derman Bagia) dari halaman referensi situs yang sama.
- Catatan Ala Dahat: sastrabali.com, "Rumus menghitung wariga".
- Isi kartu objek adalah parafrase konservatif yang merujuk Fred B. Eiseman Jr., *Bali: Sekala & Niskala*, Volume I (Periplus), dan untuk gapura, Barong, serta Rangda juga Miguel Covarrubias, *Island of Bali* (1937). Bukan salinan halaman. Beberapa pengamatan Covarrubias sudah tua dan hanya dipakai untuk bentuk, bukan untuk aturan yang berubah.
- Huruf: Fraunces dan Source Sans 3 (Google Fonts).
- Model: Google Gemini dan Alibaba Cloud Model Studio (Qwen), hanya bila kunci diisi.

## Team Members

- Tim: Weekly Limit
- I Nyoman Kailash Kesava-Hrsikesa
- Ferdinand Majesty Hendriknas Wangania

Pembagian peran rinci belum dicatat di luar catatan PRD bahwa Kailash mengerjakan frontend (React + Laravel). Peran Ferdinand dapat ditambahkan kemudian.

## AI & Technology Disclosure

| Komponen | Isi |
| --- | --- |
| Nama produk | Sanggah: Bali Culture Companion |
| Framework / frontend | React, Vite, TypeScript, Tailwind CSS |
| Backend / runtime | Laravel 12, PHP 8.3+ |
| Basis data | SQLite (lokal dan uji), MySQL (siap dipakai) |
| Hosting | Belum. Rencana: API di Railway atau Render, UI di Vercel. |
| Model AI | Gemini `gemini-3.5-flash-lite` dan Qwen `qwen3-vl-flash` (dapat diganti lewat env). Mock lokal bila kunci kosong. |
| Peran AI | Klasifikasi foto dan uraian maksud bahasa. Bukan penghitung tanggal. |
| Dataset | Kartu dan chunk yang ditulis tim dari sumber pada bagian kredit. Bukan hasil latih ulang model. |
| Pustaka | Laravel, Sanctum, React, Vite, Tailwind, PHPUnit, Guzzle (lewat Laravel HTTP). |
| Template | Kerangka `laravel/laravel` dan `create-vite` react-ts. |
| Asisten pengodean | Cursor (termasuk model di dalamnya) dipakai untuk menulis dan meninjau kode MVP ini. Tim tetap bertanggung jawab atas kode, uji, dan isi budaya. |
| Komponen lama | Tidak ada. Repositori ini dimulai untuk lomba. |
| Aset | Tidak ada foto stok. Ikon favicon digambar sebagai SVG sederhana. |

Pernyataan: informasi di atas diisi sejujurnya untuk versi repositori ini. Tim bertanggung jawab atas kode, data, pustaka, API, dan aset yang dipakai.

## License

MIT. Lihat berkas `LICENSE`. Pengetahuan budaya Bali bukan milik tim. Sumber dicantumkan di seeder dan di kartu penjelasan.
