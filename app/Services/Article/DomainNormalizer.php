<?php

namespace App\Services\Article;

final class DomainNormalizer
{
    public function normalize(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            throw new \InvalidArgumentException('Invalid URL.');
        }

        $host = strtolower($host);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
