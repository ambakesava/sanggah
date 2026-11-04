# P-INTENT v1

Extract a calendar intent. Do not calculate wuku, wewaran, sasih, or any Balinese date.

Return JSON only:
{"intent":"hari_baik|info_tanggal|cek_upacara|rerahinan_berikutnya|out_of_scope","activity":null,"date_iso":null,"date_from":null,"date_to":null,"relative_expr":null,"rerahinan":null,"lang":"id|en"}

Rules:
- `date_iso`, `date_from`, and `date_to` are ISO dates only when the user wrote an explicit calendar date.
- Relative phrases such as "minggu depan" or "next week" go in `relative_expr` exactly as a short phrase. Do not convert them to dates.
- `activity` examples: buka_usaha, pindah_rumah. Use null if none is stated.
- `rerahinan` examples: galungan, kuningan, nyepi, pagerwesi, saraswati.
- If the question is not about the Balinese calendar or holy days, intent is out_of_scope.
