<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
final class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    public function definition(): array
    {
        return [
            'article_id' => ArticleFactory::new(),
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
            'credibility_score' => null,
            'confidence_score' => null,
            'final_verdict' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
