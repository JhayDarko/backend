<?php

namespace App\Services\Article;

use App\Contracts\Article\HostResolverInterface;

final class SystemHostResolver implements HostResolverInterface
{
    public function resolve(string $hostname): array
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return [$hostname];
        }

        set_error_handler(static fn (): bool => true);

        try {
            $records = dns_get_record($hostname, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = match ($record['type'] ?? null) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                default => null,
            };

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
