<?php

namespace App\Services\Source;

use App\Models\Analysis;
use App\Models\Claim;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/** Historical credibility of a publisher, computed from the analyses this system has produced. */
final class SourceProfileService
{
    /** Weight of the prior (a "virtual" number of analyses) so few samples don't swing the score. */
    private const PRIOR_WEIGHT = 5;

    /** @return array<string, mixed> */
    public function profile(Source $source): array
    {
        $base = Analysis::query()
            ->where('status', 'completed')
            ->whereNotNull('credibility_score')
            ->whereHas('article', fn ($q) => $q->where('source_id', $source->id));

        $count = (clone $base)->count();
        $avg = $count > 0 ? (float) (clone $base)->avg('credibility_score') : null;
        $verdicts = (clone $base)->select('final_verdict', DB::raw('count(*) as total'))
            ->groupBy('final_verdict')->pluck('total', 'final_verdict')->map(fn ($n) => (int) $n)->all();
        $claims = Claim::query()->whereIn('analysis_id', (clone $base)->select('analyses.id'));

        return $this->format($source, $count, $avg, $verdicts, (clone $claims)->count(), (clone $claims)->where('verifiable', true)->count());
    }

    /** @return list<array<string, mixed>> */
    public function ranking(int $limit = 50): array
    {
        $rows = Source::query()
            ->join('articles', 'articles.source_id', '=', 'sources.id')
            ->join('analyses', 'analyses.article_id', '=', 'articles.id')
            ->where('analyses.status', 'completed')
            ->whereNotNull('analyses.credibility_score')
            ->groupBy('sources.id', 'sources.name', 'sources.domain', 'sources.reputation_score')
            ->select('sources.id', 'sources.name', 'sources.domain', 'sources.reputation_score', DB::raw('count(analyses.id) as analyses_count'), DB::raw('avg(analyses.credibility_score) as avg_score'))
            ->orderByDesc('analyses_count')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Source $row): array => $this->format($row, (int) $row->analyses_count, (float) $row->avg_score, [], null, null))->all();
    }

    /**
     * @param  array<string, int>  $verdicts
     * @return array<string, mixed>
     */
    private function format(Source $source, int $count, ?float $avg, array $verdicts, ?int $claims, ?int $verifiable): array
    {
        $prior = $source->reputation_score !== null ? (float) $source->reputation_score * 100 : 50.0;
        $historical = $count > 0
            ? round(($count * $avg + self::PRIOR_WEIGHT * $prior) / ($count + self::PRIOR_WEIGHT), 1)
            : null;

        return [
            'id' => $source->id,
            'name' => $source->name,
            'domain' => $source->domain,
            'analyses_count' => $count,
            'average_score' => $avg !== null ? round($avg, 1) : null,
            'historical_score' => $historical,
            'sample' => match (true) {
                $count >= 20 => 'sólida',
                $count >= 5 => 'moderada',
                $count > 0 => 'baja',
                default => 'sin datos',
            },
            'verdicts' => $verdicts,
            'claims_count' => $claims,
            'verifiable_claims_count' => $verifiable,
        ];
    }
}
