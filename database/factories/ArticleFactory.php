<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'text' => fake()->paragraphs(3, true),
            'url' => fake()->url(),
            'published_at' => fake()->dateTime(),
            'source_id' => SourceFactory::new(),
        ];
    }
}
