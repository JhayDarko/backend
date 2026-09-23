<?php

namespace App\Services\FactCheck;

final class FactCheckRatingNormalizer
{
    /**
     * @param string|null $rawRating
     */
    public static function normalize(?string $rawRating): array
    {
        $value = trim((string) ($rawRating ?? ''));

        if ($value === '') {
            return [
                'raw_rating' => '',
                'normalized_rating' => 'unknown',
            ];
        }

        $normalized = strtolower($value);

        $map = [
            'true' => 'true',
            'mostly true' => 'mostly_true',
            'mostly-true' => 'mostly_true',
            'mostly_true' => 'mostly_true',
            'mixed' => 'mixed',
            'mixture' => 'mixed',
            'mostly false' => 'mostly_false',
            'mostly-false' => 'mostly_false',
            'mostly_false' => 'mostly_false',
            'false' => 'false',
            'not true' => 'false',
            'incorrect' => 'false',
            'unsupported' => 'unknown',
            'unverified' => 'unknown',
            'unknown' => 'unknown',
            'not enough information' => 'unknown',
            'not enough evidence' => 'unknown',
            'ambiguous' => 'unknown',
            'conflicting' => 'unknown',
        ];

        $match = $map[$normalized] ?? null;

        if ($match !== null) {
            return [
                'raw_rating' => $value,
                'normalized_rating' => $match,
            ];
        }

        foreach ($map as $key => $mapped) {
            if (str_contains($normalized, $key)) {
                return [
                    'raw_rating' => $value,
                    'normalized_rating' => $mapped,
                ];
            }
        }

        return [
            'raw_rating' => $value,
            'normalized_rating' => 'unknown',
        ];
    }
}
