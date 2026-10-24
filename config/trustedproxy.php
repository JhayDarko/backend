<?php

$trustedProxies = env('TRUSTED_PROXIES');

if (is_string($trustedProxies)) {
    $trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
}

return [
    'proxies' => $trustedProxies ?: ['127.0.0.1', '::1'],
];
