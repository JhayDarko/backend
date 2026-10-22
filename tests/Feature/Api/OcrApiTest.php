<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        Storage::fake('local');
        config([
            'ai.ocr.base_url' => 'http://127.0.0.1:8002',
            'ai.gemini.api_keys' => ['test-api-key'],
            'ai.gemini.model' => 'gemini-2.0-flash',
            'ai.gemini.fallback_models' => ['gemini-2.0-flash-lite'],
        ]);
    }

    public function test_image_text_is_classified_by_gemini_before_article_creation(): void
    {
        $lines = [
            ['text' => '@cuenta', 'box' => [10, 8, 80, 30], 'score' => 0.99],
            ['text' => 'Compartido por Ana Pérez', 'box' => [10, 40, 230, 65], 'score' => 0.98],
            ['text' => 'La NASA confirmó que la misión DART', 'box' => [12, 100, 420, 140], 'score' => 0.97],
            ['text' => 'modificó la órbita de Dimorphos.', 'box' => [12, 150, 400, 190], 'score' => 0.96],
            ['text' => 'La misión continúa en observación.', 'box' => [20, 250, 420, 290], 'score' => 0.95],
            ['text' => 'Seguir', 'box' => [500, 10, 560, 32], 'score' => 0.99],
        ];
        $this->fakeOcrAndGemini($lines, [
            ['line_id' => 1, 'category' => 'profile'],
            ['line_id' => 2, 'category' => 'profile'],
            ['line_id' => 3, 'category' => 'news'],
            ['line_id' => 4, 'category' => 'news'],
            ['line_id' => 5, 'category' => 'image_text'],
            ['line_id' => 6, 'category' => 'other'],
        ], headlineLineId: 3);
        $image = UploadedFile::fake()->image('captura.png');
        $response = $this->postJson('/api/v1/articles/ocr', ['image' => $image]);
        $response = $this->postJson('/api/v1/articles/ocr', ['image' => $image]);

        $newsText = "La NASA confirmó que la misión DART\nmodificó la órbita de Dimorphos.\nLa misión continúa en observación.";
        $response->assertCreated()->assertJsonPath('data.title', $lines[2]['text']);
        $article = Article::firstOrFail();
        $this->assertSame($newsText, $article->content);
        $this->assertSame("@cuenta\nCompartido por Ana Pérez", $article->metadata['ocr_profile_text']);
        $this->assertSame('La misión continúa en observación.', $article->metadata['ocr_image_text']);
        $this->assertSame('Seguir', $article->metadata['ocr_other_text']);
        $this->assertSame($this->user->id, $article->created_by);
        Storage::disk('local')->assertExists($article->metadata['ocr_image_path']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ocr')
            && is_string(base64_decode($request['image_base64'], true)));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com')
            && count($request['contents'][0]['parts']) === 1
            && ! array_key_exists('inline_data', $request['contents'][0]['parts'][0])
            && str_contains($request['contents'][0]['parts'][0]['text'], $lines[2]['text'])
            && ! str_contains($request['contents'][0]['parts'][0]['text'], '"box"'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com')
            && array_key_exists('inline_data', $request['contents'][0]['parts'][0] ?? []));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'vision.googleapis.com'));
    }

    public function test_legacy_ocr_response_without_lines_is_classified_as_one_line(): void
    {
        $text = 'La NASA confirmó que la misión DART modificó la órbita de Dimorphos.';
        Http::fake([
            '*127.0.0.1:8002/ocr' => Http::response(['text' => $text, 'model' => 'PaddleOCR']),
            '*generativelanguage.googleapis.com/*' => $this->geminiResponse([
                ['line_id' => 1, 'category' => 'news'],
            ], headlineLineId: 1),
        ]);

        $response = $this->postJson('/api/v1/articles/ocr', [
            'image' => UploadedFile::fake()->image('captura.jpeg'),
        ]);

        $response->assertCreated()->assertJsonPath('data.title', $text);
        $this->assertSame($text, Article::firstOrFail()->content);
    }

    public function test_gemini_failure_does_not_create_an_article_from_unfiltered_ocr_text(): void
    {
        $lines = [
            ['text' => '@cuenta', 'box' => [10, 8, 80, 30], 'score' => 0.99],
            ['text' => 'La NASA confirmó que la misión DART modificó la órbita.', 'box' => [10, 80, 500, 120], 'score' => 0.95],
        ];
        Http::fake([
            '*127.0.0.1:8002/ocr' => Http::response([
                'text' => '@cuenta'."\n".'La NASA confirmó que la misión DART modificó la órbita.',
                'lines' => $lines,
            ]),
            '*generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Model unavailable']], 500),
        ]);

        $this->postJson('/api/v1/articles/ocr', [
            'image' => UploadedFile::fake()->image('captura.png'),
        ])->assertStatus(503)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Gemini'));

        $this->assertDatabaseCount('articles', 0);
    }

    public function test_image_without_ocr_lines_is_rejected_without_calling_gemini(): void
    {
        Http::fake(['*127.0.0.1:8002/ocr' => Http::response([
            'text' => '',
            'lines' => [],
            'width' => 800,
            'height' => 1200,
            'model' => 'PaddleOCR',
        ])]);

        $this->postJson('/api/v1/articles/ocr', ['image' => UploadedFile::fake()->image('vacia.png')])
            ->assertStatus(422);

        $this->assertDatabaseCount('articles', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_invalid_gemini_line_classification_is_rejected(): void
    {
        $this->fakeOcrAndGemini([
            ['text' => 'Una línea detectada por el OCR.', 'box' => [10, 20, 300, 60], 'score' => 0.95],
        ], [], headlineLineId: 0);

        $this->postJson('/api/v1/articles/ocr', [
            'image' => UploadedFile::fake()->image('captura.png'),
        ])->assertStatus(503);

        $this->assertDatabaseCount('articles', 0);
    }

    public function test_non_image_files_are_rejected(): void
    {
        $this->postJson('/api/v1/articles/ocr', ['image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_local_ocr_service_outage_returns_a_friendly_error(): void
    {
        Http::fake(['*127.0.0.1:8002/ocr' => Http::response([], 503)]);

        $this->postJson('/api/v1/articles/ocr', ['image' => UploadedFile::fake()->image('a.png')])
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'PaddleOCR'));
    }

    /**
     * @param  list<array{text: string, box: array<int>, score: float}>  $lines
     */
    private function fakeOcrAndGemini(array $lines, array $classifications, int $headlineLineId): void
    {
        Http::fake([
            '*127.0.0.1:8002/ocr' => Http::response([
                'text' => implode("\n", array_column($lines, 'text')),
                'lines' => $lines,
                'width' => 800,
                'height' => 1200,
                'model' => 'PaddleOCR',
            ]),
            '*generativelanguage.googleapis.com/*' => $this->geminiResponse($classifications, $headlineLineId),
        ]);
    }

    /** @param  list<array{line_id: int, category: string}>  $classifications */
    private function geminiResponse(array $classifications, int $headlineLineId): mixed
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'headline_line_id' => $headlineLineId,
                            'classifications' => $classifications,
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ],
            ]],
        ]);
    }
}
