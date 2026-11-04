<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code' => 'eiseman-1990',
                'title' => 'Bali: Sekala & Niskala, Volume I',
                'publisher' => 'Fred B. Eiseman Jr., Periplus Editions',
                'url' => null,
                'type' => 'buku',
                'lang' => 'en',
                'license' => 'Kutipan parafrase singkat untuk edukasi. Buku tetap berhak cipta.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Acuan umum tata pura, sesaji, barong, penjor, dan busana. Isi kartu adalah parafrase konservatif, bukan salinan halaman.',
            ],
            [
                'code' => 'covarrubias-1937',
                'title' => 'Island of Bali',
                'publisher' => 'Miguel Covarrubias, Alfred A. Knopf',
                'url' => null,
                'type' => 'buku',
                'lang' => 'en',
                'license' => 'Parafrase singkat. Beberapa pengamatan buku ini sudah tua.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Dipakai hanya untuk bentuk gapura, Barong, dan Rangda. Tidak dipakai untuk aturan yang bisa berubah.',
            ],
            [
                'code' => 'bimas-hindu-2026',
                'title' => 'Libur fakultatif hari raya Hindu 2026 (surat B-253/DJ.VI/Dt.VI.I.3/BA.03/09/2025)',
                'publisher' => 'Ditjen Bimas Hindu Kemenag RI, dikutip detikNews 5 Januari 2026',
                'url' => 'https://bimashindu.kemenag.go.id/storage/files/Hari%20Libur%20Nasional,%20Libur%20Fakultatif,%20dan%20Cuti%20Bersama%20Tahun%202026.pdf',
                'type' => 'pemerintah',
                'lang' => 'id',
                'license' => 'Berita yang mengutip surat resmi.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Sumber tanggal Galungan, Kuningan, Nyepi, Pagerwesi, Saraswati, dan Siwa Ratri 2026. Tanggal 2027 tidak diisi karena belum diverifikasi dari surat resmi.',
            ],
            [
                'code' => 'kalenderbali-2026',
                'title' => 'Purnama-Tilem tahun 2026',
                'publisher' => 'kalenderbali.org',
                'url' => 'http://www.kalenderbali.org/purnamatilem.php?tahun=2026',
                'type' => 'lainnya',
                'lang' => 'id',
                'license' => 'Daftar tanggal yang dipublikasikan, dikutip dengan atribusi.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Tabel sasih MVP. Bukan hitungan astronomis. Ejaan sasih mengikuti situs ini (Kepitu, Kewulu, Kesanga, Kedasa, dan seterusnya). Siwa Ratri resmi 17 Januari 2026 dapat selisih sehari dari Tilem Kepitu 18 Januari di daftar ini.',
            ],
            [
                'code' => 'kalenderbali-alaayu',
                'title' => 'Wewaran penyusun ala-ayuning dewasa',
                'publisher' => 'kalenderbali.org',
                'url' => 'https://kalenderbali.org/referensialaayu.php',
                'type' => 'lainnya',
                'lang' => 'id',
                'license' => 'Daftar ringkas yang dikutip, bukan salinan seluruh tabel.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Subset Ayu Nulus saja. Praktik wariga berbeda antar daerah. Bukan keputusan dewasa ayu.',
            ],
            [
                'code' => 'sastrabali-wariga',
                'title' => 'Rumus menghitung wariga (catatan Ala Dahat)',
                'publisher' => 'Sastra Bali',
                'url' => 'https://sastrabali.com/rumus-menghitung-wariga/',
                'type' => 'lainnya',
                'lang' => 'id',
                'license' => 'Catatan publik. Dipakai sebagai satu sumber, bukan otoritas tunggal.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Ala Dahat: Saniscara yang jatuh pada Purnama atau Tilem, untuk kegiatan memulai sesuatu.',
            ],
            [
                'code' => 'dershowitz-2018',
                'title' => 'Calendrical Calculations, bab Pawukon',
                'publisher' => 'Nachum Dershowitz dan Edward M. Reingold, Cambridge University Press, 2018',
                'url' => 'https://en.wikipedia.org/wiki/Pawukon_calendar',
                'type' => 'akademik',
                'lang' => 'en',
                'license' => 'Algoritma kalender. Tabel 210 hari tidak disalin ke repositori.',
                'accessed_at' => '2026-11-04',
                'validated_by' => 'tim',
                'notes' => 'Epoch 2020-07-05 = hari ke-1. Wikipedia Pawukon merangkum siklus yang sama dan dipakai sebagai pemeriksaan silang ejaan wewaran.',
            ],
        ];

        foreach ($rows as $row) {
            Source::query()->updateOrCreate(['code' => $row['code']], $row);
        }
    }
}
