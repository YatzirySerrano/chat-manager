<?php

namespace App\Services\LinkPreviews;

/**
 * Resolves through the system resolver: A and AAAA records, with
 * gethostbynamel() when the DNS functions return nothing (hosts file).
 */
class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);

            foreach (is_array($fallback) ? $fallback : [] as $address) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
