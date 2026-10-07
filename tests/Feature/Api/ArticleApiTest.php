<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_articles_paginated(): void
    {
        $source = Source::create([
            'name' => 'El País',
            'domain' => 'elpais.com',
            'url' => 'https://elpais.com',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Article::create([
                'source_id' => $source->id,
                'url' => "https://elpais.com/noticia-{$i}",
                'title' => "Noticia número {$i}",
                'content' => "Contenido del artículo {$i}",
                'author_name' => "Periodista {$i}",
            ]);
        }

        $response = $this->getJson('/api/v1/articles?per_page=3');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_can_list_articles_with_cursor_pagination(): void
    {
        $source = Source::create([
            'name' => 'Cursor Source',
            'domain' => 'cursor.example',
            'url' => 'https://cursor.example',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Article::create([
                'source_id' => $source->id,
                'url' => "https://cursor.example/article-{$i}",
                'title' => "Cursor article {$i}",
                'content' => "Content {$i}",
            ]);
        }

        $firstPage = $this->getJson('/api/v1/articles?pagination=cursor&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $cursor = $firstPage->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $secondPage = $this->getJson('/api/v1/articles?pagination=cursor&per_page=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstIds = collect($firstPage->json('data'))->pluck('id');
        $secondIds = collect($secondPage->json('data'))->pluck('id');

        $this->assertSame([], $firstIds->intersect($secondIds)->all());
    }

    public function test_can_search_articles_by_keyword(): void
    {
        $source = Source::create([
            'name' => 'BBC',
            'domain' => 'bbc.com',
            'url' => 'https://bbc.com',
        ]);

        Article::create([
            'source_id' => $source->id,
            'url' => 'https://bbc.com/astronomia',
            'title' => 'Descubren nuevo exoplaneta habitable',
            'content' => 'Contenido sobre el telescopio espacial...',
            'author_name' => 'Ciencia BBC',
        ]);

        Article::create([
            'source_id' => $source->id,
            'url' => 'https://bbc.com/economia',
            'title' => 'Bolsas internacionales en alza',
            'content' => 'Mercados reaccionan a tasas de interés...',
            'author_name' => 'Economía BBC',
        ]);

        $response = $this->getJson('/api/v1/articles?search=exoplaneta');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Descubren nuevo exoplaneta habitable');
    }

    public function test_can_filter_articles_by_source_domain(): void
    {
        $sourceA = Source::create(['name' => 'Reuters', 'domain' => 'reuters.com', 'url' => 'https://reuters.com']);
        $sourceB = Source::create(['name' => 'CNN', 'domain' => 'cnn.com', 'url' => 'https://cnn.com']);

        Article::create(['source_id' => $sourceA->id, 'url' => 'https://reuters.com/1', 'title' => 'Reuters News', 'content' => 'Content']);
        Article::create(['source_id' => $sourceB->id, 'url' => 'https://cnn.com/1', 'title' => 'CNN News', 'content' => 'Content']);

        $response = $this->getJson('/api/v1/articles?domain=reuters');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Reuters News');
    }

    public function test_can_show_single_article(): void
    {
        $source = Source::create(['name' => 'AP News', 'domain' => 'apnews.com', 'url' => 'https://apnews.com']);
        $article = Article::create([
            'source_id' => $source->id,
            'url' => 'https://apnews.com/tech-news',
            'title' => 'Tech Innovations 2026',
            'content' => 'Detailed technology reporting...',
        ]);

        $response = $this->getJson("/api/v1/articles/{$article->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $article->id)
            ->assertJsonPath('data.title', 'Tech Innovations 2026')
            ->assertJsonPath('data.source.name', 'AP News');
    }

    public function test_can_store_article_via_raw_text_submission(): void
    {
        $payload = [
            'title' => 'Rumor de redes sociales sobre nueva ley',
            'content' => 'Este es un mensaje que circula intensamente por grupos de mensajería afirmando cambios en la legislación civil.',
            'author_name' => 'Canal de Difusión',
        ];

        $response = $this->postJson('/api/v1/articles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Rumor de redes sociales sobre nueva ley')
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.author_name', 'Canal de Difusión');

        $this->assertDatabaseHas('articles', [
            'title' => 'Rumor de redes sociales sobre nueva ley',
            'author_name' => 'Canal de Difusión',
        ]);
    }

    public function test_can_store_article_from_plain_text_body(): void
    {
        $plainText = "Titular pegado por el usuario\n\nEste es el contenido completo de una noticia copiada y pegada directamente en el sistema.";

        $response = $this->call(
            'POST',
            '/api/v1/articles',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $plainText,
        );

        $response->assertCreated()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.content', $plainText);
    }

    public function test_raw_text_submission_is_deduplicated_by_content_hash(): void
    {
        $payload = [
            'title' => 'Cadena duplicada',
            'content' => 'Texto exactamente idéntico que se envía dos veces para verificar deduplicación.',
        ];

        $res1 = $this->postJson('/api/v1/articles', $payload);
        $res2 = $this->postJson('/api/v1/articles', $payload);

        $res1->assertStatus(201);
        $res2->assertStatus(201);

        $this->assertSame($res1->json('data.id'), $res2->json('data.id'));
        $this->assertSame(1, Article::where('content', $payload['content'])->count());
    }

    public function test_store_article_fails_if_neither_url_nor_content_provided(): void
    {
        $response = $this->postJson('/api/v1/articles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url', 'content']);
    }
}

