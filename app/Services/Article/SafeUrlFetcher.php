<?php

namespace App\Services\Article;

use App\Contracts\Article\HostResolverInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 3;

    private const MAX_RESPONSE_BYTES = 2_097_152;

    private const TOTAL_TIMEOUT_SECONDS = 20;

    private const CONNECT_TIMEOUT_SECONDS = 8;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HostResolverInterface $hostResolver,
    ) {}

    public function fetch(string $url): string
    {
        $uri = $this->validatedUri($url);
        $deadline = hrtime(true) + (self::TOTAL_TIMEOUT_SECONDS * 1_000_000_000);

        for ($redirects = 0; ; $redirects++) {
            [, $resolveEntry] = $this->resolveAndPin($uri);
            $remainingTimeout = ($deadline - hrtime(true)) / 1_000_000_000;
            if ($remainingTimeout <= 0) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::FETCH_FAILED,
                    'The article server did not respond in time.',
                );
            }

            $response = $this->send($uri, $resolveEntry, $remainingTimeout);
            $status = $response['status'];

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new SafeUrlFetchException(
                        SafeUrlFetchException::FETCH_FAILED,
                        'The URL exceeded the redirect limit.',
                    );
                }

                $location = $response['location'];
                if (! is_string($location) || trim($location) === '') {
                    throw new SafeUrlFetchException(
                        SafeUrlFetchException::FETCH_FAILED,
                        'The redirect response did not contain a destination.',
                    );
                }

                try {
                    $uri = $this->validatedUri((string) UriResolver::resolve($uri, new Uri($location)));
                } catch (SafeUrlFetchException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new SafeUrlFetchException(
                        SafeUrlFetchException::BLOCKED,
                        'The redirect destination is not allowed.',
                        $exception,
                    );
                }

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::FETCH_FAILED,
                    "The article server returned HTTP {$status}.",
                );
            }

            $contentType = strtolower(trim(explode(';', (string) $response['content_type'], 2)[0]));
            if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)
                || ! in_array(strtolower((string) $response['content_encoding']), ['', 'identity'], true)) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::CONTENT_TYPE,
                    'The URL did not return an uncompressed HTML document.',
                );
            }

            return $response['body'];
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveAndPin(Uri $uri): array
    {
        $uriHost = $uri->getHost();
        $host = trim($uriHost, '[]');
        $addresses = $this->hostResolver->resolve($host);

        if ($addresses === []) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::FETCH_FAILED,
                'The URL host could not be resolved.',
            );
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::BLOCKED,
                    'The URL host resolves to a non-public address.',
                );
            }
        }

        $address = $addresses[0];
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$address, null];
        }

        $curlAddress = str_contains($address, ':') ? "[{$address}]" : $address;

        return [$address, "{$host}:{$port}:{$curlAddress}"];
    }

    /**
     * @return array{status: int, location: ?string, content_type: ?string, content_encoding: ?string, body: string}
     */
    private function send(Uri $uri, ?string $resolveEntry, float $remainingTimeout): array
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::FETCH_FAILED,
                'The HTTP runtime cannot pin the validated host address.',
            );
        }

        $stream = fopen('php://temp/maxmemory:'.self::MAX_RESPONSE_BYTES, 'w+b');
        if ($stream === false) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::FETCH_FAILED,
                'A temporary response stream could not be opened.',
            );
        }

        try {
            $curlOptions = $resolveEntry === null
                ? []
                : [CURLOPT_RESOLVE => [$resolveEntry]];
            $response = Http::timeout($remainingTimeout)
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remainingTimeout))
                ->withOptions([
                    'allow_redirects' => false,
                    'decode_content' => false,
                    'proxy' => '',
                    'sink' => $stream,
                    'on_headers' => function (ResponseInterface $response): void {
                        $contentLength = $response->getHeaderLine('Content-Length');
                        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
                            throw new SafeUrlFetchException(
                                SafeUrlFetchException::RESPONSE_TOO_LARGE,
                                'The HTML response exceeded the size limit.',
                            );
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloadedBytes): void {
                        if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                            throw new SafeUrlFetchException(
                                SafeUrlFetchException::RESPONSE_TOO_LARGE,
                                'The HTML response exceeded the size limit.',
                            );
                        }
                    },
                    'curl' => [
                        ...$curlOptions,
                    ],
                ])
                ->withHeaders([
                    'User-Agent' => 'FactNode/1.0',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'es-CO,es;q=0.9,en;q=0.8',
                    'Accept-Encoding' => 'identity',
                ])
                ->get((string) $uri);

            rewind($stream);
            $body = stream_get_contents($stream);
            $body = $body === '' ? $response->body() : $body;

            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::RESPONSE_TOO_LARGE,
                    'The HTML response exceeded the size limit.',
                );
            }

            return [
                'status' => $response->status(),
                'location' => $response->header('Location'),
                'content_type' => $response->header('Content-Type'),
                'content_encoding' => $response->header('Content-Encoding'),
                'body' => $body,
            ];
        } catch (SafeUrlFetchException $exception) {
            throw $exception;
        } catch (ConnectionException|TransferException $exception) {
            $previous = $exception->getPrevious();
            if ($previous instanceof SafeUrlFetchException) {
                throw $previous;
            }

            throw new SafeUrlFetchException(
                SafeUrlFetchException::FETCH_FAILED,
                'The article server could not be reached.',
                $exception,
            );
        } finally {
            fclose($stream);
        }
    }

    private function validatedUri(string $url): Uri
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable $exception) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::BLOCKED,
                'The URL is invalid.',
                $exception,
            );
        }

        $scheme = strtolower($uri->getScheme());
        $uriHost = $uri->getHost();
        $host = strtolower(rtrim(trim($uriHost, '[]'), '.'));
        $port = $uri->getPort();

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || $uri->getUserInfo() !== ''
            || ($port !== null && ! in_array($port, [80, 443], true))
            || str_contains($host, '%')
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::BLOCKED,
                'The URL is not allowed.',
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $normalizedHost = str_contains($host, ':') ? "[{$host}]" : $host;
        } elseif (str_contains($host, ':') || preg_match('/^[0-9.]+$/', $host) === 1) {
            throw new SafeUrlFetchException(
                SafeUrlFetchException::BLOCKED,
                'The URL host is invalid.',
            );
        } else {
            $asciiHost = function_exists('idn_to_ascii')
                ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : $host;

            if (! is_string($asciiHost) || $asciiHost === '') {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::BLOCKED,
                    'The URL host is invalid.',
                );
            }

            $normalizedHost = strtolower($asciiHost);
            if (! $this->isValidDnsHostname($normalizedHost)) {
                throw new SafeUrlFetchException(
                    SafeUrlFetchException::BLOCKED,
                    'The URL host is invalid.',
                );
            }
        }

        return $uri
            ->withScheme($scheme)
            ->withHost($normalizedHost)
            ->withFragment('');
    }

    private function isValidDnsHostname(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return preg_match('/^(?:0[x][0-9a-f]+|[0-9]+)(?:\.(?:0[x][0-9a-f]+|[0-9]+)){0,3}$/i', $host) !== 1;
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }

            foreach ([
                ['100.64.0.0', 10],
                ['192.0.0.0', 24],
                ['192.0.2.0', 24],
                ['192.88.99.0', 24],
                ['198.18.0.0', 15],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ] as [$network, $prefix]) {
                if ($this->matchesCidr($packed, inet_pton($network), $prefix)) {
                    return false;
                }
            }

            return true;
        }

        if (strlen($packed) !== 16
            || str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || ! $this->matchesCidr($packed, inet_pton('2000::'), 3)) {
            return false;
        }

        foreach ([
            ['2001::', 23],
            ['2001:db8::', 32],
            ['2002::', 16],
            ['3fff::', 20],
            ['64:ff9b::', 96],
            ['64:ff9b:1::', 48],
        ] as [$network, $prefix]) {
            if ($this->matchesCidr($packed, inet_pton($network), $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCidr(string $address, string|false $network, int $prefix): bool
    {
        if ($network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
