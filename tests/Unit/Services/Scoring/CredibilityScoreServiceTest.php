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
            'prediction_label' => 'REAL',
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

    public function test_search_hits_without_fact_check_match_do_not_create_false_confidence(): void
    {
        $article = Article::query()->create([
            'title' => 'Anuncio extraordinario no confirmado',
            'content' => 'El Gobierno anunció una medida extraordinaria que no aparece en fuentes oficiales.',
            'url' => null,
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'El Gobierno anunció una medida extraordinaria',
            'importance' => 1.0,
            'verifiable' => true,
        ]);

        $verification = ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'search_evidence',
            'provider' => 'tavily',
            'status' => 'matched',
            'normalized_rating' => 'unknown',
        ]);

        $evidences = collect(range(1, 10))->map(fn (int $index) => Evidence::query()->create([
            'url' => "https://source-{$index}.example.test/article",
            'title' => "Search result {$index}",
            'snippet' => 'Search result without a fact-check rating.',
            'metadata' => ['domain' => "source-{$index}.example.test"],
        ]));
        $verification->evidences()->sync($evidences->pluck('id'));

        ModelPrediction::query()->create([
            'analysis_id' => $analysis->id,
            'prediction_label' => 'REAL',
            'confidence_score' => 0.85,
            'probability' => 0.85,
            'model_provider' => 'bert',
            'model_name' => 'spanish-fake-news-classifier',
        ]);

        $analystSynthesis = new AnalystSynthesisResult(
            summary: 'No existe confirmación oficial suficiente.',
            claimEvaluations: [
                new AnalystClaimEvaluation($claim->id, $claim->text, 'unverified', 'No hay confirmación oficial.', 0.9),
            ],
            consistencyScore: 0.65,
            suggestedVerdict: 'inconclusive',
        );

        $scoreResult = $this->service->calculate(
            $analysis,
            new SourceAnalysisResult('unknown', 'unknown', 0.5, false),
            $analystSynthesis,
        );

        $this->assertSame(50.0, $scoreResult->evidenceScore);
        $this->assertSame(0.0, $scoreResult->corroborationScore);
        $this->assertSame(50.0, $scoreResult->independenceScore);
        $this->assertLessThan(1.0, $scoreResult->confidenceScore);
        $this->assertNotSame('probably_credible', $scoreResult->finalVerdict);
        $this->assertSame('unverified', $scoreResult->breakdown['claim_evaluations'][0]['stance']);
        $this->assertSame(0.0, $scoreResult->breakdown['signals']['evidence_coverage']);
    }

    public function test_bert_and_ungrounded_analyst_claim_do_not_override_missing_evidence(): void
    {
        $article = Article::query()->create([
            'title' => 'Titular viral sin fuentes',
            'content' => 'Una afirmación extraordinaria sin enlaces ni revisiones disponibles.',
            'url' => null,
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);
        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'El Gobierno anunció una medida nacional',
            'importance' => 1.0,
            'verifiable' => true,
            'type' => 'factual',
        ]);
        ModelPrediction::query()->create([
            'analysis_id' => $analysis->id,
            'prediction_label' => 'REAL',
            'confidence_score' => 0.99,
            'probability' => 0.99,
            'model_provider' => 'bert',
            'model_name' => 'spanish-fake-news-classifier',
        ]);

        $result = $this->service->calculate(
            $analysis,
            new SourceAnalysisResult('unknown', 'unknown', 0.5, false),
            new AnalystSynthesisResult(
                summary: 'El claim parece verdadero.',
                claimEvaluations: [new AnalystClaimEvaluation($claim->id, $claim->text, 'supported', 'Sin fuente vinculada.', 0.99)],
                consistencyScore: 0.99,
                suggestedVerdict: 'credible',
            ),
        );

        $this->assertSame('inconclusive', $result->finalVerdict);
        $this->assertSame('insufficient_evidence', $result->breakdown['truth_assessment']['label']);
        $this->assertSame('unverified', $result->breakdown['claim_evaluations'][0]['stance']);
        $this->assertSame(0.0, $result->breakdown['signals']['evidence_coverage']);
    }

    public function test_article_report_keeps_true_and_refuted_claims_separate(): void
    {
        $article = Article::query()->create([
            'title' => 'Dos afirmaciones distintas',
            'content' => 'Una afirmación fue confirmada y otra fue desmentida.',
            'url' => null,
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        foreach ([['Afirmación respaldada', 'true'], ['Afirmación refutada', 'false']] as [$text, $rating]) {
            $claim = Claim::query()->create([
                'analysis_id' => $analysis->id,
                'text' => $text,
                'importance' => 0.8,
                'verifiable' => true,
                'type' => 'factual',
            ]);
            ClaimVerification::query()->create([
                'claim_id' => $claim->id,
                'relation' => 'external_fact_check',
                'provider' => 'google_fact_check',
                'status' => 'matched',
                'normalized_rating' => $rating,
                'publisher' => $rating === 'true' ? 'Fuente A' : 'Fuente B',
                'publisher_site' => $rating === 'true' ? 'fuente-a.example' : 'fuente-b.example',
                'fact_check_url' => "https://{$rating}.example/review",
            ]);
        }

        $result = $this->service->calculate(
            $analysis,
            new SourceAnalysisResult('unknown', 'unknown', 0.5, false),
        );
        $claimResults = collect($result->breakdown['claim_evaluations'])->keyBy('claim_text');

        $this->assertSame('supported', $claimResults['Afirmación respaldada']['stance']);
        $this->assertSame('refuted', $claimResults['Afirmación refutada']['stance']);
        $this->assertSame('inconclusive', $result->finalVerdict);
        $this->assertSame('partially_true', $result->breakdown['truth_assessment']['label']);
        $this->assertStringContainsString('no se pudieron confirmar', strtolower($result->breakdown['analyst_summary']));
    }

    public function test_legacy_fact_check_raw_rating_is_used_when_normalized_rating_is_unknown(): void
    {
        $article = Article::query()->create([
            'title' => 'Afirmación revisada',
            'content' => 'Contenido de prueba suficiente para evaluar un fact-check legado.',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);
        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'La afirmación fue calificada como engañosa',
            'importance' => 1.0,
            'verifiable' => true,
            'type' => 'factual',
        ]);
        ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'raw_rating' => 'Engañoso',
            'normalized_rating' => 'unknown',
            'publisher' => 'Verificador',
            'publisher_site' => 'verificador.example',
            'fact_check_url' => 'https://verificador.example/nota',
        ]);

        $result = $this->service->calculate(
            $analysis,
            new SourceAnalysisResult('unknown', 'unknown', 0.5, false),
        );

        $claimResult = $result->breakdown['claim_evaluations'][0];
        $this->assertSame('refuted', $claimResult['stance']);
        $this->assertTrue($claimResult['fact_checked']);
        $this->assertSame('mostly_false', $claimResult['fact_check_sources'][0]['rating']);
    }

    public function test_main_claim_weighs_more_and_minor_unverifiable_claims_do_not_drag_the_verdict(): void
    {
        $article = Article::query()->create(['title' => 'Misión confirmada', 'content' => 'Texto.', 'url' => null]);
        $analysis = Analysis::query()->create(['article_id' => $article->id, 'status' => 'processing']);

        $main = Claim::query()->create(['analysis_id' => $analysis->id, 'text' => 'Tesis principal', 'type' => 'factual', 'importance' => 0.9, 'verifiable' => true]);
        $minor = Claim::query()->create(['analysis_id' => $analysis->id, 'text' => 'Detalle menor sin fuentes', 'type' => 'factual', 'importance' => 0.5, 'verifiable' => true]);
        Claim::query()->create(['analysis_id' => $analysis->id, 'text' => 'Opinión del autor', 'type' => 'opinion', 'importance' => 0.4, 'verifiable' => false]);
        Claim::query()->create(['analysis_id' => $analysis->id, 'text' => 'Ocurrirá en 2030', 'type' => 'prediction', 'importance' => 0.4, 'verifiable' => true]);

        ClaimVerification::query()->create([
            'claim_id' => $main->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'normalized_rating' => 'true',
            'fact_check_url' => 'https://factual.afp.com/v1',
        ]);

        $result = $this->service->calculate($analysis, new SourceAnalysisResult(domain: 'x.test', trustTier: 'unknown', reputationScore: 0.5, isKnownFactChecker: false));
        $byText = collect($result->breakdown['claim_evaluations'])->keyBy('claim_text');

        $this->assertSame($main->id, $result->breakdown['signals']['central_claim_id']);
        $this->assertTrue($byText['Tesis principal']['is_central']);
        $this->assertSame(1.8, $byText['Tesis principal']['weight']);
        $this->assertSame(0.2, $byText['Detalle menor sin fuentes']['weight']);
        $this->assertSame('no_evidence_found', $byText['Detalle menor sin fuentes']['unverifiable_reason']);
        $this->assertSame('opinion', $byText['Opinión del autor']['unverifiable_reason']);
        $this->assertSame('prediction', $byText['Ocurrirá en 2030']['unverifiable_reason']);
        $this->assertFalse($byText['Ocurrirá en 2030']['included_in_verdict']);
        $this->assertSame(0.9, $result->breakdown['signals']['evidence_coverage']);
        $this->assertSame(2, $result->breakdown['signals']['unverifiable_claims_count']);
        $this->assertNotNull($minor->id);
    }}
