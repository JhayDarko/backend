<?php

namespace Tests\Unit\Services\FactCheck;

use App\Services\FactCheck\FactCheckRatingNormalizer;
use Tests\TestCase;

class FactCheckRatingNormalizerTest extends TestCase
{
    public function test_it_maps_known_ratings(): void
    {
        $this->assertSame(['raw_rating' => 'True', 'normalized_rating' => 'true'], FactCheckRatingNormalizer::normalize('True'));
        $this->assertSame(['raw_rating' => 'Mostly True', 'normalized_rating' => 'mostly_true'], FactCheckRatingNormalizer::normalize('Mostly True'));
        $this->assertSame(['raw_rating' => 'Mostly False', 'normalized_rating' => 'mostly_false'], FactCheckRatingNormalizer::normalize('Mostly False'));
        $this->assertSame(['raw_rating' => 'False', 'normalized_rating' => 'false'], FactCheckRatingNormalizer::normalize('False'));
    }

    public function test_it_marks_unknown_when_rating_cannot_be_safely_interpreted(): void
    {
        $this->assertSame(['raw_rating' => 'Ambiguous', 'normalized_rating' => 'unknown'], FactCheckRatingNormalizer::normalize('Ambiguous'));
        $this->assertSame(['raw_rating' => '', 'normalized_rating' => 'unknown'], FactCheckRatingNormalizer::normalize(''));
    }
}
