<?php

namespace Tests\Feature\Api;

use App\Contracts\Article\HostResolverInterface;
use App\Models\ApiKey;
use App\Services\Article\ArticleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Throwable;

class IngestionErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        Storage::fake('local');
        config([
            'ai.ocr.base_url' => 'http://127.0.0.1:8002',
            'ai.gemini.api_keys' => ['test-api-key'],
            'ai.gemini.model' => 'gemini-2.0-flash',
            'ai.gemini.fallback_models' => [],
        ]);
    }

    public function test_blocked_article_url_returns_a_stable_error_code(): void
    {
        Http::fake();
        $this->resolveHostTo(['169.254.169.254']);

        $this->postJson('/api/v1/articles', ['url' => 'https://news.example.test/story'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'url_blocked')
            ->assertJsonPath('message', 'No se permite acceder a esa dirección.');

        Http::assertNothingSent();
    }

    public function test_private_article_url_is_reported_as_blocked_input(): void
    {
        $this->postJson('/api/v1/articles', ['url' => 'http://127.0.0.1/'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'url_blocked')
            ->assertJsonPath('message', 'No se permite acceder a esa dirección.');
    }

    public function test_failed_article_download_returns_a_stable_error_code(): void
    {
        $url = 'https://news.example.test/story';
        Http::fake([$url => Http::response([], 503)]);
        $this->resolveHostTo(['93.184.216.34']);

        $this->postJson('/api/v1/articles', ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'fetch_failed')
            ->assertJsonPath('message', 'No se pudo descargar la noticia. Verifica el enlace e inténtalo de nuevo.');
    }

    public function test_page_without_extractable_news_text_returns_a_stable_error_code(): void
    {
        $url = 'https://news.example.test/empty';
        Http::fake([
            $url => Http::response(
                '<html><head><title>Portada</title></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $this->resolveHostTo(['93.184.216.34']);

        $this->postJson('/api/v1/articles', ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'content_not_extractable');
    }

    public function test_invalid_article_input_returns_a_stable_error_code(): void
    {
        $this->postJson('/api/v1/articles', ['content' => 'corto'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_input')
            ->assertJsonStructure(['errors' => ['content']]);
    }

    public function test_ocr_provider_failure_returns_a_stable_error_code(): void
    {
        Http::fake(['*127.0.0.1:8002/ocr' => Http::response([], 503)]);

        $this->postJson('/api/v1/articles/ocr', [
            'image' => UploadedFile::fake()->image('captura.png'),
        ])->assertStatus(503)
            ->assertJsonPath('code', 'ocr_unavailable')
            ->assertJsonPath('message', 'El servicio de reconocimiento de imágenes no está disponible. Inténtalo de nuevo.');
    }

    public function test_gemini_provider_failure_returns_a_stable_error_code(): void
    {
        Http::fake([
            '*127.0.0.1:8002/ocr' => Http::response([
                'text' => 'La noticia contiene información reconocida.',
                'lines' => [[
                    'text' => 'La noticia contiene información reconocida.',
                    'box' => [10, 10, 400, 50],
                    'score' => 0.95,
                ]],
            ]),
            '*generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 503),
        ]);

        $this->postJson('/api/v1/articles/ocr', [
            'image' => UploadedFile::fake()->image('captura.png'),
        ])->assertStatus(503)
            ->assertJsonPath('code', 'gemini_unavailable')
            ->assertJsonPath('message', 'El servicio de clasificación de texto no está disponible. Inténtalo de nuevo.');
    }

    public function test_unexpected_private_ingestion_failure_returns_generic_500_and_logs_context(): void
    {
        $this->failArticleCreationWith('sensitive database detail');
        Log::spy();

        $this->postJson('/api/v1/articles', [
            'content' => 'Este contenido válido provoca una excepción interna inesperada.',
        ])->assertInternalServerError()
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonPath('message', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
            ->assertDontSee('sensitive database detail');

        Log::shouldHaveReceived('error')->once()->with(
            'Unexpected ingestion failure.',
            Mockery::on(fn (array $context): bool => $context['path'] === 'api/v1/articles'
                && $context['user_id'] === $this->user->id
                && $context['exception'] instanceof Throwable),
        );
    }

    public function test_unexpected_public_ingestion_failure_returns_generic_500(): void
    {
        $this->failArticleCreationWith('sensitive provider detail');
        [, $plainKey] = ApiKey::issue($this->user, 'Ingestion test');

        $this->withHeader('X-API-Key', $plainKey)
            ->postJson('/api/public/v1/analyses', [
                'content' => 'Este contenido válido provoca una excepción inesperada en la API pública.',
            ])->assertInternalServerError()
            ->assertJsonPath('code', 'internal_error')
            ->assertDontSee('sensitive provider detail');
    }

    private function resolveHostTo(array $addresses): void
    {
        $this->app->instance(HostResolverInterface::class, new class($addresses) implements HostResolverInterface
        {
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $hostname): array
            {
                return $this->addresses;
            }
        });
    }

    private function failArticleCreationWith(string $message): void
    {
        $repository = Mockery::mock(ArticleRepository::class);
        $repository->shouldReceive('findDuplicateForUser')->andReturnNull();
        $repository->shouldReceive('create')->once()->andThrow(new \RuntimeException($message));
        $this->app->instance(ArticleRepository::class, $repository);
    }
}
