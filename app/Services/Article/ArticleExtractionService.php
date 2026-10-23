<?php

namespace App\Services\Article;

use App\Exceptions\ArticleInputException;
use App\Services\Article\DTO\ExtractedArticleData;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;

final class ArticleExtractionService
{
    public function __construct(
        private readonly SafeUrlFetcher $urlFetcher,
    ) {}

    public function extract(string $url): ExtractedArticleData
    {
        try {
            $html = $this->urlFetcher->fetch($url);
        } catch (SafeUrlFetchException $exception) {
            throw match ($exception->reason) {
                SafeUrlFetchException::BLOCKED => ArticleInputException::blockedUrl($exception),
                SafeUrlFetchException::FETCH_FAILED => ArticleInputException::fetchFailed($exception),
                default => ArticleInputException::contentNotExtractable($exception),
            };
        }

        if (trim($html) === '') {
            throw ArticleInputException::contentNotExtractable();
        }

        $crawler = new Crawler($html);

        /*
         * JSON-LD suele contener la información más completa
         * de una noticia. Muchos periódicos modernos lo utilizan.
         */
        $jsonLd = $this->extractJsonLd($crawler);

        $title = $this->firstNonEmpty([
            $jsonLd['headline'] ?? null,
            $this->extractMeta($crawler, 'property', 'og:title'),
            $this->extractMeta($crawler, 'name', 'twitter:title'),
            $this->extractHtmlTitle($crawler),
        ]);

        if (! $title) {
            throw ArticleInputException::contentNotExtractable();
        }

        $description = $this->firstNonEmpty([
            $jsonLd['description'] ?? null,
            $this->extractMeta($crawler, 'property', 'og:description'),
            $this->extractMeta($crawler, 'name', 'description'),
            $this->extractMeta($crawler, 'name', 'twitter:description'),
        ]);

        $content = $this->firstNonEmpty([
            $this->cleanText($jsonLd['articleBody'] ?? null),
            $this->extractArticleContent($crawler),
            $this->extractParagraphContent($crawler),
        ]);

        if (! $content) {
            throw ArticleInputException::contentNotExtractable();
        }

        $authorName = $this->extractAuthor(
            $crawler,
            $jsonLd['author'] ?? null
        );

        $canonicalUrl = $this->firstNonEmpty([
            $jsonLd['url'] ?? null,
            $this->extractCanonicalUrl($crawler),
            $url,
        ]);

        $publishedAt = $this->extractPublishedAt(
            $crawler,
            $jsonLd
        );

        $rawLanguage = $this->firstNonEmpty([
            $jsonLd['inLanguage'] ?? null,
            $this->extractLanguage($crawler),
        ]);

        $language = $this->normalizeLanguage($rawLanguage);

        $metadata = $this->extractMetadata(
            $crawler,
            $jsonLd
        );

        return new ExtractedArticleData(
            url: $url,
            canonicalUrl: $canonicalUrl,
            title: $title,
            description: $description,
            content: $content,
            authorName: $authorName,
            publishedAt: $publishedAt,
            language: $language,
            metadata: $metadata,
        );
    }

    /**
     * Extrae datos de JSON-LD.
     *
     * Busca objetos como:
     * - NewsArticle
     * - Article
     * - ReportageNewsArticle
     * - WebPage con información de artículo
     */
    private function extractJsonLd(Crawler $crawler): array
    {
        $bestMatch = [];

        if ($crawler->filter('script[type="application/ld+json"]')->count() === 0) {
            return [];
        }

        $crawler
            ->filter('script[type="application/ld+json"]')
            ->each(function (Crawler $node) use (&$bestMatch) {
                $json = trim($node->text(null, false));

                if ($json === '') {
                    return;
                }

                /*
                 * Algunos sitios colocan caracteres especiales
                 * o estructuras JSON ligeramente problemáticas.
                 */
                $data = json_decode($json, true);

                if (! is_array($data)) {
                    return;
                }

                $objects = $this->flattenJsonLd($data);

                foreach ($objects as $object) {
                    if (! is_array($object)) {
                        continue;
                    }

                    $type = $object['@type'] ?? null;

                    if (is_array($type)) {
                        $type = implode(',', $type);
                    }

                    $type = strtolower((string) $type);

                    $isArticle = str_contains($type, 'article')
                        || str_contains($type, 'newsarticle')
                        || str_contains($type, 'reportagenewsarticle');

                    if (! $isArticle) {
                        continue;
                    }

                    /*
                     * Preferimos objetos que realmente tengan
                     * información útil para nuestra aplicación.
                     */
                    $score = 0;

                    if (! empty($object['headline'])) {
                        $score += 3;
                    }

                    if (! empty($object['articleBody'])) {
                        $score += 4;
                    }

                    if (! empty($object['datePublished'])) {
                        $score += 2;
                    }

                    if (! empty($object['author'])) {
                        $score += 1;
                    }

                    if (! empty($object['description'])) {
                        $score += 1;
                    }

                    $currentScore = $bestMatch['_score'] ?? -1;

                    if ($score > $currentScore) {
                        $object['_score'] = $score;
                        $bestMatch = $object;
                    }
                }
            });

        unset($bestMatch['_score']);

        return $bestMatch;
    }

    /**
     * Convierte estructuras JSON-LD como:
     *
     * {
     *   "@graph": [...]
     * }
     *
     * en una lista sencilla de objetos.
     */
    private function flattenJsonLd(array $data): array
    {
        $objects = [];

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                if (is_array($item)) {
                    $objects[] = $item;
                }
            }
        }

        /*
         * Si el JSON directamente es un objeto.
         */
        if (isset($data['@type'])) {
            $objects[] = $data;
        }

        /*
         * Si es un array de objetos JSON-LD.
         */
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $objects[] = $item;
                }
            }
        }

        return $objects;
    }

    /**
     * Extrae contenido de <article> o <main>.
     */
    private function extractArticleContent(Crawler $crawler): ?string
    {
        $selectors = [
            'article',
            'main',
            '[itemprop="articleBody"]',
            '.article-body',
            '.article-content',
            '.article__body',
            '.story-body',
            '.story-content',
            '.entry-content',
            '.post-content',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $text = $crawler
                ->filter($selector)
                ->first()
                ->text(null, false);

            $text = $this->cleanText($text);

            /*
             * Evitamos devolver contenedores demasiado pequeños
             * que probablemente sean menús, encabezados, etc.
             */
            if ($text !== null && mb_strlen($text) >= 200) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Fallback basado en párrafos.
     *
     * Es útil cuando el sitio no utiliza <article> correctamente.
     */
    private function extractParagraphContent(Crawler $crawler): ?string
    {
        if ($crawler->filter('p')->count() === 0) {
            return null;
        }

        $paragraphs = [];

        $crawler->filter('p')->each(
            function (Crawler $node) use (&$paragraphs) {
                $text = $this->cleanText(
                    $node->text(null, false)
                );

                if ($text === null) {
                    return;
                }

                /*
                 * Ignoramos párrafos demasiado pequeños porque
                 * normalmente son botones, menús o elementos similares.
                 */
                if (mb_strlen($text) < 40) {
                    return;
                }

                $paragraphs[] = $text;
            }
        );

        if (empty($paragraphs)) {
            return null;
        }

        /*
         * Evitamos guardar páginas enormes por accidente.
         */
        $content = implode("\n\n", $paragraphs);

        return $this->cleanText($content);
    }

    /**
     * Extrae el título HTML.
     */
    private function extractHtmlTitle(Crawler $crawler): ?string
    {
        if ($crawler->filter('title')->count() === 0) {
            return null;
        }

        return $this->cleanText(
            $crawler->filter('title')->first()->text(null, false)
        );
    }

    /**
     * Extrae una meta etiqueta de forma segura.
     *
     * IMPORTANTE:
     * comprobamos count() antes de llamar a attr().
     * Esto evita el error:
     *
     * "The current node list is empty."
     */
    private function extractMeta(
        Crawler $crawler,
        string $attribute,
        string $value
    ): ?string {
        $selector = sprintf(
            'meta[%s="%s"]',
            $attribute,
            $value
        );

        if ($crawler->filter($selector)->count() === 0) {
            return null;
        }

        return $this->cleanText(
            $crawler->filter($selector)->first()->attr('content')
        );
    }

    /**
     * Extrae el autor.
     */
    private function extractAuthor(
        Crawler $crawler,
        mixed $jsonLdAuthor
    ): ?string {
        /*
         * Primero JSON-LD.
         */
        if (is_string($jsonLdAuthor)) {
            return $this->cleanText($jsonLdAuthor);
        }

        if (is_array($jsonLdAuthor)) {
            /*
             * author puede ser:
             *
             * {
             *   "@type": "Person",
             *   "name": "Juan Pérez"
             * }
             */
            if (! empty($jsonLdAuthor['name'])) {
                return $this->cleanText(
                    (string) $jsonLdAuthor['name']
                );
            }

            /*
             * También puede ser una lista de autores.
             */
            if (array_is_list($jsonLdAuthor)) {
                $authors = [];

                foreach ($jsonLdAuthor as $author) {
                    if (is_string($author)) {
                        $authors[] = $author;
                    } elseif (
                        is_array($author)
                        && ! empty($author['name'])
                    ) {
                        $authors[] = $author['name'];
                    }
                }

                if (! empty($authors)) {
                    return $this->cleanText(
                        implode(', ', $authors)
                    );
                }
            }
        }

        /*
         * Fallback HTML.
         */
        $selectors = [
            'meta[name="author"]',
            '[rel="author"]',
            '[itemprop="author"]',
            '.author',
            '.author-name',
            '.byline',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $node = $crawler->filter($selector)->first();

            $value = $node->attr('content');

            if (! $value) {
                $value = $node->text(null, false);
            }

            $value = $this->cleanText($value);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extrae URL canónica.
     */
    private function extractCanonicalUrl(
        Crawler $crawler
    ): ?string {
        if (
            $crawler
                ->filter('link[rel="canonical"]')
                ->count() === 0
        ) {
            return null;
        }

        return $this->cleanText(
            $crawler
                ->filter('link[rel="canonical"]')
                ->first()
                ->attr('href')
        );
    }

    /**
     * Extrae fecha de publicación.
     */
    private function extractPublishedAt(
        Crawler $crawler,
        array $jsonLd
    ): ?DateTimeImmutable {
        $date = $this->firstNonEmpty([
            $jsonLd['datePublished'] ?? null,
            $jsonLd['dateCreated'] ?? null,
            $this->extractMeta(
                $crawler,
                'property',
                'article:published_time'
            ),
            $this->extractMeta(
                $crawler,
                'property',
                'og:published_time'
            ),
            $this->extractMeta(
                $crawler,
                'name',
                'date'
            ),
            $this->extractMeta(
                $crawler,
                'name',
                'publish_date'
            ),
        ]);

        if (! $date) {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extrae idioma.
     */
    private function extractLanguage(
        Crawler $crawler
    ): ?string {
        if ($crawler->filter('html')->count() === 0) {
            return null;
        }

        return $this->cleanText(
            $crawler
                ->filter('html')
                ->first()
                ->attr('lang')
        );
    }

    /**
     * Extrae metadatos útiles.
     */
    private function extractMetadata(
        Crawler $crawler,
        array $jsonLd
    ): array {
        $metadata = [];

        /*
         * Imagen.
         */
        $image = null;

        if (! empty($jsonLd['image'])) {
            $image = $this->extractImageValue(
                $jsonLd['image']
            );
        }

        if (! $image) {
            $image = $this->extractMeta(
                $crawler,
                'property',
                'og:image'
            );
        }

        if (! $image) {
            $image = $this->extractMeta(
                $crawler,
                'name',
                'twitter:image'
            );
        }

        if ($image) {
            $metadata['image'] = $image;
        }

        /*
         * Tipo de contenido.
         */
        $ogType = $this->extractMeta(
            $crawler,
            'property',
            'og:type'
        );

        if ($ogType) {
            $metadata['og_type'] = $ogType;
        }

        /*
         * Se conserva el tipo JSON-LD.
         */
        if (! empty($jsonLd['@type'])) {
            $metadata['schema_type'] = $jsonLd['@type'];
        }

        return array_filter(
            $metadata,
            fn ($value) => $value !== null && $value !== ''
        );
    }

    /**
     * JSON-LD puede representar image como:
     *
     * "https://..."
     *
     * o:
     *
     * {
     *   "url": "https://..."
     * }
     *
     * o:
     *
     * [
     *   {
     *      "url": "https://..."
     *   }
     * ]
     */
    private function extractImageValue(mixed $image): ?string
    {
        if (is_string($image)) {
            return $this->cleanText($image);
        }

        if (is_array($image)) {
            if (! empty($image['url'])) {
                return $this->cleanText(
                    (string) $image['url']
                );
            }

            if (array_is_list($image)) {
                foreach ($image as $item) {
                    $result = $this->extractImageValue($item);

                    if ($result) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Devuelve el primer valor válido.
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Limpia espacios, saltos de línea y entidades HTML.
     */
    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            trim($text)
        );

        return $text !== '' ? $text : null;
    }

    /**
     * Normaliza el valor de lenguaje asegurando que sea string o null.
     */
    private function normalizeLanguage(mixed $language): ?string
    {
        if (is_string($language)) {
            return $this->cleanText($language);
        }

        if (is_array($language)) {
            foreach (array_reverse($language) as $item) {
                if (is_string($item) && strlen(trim($item)) <= 5) {
                    return $this->cleanText($item);
                }
            }
            foreach ($language as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return $this->cleanText($item);
                }
            }
        }

        return null;
    }
}
