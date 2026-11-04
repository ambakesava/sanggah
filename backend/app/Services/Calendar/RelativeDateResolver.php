<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Resolves everyday date phrases in Asia/Makassar (WITA).
 * The language model is not allowed to do this.
 */
final class RelativeDateResolver
{
    /**
     * @return array{from: string, to: string}|null
     */
    public function resolve(?string $expression, ?DateTimeImmutable $today = null): ?array
    {
        if ($expression === null) {
            return null;
        }
        $expression = mb_strtolower(trim($expression));
        if ($expression === '') {
            return null;
        }
        $today = ($today ?? new DateTimeImmutable('now', new DateTimeZone(CalendarEngine::TIMEZONE)))->setTime(0, 0);

        return match ($expression) {
            'hari ini', 'today' => $this->span($today, $today),
            'besok', 'tomorrow' => $this->span($today->modify('+1 day'), $today->modify('+1 day')),
            'lusa' => $this->span($today->modify('+2 day'), $today->modify('+2 day')),
            'minggu ini', 'this week' => $this->span($today, $this->sundayOnOrAfter($today)),
            'minggu depan', 'next week' => $this->nextWeek($today),
            'bulan depan', 'next month' => $this->nextMonth($today),
            default => null,
        };
    }

    /**
     * @return array{from: string, to: string}
     */
    private function nextWeek(DateTimeImmutable $today): array
    {
        $dow = (int) $today->format('N');
        $start = $today->modify('+'.(8 - $dow).' day');

        return $this->span($start, $start->modify('+6 day'));
    }

    /**
     * @return array{from: string, to: string}
     */
    private function nextMonth(DateTimeImmutable $today): array
    {
        $start = $today->modify('first day of next month')->setTime(0, 0);
        $end = $start->modify('last day of this month')->setTime(0, 0);

        return $this->span($start, $end);
    }

    private function sundayOnOrAfter(DateTimeImmutable $today): DateTimeImmutable
    {
        $dow = (int) $today->format('N');
        if ($dow === 7) {
            return $today;
        }

        return $today->modify('+'.(7 - $dow).' day');
    }

    /**
     * @return array{from: string, to: string}
     */
    private function span(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }
}
