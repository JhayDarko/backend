<?php

namespace Tests\Unit\Services\AI;

use App\Exceptions\AiProviderException;
use App\Services\AI\Providers\BertFakeNewsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BertFakeNewsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.classifier.provider' => 'bert',
            'ai.bert.base_url' => 'http://127.0.0.1:8001',
            'ai.timeout' => 30,
        ]);
    }

    public function test_it_returns_normalized_real_label_and_confidence(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/classify' => Http::response([
                'label' => 'REAL',
                'confidence' => 0.87,
                'probability' => 0.87,
                'model' => 'Juanillaberia/spanish-fake-news-classifier',
            ], 200),
        ]);

        $provider = new BertFakeNewsProvider;
        $result = $provider->classify('El gobierno anunció un plan', 'Contenido real del artículo de prueba.', 'Descripción breve');

        $this->assertSame('REAL', $result->label);
        $this->assertSame(0.87, $result->confidence);
        $this->assertSame('Juanillaberia/spanish-fake-news-classifier', $result->model);
    }

    public function test_it_rejects_invalid_labels(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/classify' => Http::response([
                'label' => 'UNKNOWN',
                'confidence' => 0.5,
                'probability' => 0.5,
                'model' => 'Juanillaberia/spanish-fake-news-classifier',
            ], 200),
        ]);

        $provider = new BertFakeNewsProvider;

        $this->expectException(AiProviderException::class);
        $provider->classify('Título', 'Contenido', 'Descripción');
    }
}
