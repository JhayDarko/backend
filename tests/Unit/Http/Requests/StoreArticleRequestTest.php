<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\StoreArticleRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreArticleRequestTest extends TestCase
{
    public function test_url_rule_rejects_unsupported_schemes_credentials_and_ports(): void
    {
        foreach ([
            'ftp://news.example.test/story',
            'https://user:pass@news.example.test/story',
            'https://news.example.test:8443/story',
        ] as $url) {
            $validator = Validator::make(
                ['url' => $url],
                (new StoreArticleRequest)->rules(),
            );

            $this->assertFalse($validator->passes(), $url);
        }
    }

    public function test_url_rule_accepts_public_http_and_https_urls_on_standard_ports(): void
    {
        foreach ([
            'http://news.example.test/story',
            'https://news.example.test:443/story',
        ] as $url) {
            $validator = Validator::make(
                ['url' => $url],
                (new StoreArticleRequest)->rules(),
            );

            $this->assertTrue($validator->passes(), $url);
        }
    }
}
