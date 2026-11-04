<?php

namespace Database\Seeders;

use App\Models\Ceremony;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Seeder;

class CeremonySeeder extends Seeder
{
    public function run(): void
    {
        $source = Source::query()->where('code', 'bimas-hindu-2026')->first();
        $adminId = User::query()->where('email', config('sanggah.demo.email'))->value('id');
        $roadNyepiId = 'Pada Hari Raya Nyepi, lalu lintas umum di Bali berhenti: bandara dan pelabuhan tutup, jalan tidak untuk bepergian, sesuai Catur Brata Penyepian. Ini pola yang sudah diketahui, bukan pantauan lalu lintas langsung.';
        $roadNyepiEn = 'On Nyepi, ordinary traffic in Bali stops: the airport and harbours close and the roads are not for travel, under the Catur Brata. This is a known pattern, not a live traffic report.';
        $roadSoftId = 'Di sekitar pura dan jalur prosesi bisa ramai atau menyempit. Tidak ada penutupan jalan tertentu yang diklaim di sini. Informasi kurasi, bukan real-time.';
        $roadSoftEn = 'Streets near temples and processions can be crowded or narrow. No specific road closure is claimed here. Curated information, not real-time.';

        $rows = [
            $this->row('siwa-ratri-2026', 'Hari Suci Siwa Ratri', 'Siwa Ratri', '2026-01-17', 'Malam pelepasan pada Tilem Kapitu menurut daftar libur fakultatif.', 'A night of release at Tilem Kapitu on the facultative-holiday list.', 'Siwa Ratri biasanya diisi dengan berjaga dan menyucikan diri. Tamu tidak perlu ikut kecuali diundang. Tanggal libur mengikuti surat Bimas Hindu. Daftar purnama-tilem kalenderbali.org menaruh Tilem Kepitu pada 18 Januari, selisih yang dicatat agar tidak disembunyikan.', 'Siwa Ratri is kept with vigil and purification. Visitors need not join unless invited. The holiday date follows the Bimas Hindu letter. kalenderbali.org places Tilem Kepitu on 18 January, a one-day difference we record rather than hide.', null, null),
            $this->row('tawur-kesanga-2026', 'Tawur Agung Kesanga dan Pengerupukan', 'Tawur Agung Kesanga and Pengerupukan', '2026-03-18', 'Sehari sebelum Nyepi: tawur di perempatan dan arak ogoh-ogoh pada malam harinya.', 'The day before Nyepi: tawur at the crossroads and the ogoh-ogoh parade at night.', 'Tonton arak-arakan dari tepi jalan. Jangan memanjat ogoh-ogoh. Malam ini masih boleh di luar. Besoknya, Nyepi, tidak.', 'Watch the parade from the roadside. Do not climb an ogoh-ogoh. This night is still outdoors. The next day, Nyepi, is not.', $roadSoftId, $roadSoftEn),
            $this->row('nyepi-2026', 'Hari Suci Nyepi Tahun Saka 1948', 'Nyepi, Saka year 1948', '2026-03-19', 'Tahun Baru Saka. Sehari setelah Tilem Kesanga. Libur nasional.', 'The Saka new year, the day after Tilem Kesanga. A national public holiday.', 'Catur Brata Penyepian: amati geni, amati karya, amati lelungaan, amati lelanguan. Tamu tinggal di penginapan, lampu redup, tidak bepergian, tidak ada hiburan. Bandara Bali tutup pada hari ini.', 'The Catur Brata: no fire or bright lights, no work, no travel, no entertainment. Guests stay inside, keep lights low, and do not travel. Bali\'s airport is closed that day.', $roadNyepiId, $roadNyepiEn),
            $this->row('ngembak-geni-2026', 'Ngembak Geni', 'Ngembak Geni', '2026-03-20', 'Hari setelah Nyepi, saling maaf dan kunjungan keluarga.', 'The day after Nyepi, for forgiveness and family visits.', 'Suasana kembali bergerak, tetapi hormati rumah yang masih menerima tamu keluarga. Jangan menganggap seluruh pulau sudah kembali seperti hari biasa sejak pagi.', 'Movement returns, but respect homes that are receiving family. Do not assume the whole island is back to an ordinary morning.', $roadSoftId, $roadSoftEn),
            $this->row('saraswati-2026-04', 'Hari Raya Saraswati', 'Saraswati', '2026-04-04', 'Saniscara Umanis Watugunung. Penghormatan kepada ilmu dan buku.', 'Saniscara Umanis Watugunung. Honour for learning and books.', 'Buku dan lontar tidak dibaca sembarangan pada hari ini di banyak rumah. Jangan memindahkan sesaji di pelinggih ilmu.', 'In many homes, books and lontar are not read casually on this day. Do not move offerings on a shrine for learning.', $roadSoftId, $roadSoftEn),
            $this->row('pagerwesi-2026-04', 'Hari Raya Pagerwesi', 'Pagerwesi', '2026-04-08', 'Buda Keliwon Sinta, tiga hari setelah Saraswati April.', 'Buda Keliwon Sinta, three days after the April Saraswati.', 'Pagerwesi dimaknai sebagai penguatan budi setelah Saraswati. Ikuti pura setempat jika ingin bersembahyang, dan kenakan busana pura.', 'Pagerwesi is kept as a strengthening of character after Saraswati. Follow the local temple if you join prayer, and wear temple dress.', $roadSoftId, $roadSoftEn),
            $this->row('penampahan-galungan-2026', 'Penampahan Galungan', 'Penampahan Galungan', '2026-06-16', 'Sehari sebelum Galungan. Banyak keluarga menyembelih dan memasak.', 'The day before Galungan. Many families prepare the feast.', 'Jalan kampung bisa ramai karena penjor mulai berdiri dan keluarga memasak. Tidak ada penutupan jalan provinsi yang diklaim di sini.', 'Village streets can be busy as penjor go up and families cook. No provincial road closure is claimed here.', $roadSoftId, $roadSoftEn),
            $this->row('galungan-2026-06', 'Hari Raya Galungan', 'Galungan', '2026-06-17', 'Buda Keliwon Dunggulan. Hari kemenangan dharma.', 'Buda Keliwon Dunggulan. The day dharma is said to prevail.', 'Penjor terpasang di sepanjang jalan. Jangan merusaknya. Jika masuk pura, kenakan kain dan selendang. Prosesi lokal bisa memperlambat jalan desa.', 'Penjor line the roads. Do not damage them. If you enter a temple, wear a cloth and sash. Local processions can slow a village road.', $roadSoftId, $roadSoftEn),
            $this->row('umanis-galungan-2026', 'Umanis Galungan', 'Umanis Galungan', '2026-06-18', 'Sehari setelah Galungan, kunjungan ke keluarga.', 'The day after Galungan, for family visits.', 'Hormati rumah yang menerima kerabat. Jalan tidak otomatis ditutup.', 'Respect homes receiving relatives. Roads are not automatically closed.', $roadSoftId, $roadSoftEn),
            $this->row('penampahan-kuningan-2026', 'Penampahan Kuningan', 'Penampahan Kuningan', '2026-06-26', 'Sehari sebelum Kuningan.', 'The day before Kuningan.', 'Persiapan sesaji terakhir sebelum Kuningan. Jangan mengambil jajan dari banten.', 'Final offering preparations before Kuningan. Do not take cakes from a banten.', $roadSoftId, $roadSoftEn),
            $this->row('kuningan-2026-06', 'Hari Raya Kuningan', 'Kuningan', '2026-06-27', 'Saniscara Keliwon Kuningan, sepuluh hari setelah Galungan.', 'Saniscara Keliwon Kuningan, ten days after Galungan.', 'Kuningan menutup rangkaian Galungan. Di banyak rumah, waktu sembahyang pagi lebih ketat. Tanyakan jam buka pura sebelum berkunjung.', 'Kuningan closes the Galungan sequence. In many homes the morning prayer time is strict. Ask when a temple opens before you visit.', $roadSoftId, $roadSoftEn),
            $this->row('saraswati-2026-10', 'Hari Raya Saraswati', 'Saraswati', '2026-10-31', 'Saniscara Umanis Watugunung pada siklus berikutnya.', 'Saniscara Umanis Watugunung in the next cycle.', 'Sama seperti Saraswati April: hormati buku dan lontar, jangan memindahkan sesaji.', 'As with the April Saraswati: respect books and lontar, and do not move offerings.', $roadSoftId, $roadSoftEn),
            $this->row('pagerwesi-2026-11', 'Hari Raya Pagerwesi', 'Pagerwesi', '2026-11-04', 'Buda Keliwon Sinta. Pagerwesi kedua pada 2026.', 'Buda Keliwon Sinta. The second Pagerwesi of 2026.', 'Jika berkunjung ke pura, kenakan busana adat dan ikuti arahan di pintu.', 'If you visit a temple, wear ceremonial dress and follow the gate instructions.', $roadSoftId, $roadSoftEn),
        ];

        foreach ($rows as $row) {
            $ceremony = Ceremony::query()->updateOrCreate(['slug' => $row['slug']], array_merge($row, [
                'status' => 'published',
                'ai_drafted' => false,
                'reviewed_by' => $adminId,
                'published_at' => now(),
            ]));
            if ($source !== null) {
                $ceremony->sources()->sync([$source->id]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $slug, string $nameId, string $nameEn, string $date, string $summaryId, string $summaryEn, string $tipsId, string $tipsEn, ?string $roadId, ?string $roadEn): array
    {
        return [
            'slug' => $slug,
            'name_id' => $nameId,
            'name_en' => $nameEn,
            'type' => 'hari_raya',
            'date_start' => $date,
            'date_end' => $date,
            'summary_id' => $summaryId,
            'summary_en' => $summaryEn,
            'tips_id' => $tipsId,
            'tips_en' => $tipsEn,
            'road_note_id' => $roadId,
            'road_note_en' => $roadEn,
        ];
    }
}
