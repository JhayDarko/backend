<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_forwarded_ip_is_ignored_when_remote_address_is_not_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->registerClientIpRoute();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.20'])
            ->withHeader('X-Forwarded-For', '203.0.113.45')
            ->getJson('/__test/client-ip')
            ->assertOk()
            ->assertJsonPath('ip', '10.0.0.20');
    }

    public function test_forwarded_ip_is_used_when_remote_address_is_trusted(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.20']]);
        $this->registerClientIpRoute();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.20'])
            ->withHeader('X-Forwarded-For', '203.0.113.45')
            ->getJson('/__test/client-ip')
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.45');
    }

    private function registerClientIpRoute(): void
    {
        Route::get('/__test/client-ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
        ]));
    }
}
