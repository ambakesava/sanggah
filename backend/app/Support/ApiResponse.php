<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(array_merge([
            'request_id' => self::requestId(),
        ], $payload), $status);
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     * @param  array<string, mixed>|null  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        bool $retryable = false,
        ?array $fallback = null,
        ?array $details = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
        ];
        if ($fallback !== null) {
            $error['fallback'] = $fallback;
        }
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'request_id' => self::requestId(),
            'error' => $error,
        ], $status);
    }

    public static function message(string $code, string $lang): string
    {
        $map = [
            'AI_UNAVAILABLE' => [
                'id' => 'Layanan AI sedang tidak tersedia. Anda tetap bisa memilih objek secara manual.',
                'en' => 'The AI service is temporarily unavailable. You can still choose an object manually.',
            ],
            'AI_TIMEOUT' => [
                'id' => 'Layanan AI terlalu lama menjawab. Silakan coba lagi atau pilih objek secara manual.',
                'en' => 'The AI service timed out. Try again, or choose an object manually.',
            ],
            'AI_UPSTREAM_ERROR' => [
                'id' => 'Layanan AI mengembalikan galat. Silakan coba lagi atau pilih objek secara manual.',
                'en' => 'The AI service returned an error. Try again, or choose an object manually.',
            ],
            'AI_BUDGET_EXCEEDED' => [
                'id' => 'Kuota AI harian sudah tercapai. Penjelasan statis dan kalender tetap tersedia.',
                'en' => 'The daily AI quota has been reached. Static explanations and the calendar still work.',
            ],
            'FILE_TOO_LARGE' => [
                'id' => 'Berkas terlalu besar. Batas unggahan adalah 8 MB.',
                'en' => 'The file is too large. The upload limit is 8 MB.',
            ],
            'UNSUPPORTED_MEDIA_TYPE' => [
                'id' => 'Format gambar tidak didukung. Gunakan JPEG, PNG, atau WebP.',
                'en' => 'Unsupported image format. Use JPEG, PNG, or WebP.',
            ],
            'VALIDATION_ERROR' => [
                'id' => 'Data yang dikirim tidak valid.',
                'en' => 'The submitted data is not valid.',
            ],
            'RANGE_TOO_LARGE' => [
                'id' => 'Rentang tanggal terlalu panjang. Persempit hingga 62 hari.',
                'en' => 'The date range is too long. Narrow it to 62 days.',
            ],
            'UNAUTHENTICATED' => [
                'id' => 'Login diperlukan.',
                'en' => 'Authentication is required.',
            ],
            'FORBIDDEN' => [
                'id' => 'Akun ini tidak memiliki akses admin.',
                'en' => 'This account does not have admin access.',
            ],
            'NOT_FOUND' => [
                'id' => 'Data tidak ditemukan.',
                'en' => 'The requested record was not found.',
            ],
            'RATE_LIMITED' => [
                'id' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
                'en' => 'Too many requests. Please wait and try again.',
            ],
            'INTERNAL_ERROR' => [
                'id' => 'Terjadi kesalahan pada server.',
                'en' => 'The server ran into an unexpected error.',
            ],
        ];

        $row = $map[$code] ?? $map['INTERNAL_ERROR'];

        return $row[$lang] ?? $row['id'];
    }

    public static function requestId(): ?string
    {
        $request = request();
        if (! $request instanceof Request) {
            return null;
        }
        $id = $request->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }
}
