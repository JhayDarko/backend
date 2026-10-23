<?php

namespace App\Services\Evaluation;

final class EvaluationSnapshotSanitizer
{
    private const SECRET_KEYS = [
        'authorization',
        'key',
        'api_key',
        'apikey',
        'access_token',
        'token',
        'password',
        'secret',
        'credential',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = $this->sanitizeValue($data);
        if (! is_array($clean)) {
            return [];
        }

        if (isset($clean['response_headers']) && is_array($clean['response_headers'])) {
            $clean['response_headers'] = $this->rateLimitHeaders($clean['response_headers']);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string|null>
     */
    public function rateLimitHeaders(array $headers): array
    {
        $allowed = [];
        foreach ($headers as $name => $value) {
            $header = strtolower((string) $name);
            if (! str_starts_with($header, 'x-ratelimit-') && $header !== 'retry-after') {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }
            $allowed[$header] = is_scalar($value) ? (string) $value : null;
        }

        return $allowed;
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSecretKey($key)) {
            return null;
        }

        if ($value instanceof \Throwable) {
            return [
                'type' => $value::class,
                'message' => $this->sanitizeValue($value->getMessage()),
            ];
        }

        if (is_array($value)) {
            if ($key === 'response_headers') {
                return $this->rateLimitHeaders($value);
            }

            $clean = [];
            foreach ($value as $childKey => $childValue) {
                if (is_string($childKey) && $this->isSecretKey($childKey)) {
                    continue;
                }
                $clean[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $clean;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b(authorization|x-api-key|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? $value;

        return $this->sanitizeUrlCredentials($value);
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        return in_array($normalized, self::SECRET_KEYS, true)
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'access_token')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'credential')
            || str_contains($normalized, 'signature');
    }

    private function sanitizeUrlCredentials(string $value): string
    {
        return preg_replace_callback(
            '#https?://[^\s"\'<>]+#i',
            function (array $matches): string {
                $url = $matches[0];
                $parts = parse_url($url);
                if (! is_array($parts)) {
                    return $url;
                }

                $query = $parts['query'] ?? null;
                $hasUserInfo = isset($parts['user']) || isset($parts['pass']);
                if ((! is_string($query) || $query === '') && ! $hasUserInfo) {
                    return $url;
                }

                $parameters = [];
                if (is_string($query) && $query !== '') {
                    parse_str($query, $parameters);
                }
                foreach (array_keys($parameters) as $parameter) {
                    if ($this->isSecretKey((string) $parameter)) {
                        $parameters[$parameter] = '[REDACTED]';
                    }
                }
                $parts['query'] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

                return $this->buildUrl($parts);
            },
            $value,
        ) ?? $value;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function buildUrl(array $parts): string
    {
        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
