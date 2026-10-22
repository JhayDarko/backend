<?php

namespace App\Services\FactCheck;

final class FactCheckRatingNormalizer
{
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
            'verdadero' => 'true',
            'verdadera' => 'true',
            'mostly true' => 'mostly_true',
            'mostly-true' => 'mostly_true',
            'mostly_true' => 'mostly_true',
            'mostly accurate' => 'mostly_true',
            'largely true' => 'mostly_true',
            'mixed' => 'mixed',
            'mixture' => 'mixed',
            'partly true' => 'mixed',
            'half true' => 'mixed',
            'partly false' => 'mixed',
            'half true half false' => 'mixed',
            'verdad a medias' => 'mixed',
            'parcialmente cierto' => 'mixed',
            'parcialmente cierta' => 'mixed',
            'parcialmente verdadero' => 'mixed',
            'parcialmente verdadera' => 'mixed',
            'mostly false' => 'mostly_false',
            'mostly-false' => 'mostly_false',
            'mostly_false' => 'mostly_false',
            'mostly inaccurate' => 'mostly_false',
            'largely false' => 'mostly_false',
            'misleading' => 'mostly_false',
            'engañoso' => 'mostly_false',
            'engañosa' => 'mostly_false',
            'false' => 'false',
            'falso' => 'false',
            'falsa' => 'false',
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

        uksort($map, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

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
