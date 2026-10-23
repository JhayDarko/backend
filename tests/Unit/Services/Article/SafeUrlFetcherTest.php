<?php

namespace Tests\Unit\Services\Article;

use App\Contracts\Article\HostResolverInterface;
use App\Services\Article\SafeUrlFetcher;
use App\Services\Article\SafeUrlFetchException;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeUrlFetcherTest extends TestCase
{
    public function test_fetches_html_from_a_public_host(): void
    {
        Http::fake([
            'https://news.example.test/story' => Http::response('<html>Noticia</html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        $this->assertSame('<html>Noticia</html>', $fetcher->fetch('https://news.example.test/story'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://news.example.test/story');
    }

    public function test_accepts_public_ipv6_addresses(): void
    {
        Http::fake([
            'https://[2606:4700:4700::1111]/' => Http::response('<html>IPv6</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $fetcher = $this->fetcher(fn (string $host): array => ['2606:4700:4700::1111']);

        $this->assertSame('<html>IPv6</html>', $fetcher->fetch('https://[2606:4700:4700::1111]/'));
    }

    #[DataProvider('blockedAddressUrls')]
    public function test_rejects_non_public_addresses(string $url, string $address): void
    {
        Http::fake();
        $fetcher = $this->fetcher(fn (string $host): array => [$address]);

        $this->assertFetchFails($fetcher, $url, SafeUrlFetchException::BLOCKED);
        Http::assertNothingSent();
    }

    public static function blockedAddressUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/', '127.0.0.1'],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'],
            'link-local' => ['http://169.254.2.5/', '169.254.2.5'],
            'private RFC1918' => ['http://10.1.2.3/', '10.1.2.3'],
            'unspecified' => ['http://0.0.0.0/', '0.0.0.0'],
            'CGNAT' => ['http://100.64.0.1/', '100.64.0.1'],
            'documentation range' => ['http://192.0.2.1/', '192.0.2.1'],
            'benchmark range' => ['http://198.18.0.1/', '198.18.0.1'],
            'multicast' => ['http://224.0.0.1/', '224.0.0.1'],
            'reserved' => ['http://240.0.0.1/', '240.0.0.1'],
            'IPv6 loopback' => ['http://[::1]/', '::1'],
            'IPv6 ULA' => ['http://[fc00::1]/', 'fc00::1'],
            'IPv6 link-local' => ['http://[fe80::1]/', 'fe80::1'],
            'IPv4 mapped IPv6' => ['http://[::ffff:127.0.0.1]/', '::ffff:127.0.0.1'],
            'IPv6 reserved' => ['http://[2001:db8::1]/', '2001:db8::1'],
        ];
    }

    public function test_rejects_a_hostname_if_any_resolved_address_is_not_public(): void
    {
        Http::fake();
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34', '10.0.0.8']);

        $this->assertFetchFails($fetcher, 'https://news.example.test/', SafeUrlFetchException::BLOCKED);
        Http::assertNothingSent();
    }

    public function test_rejects_unsupported_schemes_credentials_and_ports(): void
    {
        Http::fake();
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        foreach ([
            'ftp://news.example.test/story',
            'https://user:pass@news.example.test/story',
            'https://news.example.test:8443/story',
        ] as $url) {
            $this->assertFetchFails($fetcher, $url, SafeUrlFetchException::BLOCKED);
        }

        Http::assertNothingSent();
    }

    public function test_rejects_noncanonical_numeric_ipv4_host_notation(): void
    {
        Http::fake();
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        foreach ([
            'http://127.1/',
            'http://2130706433/',
            'http://0x7f000001/',
            'http://0177.0.0.1/',
        ] as $url) {
            $this->assertFetchFails($fetcher, $url, SafeUrlFetchException::BLOCKED);
        }

        Http::assertNothingSent();
    }

    public function test_revalidates_redirect_destinations_before_requesting_them(): void
    {
        Http::fake([
            'https://news.example.test/story' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $fetcher = $this->fetcher(fn (string $host): array => match ($host) {
            'news.example.test' => ['93.184.216.34'],
            '169.254.169.254' => ['169.254.169.254'],
            default => [],
        });

        $this->assertFetchFails($fetcher, 'https://news.example.test/story', SafeUrlFetchException::BLOCKED);
        Http::assertSentCount(1);
    }

    public function test_rechecks_dns_on_each_redirect_to_prevent_rebinding(): void
    {
        Http::fake([
            'https://news.example.test/story' => Http::response('', 302, ['Location' => '/next']),
        ]);

        $resolutions = 0;
        $fetcher = $this->fetcher(function (string $host) use (&$resolutions): array {
            $resolutions++;

            return $resolutions === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
        });

        $this->assertFetchFails($fetcher, 'https://news.example.test/story', SafeUrlFetchException::BLOCKED);
        $this->assertSame(2, $resolutions);
        Http::assertSentCount(1);
    }

    public function test_follows_up_to_three_redirects_manually(): void
    {
        Http::fake([
            'https://news.example.test/one' => Http::response('', 302, ['Location' => '/two']),
            'https://news.example.test/two' => Http::response('', 302, ['Location' => '/three']),
            'https://news.example.test/three' => Http::response('', 302, ['Location' => '/four']),
            'https://news.example.test/four' => Http::response('<html>Final</html>', 200, [
                'Content-Type' => 'application/xhtml+xml',
            ]),
        ]);
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        $this->assertSame('<html>Final</html>', $fetcher->fetch('https://news.example.test/one'));
        Http::assertSentCount(4);
    }

    public function test_stops_after_the_redirect_limit(): void
    {
        Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => '/next']));
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        $this->assertFetchFails($fetcher, 'https://news.example.test/one', SafeUrlFetchException::FETCH_FAILED);
        Http::assertSentCount(4);
    }

    public function test_rejects_responses_over_two_megabytes(): void
    {
        Http::fake([
            '*' => Http::response(str_repeat('x', 2_097_153), 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        $this->assertFetchFails($fetcher, 'https://news.example.test/story', SafeUrlFetchException::RESPONSE_TOO_LARGE);
    }

    public function test_rejects_non_html_content_types_and_encoded_bodies(): void
    {
        Http::fake([
            'https://news.example.test/json' => Http::response('{}', 200, [
                'Content-Type' => 'application/json',
            ]),
            'https://news.example.test/compressed' => Http::response('body', 200, [
                'Content-Type' => 'text/html',
                'Content-Encoding' => 'gzip',
            ]),
        ]);
        $fetcher = $this->fetcher(fn (string $host): array => ['93.184.216.34']);

        $this->assertFetchFails($fetcher, 'https://news.example.test/json', SafeUrlFetchException::CONTENT_TYPE);
        $this->assertFetchFails($fetcher, 'https://news.example.test/compressed', SafeUrlFetchException::CONTENT_TYPE);
    }

    private function fetcher(callable $resolve): SafeUrlFetcher
    {
        $resolver = new class(Closure::fromCallable($resolve)) implements HostResolverInterface
        {
            public function __construct(private readonly Closure $resolve) {}

            public function resolve(string $hostname): array
            {
                return ($this->resolve)($hostname);
            }
        };

        return new SafeUrlFetcher($resolver);
    }

    private function assertFetchFails(SafeUrlFetcher $fetcher, string $url, string $reason): void
    {
        try {
            $fetcher->fetch($url);
            $this->fail("Expected {$reason} for {$url}.");
        } catch (SafeUrlFetchException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
