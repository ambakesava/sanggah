<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class Locale
{
    public static function fromRequest(Request $request): string
    {
        $lang = $request->input('lang', $request->query('lang'));
        if (! is_string($lang) || $lang === '') {
            $header = strtolower((string) $request->header('Accept-Language', 'id'));
            $lang = str_starts_with($header, 'en') ? 'en' : 'id';
        }

        return $lang === 'en' ? 'en' : 'id';
    }
}
