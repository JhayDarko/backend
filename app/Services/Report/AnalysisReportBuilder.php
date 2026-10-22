<?php

namespace App\Services\Report;

use App\Models\Analysis;

/** Builds the downloadable report (PDF/JSON) of a completed analysis. */
final class AnalysisReportBuilder
{
    public const VERDICTS = [
        'credible' => 'Creíble',
        'probably_credible' => 'Probablemente creíble',
        'inconclusive' => 'Inconclusa',
        'probably_misleading' => 'Probablemente engañosa',
        'false' => 'Falsa',
    ];

    public const STANCES = [
        'supported' => 'Respaldada',
        'refuted' => 'Desmentida',
        'mixed' => 'Parcialmente cierta',
        'unverified' => 'No verificada',
    ];

    public const SUBSCORES = [
        'evidence_score' => 'Evidencia por afirmación',
        'corroboration_score' => 'Corroboración',
        'source_quality_score' => 'Calidad de la fuente',
        'independence_score' => 'Independencia de verificadores',
        'consistency_score' => 'Consistencia',
        'language_score' => 'Lenguaje',
    ];

    /** @return array<string, mixed> */
    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing(['article.source', 'score', 'predictions']);
        $score = $analysis->score;
        $meta = (array) ($score?->metadata ?? []);
        $article = $analysis->article;

        $claims = collect($meta['claim_evaluations'] ?? [])->map(fn (array $claim): array => [
            'text' => $claim['claim_text'] ?? '',
            'stance' => self::STANCES[$claim['stance'] ?? 'unverified'] ?? 'No verificada',
            'is_central' => (bool) ($claim['is_central'] ?? false),
            'included_in_verdict' => (bool) ($claim['included_in_verdict'] ?? true),
            'conclusion' => $claim['conclusion'] ?? '',
            'reasoning' => $claim['reasoning'] ?? '',
            'fact_checks' => $claim['fact_check_sources'] ?? [],
            'evidence' => $claim['evidence'] ?? [],
        ])->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'analysis_id' => $analysis->id,
            'algorithm_version' => $analysis->algorithm_version,
            'article' => [
                'title' => $article?->title,
                'url' => $article?->url,
                'source' => $article?->source?->name,
                'author' => $article?->author_name,
                'published_at' => $article?->published_at?->toIso8601String(),
            ],
            'verdict' => [
                'key' => $analysis->final_verdict,
                'label' => self::VERDICTS[$analysis->final_verdict] ?? 'Sin veredicto',
                'score' => $analysis->credibility_score !== null ? (float) $analysis->credibility_score : null,
                'confidence' => $analysis->confidence_score !== null ? (float) $analysis->confidence_score : null,
                'truth_assessment' => $meta['truth_assessment']['display'] ?? null,
                'summary' => $meta['analyst_summary'] ?? null,
                'rationale' => $meta['verdict_rationale'] ?? null,
            ],
            'coverage' => [
                'evidence' => (float) ($meta['signals']['evidence_coverage'] ?? 0),
                'fact_check' => (float) ($meta['signals']['fact_check_coverage'] ?? 0),
            ],
            'subscores' => collect(self::SUBSCORES)->map(fn (string $label, string $key): array => [
                'label' => $label,
                'value' => $score?->{$key} !== null ? (float) $score->{$key} : null,
            ])->values()->all(),
            'key_findings' => $meta['key_findings'] ?? [],
            'red_flags' => $meta['fallacies_and_red_flags'] ?? [],
            'claims' => $claims,
            'methodology' => [
                'weights' => $meta['weights'] ?? [],
                'notes' => [
                    'Cada afirmación verificable se contrasta con verificadores de datos y fuentes web; los resultados de búsqueda son pistas, no pruebas.',
                    'La afirmación principal pesa más en el puntaje; opiniones y predicciones no entran al veredicto.',
                    'Con cobertura de evidencia insuficiente el sistema se abstiene de emitir un veredicto.',
                    'El puntaje es una ayuda para la decisión, no una garantía de exactitud.',
                ],
            ],
        ];
    }
}
