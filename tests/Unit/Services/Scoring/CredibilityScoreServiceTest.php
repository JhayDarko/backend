<?php

namespace Tests\Unit\Services\Scoring;

use App\DTO\Analyst\AnalystClaimEvaluation;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Models\ModelPrediction;
use App\Models\Source;
use App\Services\Scoring\CredibilityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredibilityScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private CredibilityScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CredibilityScoreService();
    }

    public function test_calculates_high_credibility_score_with_strong_positive_signals(): void
    {
        $source = Source::query()->create([
            'name' => 'Verified Source',
            'domain' => 'chequeado.com',
            'url' => 'https://chequeado.com',
            'type' => 'fact_checker',
            'reputation_score' => 0.95,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'title' => 'Noticia verificada y contrastada',
            'content' => 'Contenido riguroso y verificado con fuentes oficiales.',
            'url' => 'https://chequeado.com/nota-verificada',
            'author_name' => 'Periodista Certificado',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Afirmación comprobada verdadera',
            'importance' => 0.9,
            'verifiable' => true,
        ]);

        $verification = ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'normalized_rating' => 'true',
            'fact_check_url' => 'https://factual.afp.com/verificacion-1',
            'fact_check_title' => 'Verificación positiva',
        ]);

        $evidence1 = Evidence::query()->create([
            'url' => 'https://factual.afp.com/verificacion-1',
            'title' => 'Verificación AFP',
            'snippet' => 'Evidencia oficial que confirma el dato.',
            'metadata' => ['domain' => 'factual.afp.com'],
        ]);

        $evidence2 = Evidence::query()->create([
            'url' => 'https://reuters.com/fact-check-2',
            'title' => 'Reuters Fact Check',
            'snippet' => 'Confirmación independiente.',
            'metadata' => ['domain' => 'reuters.com'],
        ]);

        $verification->evidences()->sync([$evidence1->id, $evidence2->id]);

        ModelPrediction::query()->create([
            'analysis_id' => $analysis->id,
            'prediction_label' => 'REAL',
            'confidence_score' => 0.94,
            'probability' => 0.94,
            'model_provider' => 'bert',
            'model_name' => 'spanish-fake-news-classifier',
        ]);

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'chequeado.com',
            trustTier: 'fact_checker',
            reputationScore: 0.95,
            isKnownFactChecker: true,
        );

        $analystSynthesis = new AnalystSynthesisResult(
            summary: 'La noticia está completamente respaldada por evidencias sólidas.',
            claimEvaluations: [
                new AnalystClaimEvaluation($claim->id, $claim->text, 'supported', 'Respaldo directo', 0.95),
            ],
            consistencyScore: 0.95,
            suggestedVerdict: 'credible',
        );

        $scoreResult = $this->service->calculate($analysis, $sourceAnalysis, $analystSynthesis);

        $this->assertGreaterThanOrEqual(85.0, $scoreResult->finalScore);
        $this->assertSame('credible', $scoreResult->finalVerdict);
        $this->assertGreaterThanOrEqual(0.80, $scoreResult->confidenceScore);
        $this->assertSame(100.0, $scoreResult->evidenceScore);
        $this->assertSame(95.0, $scoreResult->sourceQualityScore);
        $this->assertSame(95.0, $scoreResult->consistencyScore);
    }

    public function test_calculates_low_credibility_score_for_false_content(): void
    {
        $source = Source::query()->create([
            'name' => 'Sitio Desconocido',
            'domain' => 'fake-viral-news.xyz',
            'url' => 'https://fake-viral-news.xyz',
            'type' => 'unknown',
            'reputation_score' => 0.10,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'title' => 'Bulo viral sensacionalista',
            'content' => 'Texto con mentiras flagrantes inventadas.',
            'url' => 'https://fake-viral-news.xyz/noticia-falsa',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Afirmación refutada por fact-checkers',
            'importance' => 1.0,
            'verifiable' => true,
        ]);

        ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'normalized_rating' => 'false',
            'fact_check_url' => 'https://maldita.es/bulo-123',
            'fact_check_title' => 'Bulo desmentido',
        ]);

        ModelPrediction::query()->create([
            'analysis_id' => $analysis->id,
            'prediction_label' => 'FAKE',
            'confidence_score' => 0.98,
            'probability' => 0.98,
            'model_provider' => 'bert',
            'model_name' => 'spanish-fake-news-classifier',
        ]);

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'fake-viral-news.xyz',
            trustTier: 'low_trust',
            reputationScore: 0.10,
            isKnownFactChecker: false,
        );

        $analystSynthesis = new AnalystSynthesisResult(
            summary: 'La afirmación principal ha sido desmentida formalmente.',
            claimEvaluations: [
                new AnalystClaimEvaluation($claim->id, $claim->text, 'refuted', 'Desmentido por fact-checker', 0.98),
            ],
            consistencyScore: 0.10,
            suggestedVerdict: 'false',
        );

        $scoreResult = $this->service->calculate($analysis, $sourceAnalysis, $analystSynthesis);

        $this->assertLessThan(25.0, $scoreResult->finalScore);
        $this->assertSame('false', $scoreResult->finalVerdict);
        $this->assertSame(0.0, $scoreResult->evidenceScore);
        $this->assertSame(10.0, $scoreResult->sourceQualityScore);
    }

    public function test_handles_unverified_content_with_inconclusive_verdict(): void
    {
        $article = Article::query()->create([
            'title' => 'Rumor sin verificar',
            'content' => 'Se comenta en redes que algo podría ocurrir.',
            'url' => 'https://sitio-desconocido.com/rumor',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'sitio-desconocido.com',
            trustTier: 'unverified_domain',
            reputationScore: 0.50,
            isKnownFactChecker: false,
        );

        $scoreResult = $this->service->calculate($analysis, $sourceAnalysis);

        $this->assertGreaterThanOrEqual(40.0, $scoreResult->finalScore);
        $this->assertLessThan(65.0, $scoreResult->finalScore);
        $this->assertSame('inconclusive', $scoreResult->finalVerdict);
    }
}
