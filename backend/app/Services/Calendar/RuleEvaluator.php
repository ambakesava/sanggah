<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * Evaluates curated calendar_rules against one engine day.
 * Conditions are JSON equality / AND / OR. No I/O.
 */
final class RuleEvaluator
{
    /**
     * @param  array<string, mixed>  $day
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public function match(array $day, array $rules, ?string $activity = null): array
    {
        $matched = [];
        foreach ($rules as $rule) {
            if (($rule['status'] ?? null) !== 'validated') {
                continue;
            }
            if (! $this->appliesToActivity($rule, $activity)) {
                continue;
            }
            $conditions = $rule['conditions'] ?? [];
            if (! is_array($conditions) || ! $this->matches($day, $conditions)) {
                continue;
            }
            $matched[] = $rule;
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function appliesToActivity(array $rule, ?string $activity): bool
    {
        if ($activity === null || $activity === '') {
            return true;
        }
        $tags = $rule['activity_tags'] ?? [];
        if (! is_array($tags)) {
            return false;
        }
        if (($rule['effect'] ?? null) === 'good') {
            return in_array($activity, $tags, true);
        }

        return in_array($activity, $tags, true) || in_array('umum', $tags, true);
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $condition
     */
    public function matches(array $day, array $condition): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach ((array) $condition['all'] as $child) {
                if (! is_array($child) || ! $this->matches($day, $child)) {
                    return false;
                }
            }

            return true;
        }
        if (array_key_exists('any', $condition)) {
            foreach ((array) $condition['any'] as $child) {
                if (is_array($child) && $this->matches($day, $child)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists('penanggal', $condition)) {
            if (($day['phase'] ?? null) !== 'penanggal' || (int) ($day['phase_day'] ?? 0) !== (int) $condition['penanggal']) {
                return false;
            }
        }
        if (array_key_exists('pangelong', $condition)) {
            if (($day['phase'] ?? null) !== 'pangelong' || (int) ($day['phase_day'] ?? 0) !== (int) $condition['pangelong']) {
                return false;
            }
        }
        if (array_key_exists('rerahinan_contains', $condition)) {
            $have = $day['rerahinan'] ?? [];
            if (! is_array($have) || ! in_array($condition['rerahinan_contains'], $have, true)) {
                return false;
            }
        }

        foreach ($condition as $key => $value) {
            if (in_array($key, ['all', 'any', 'penanggal', 'pangelong', 'rerahinan_contains'], true)) {
                continue;
            }
            if (($day[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
