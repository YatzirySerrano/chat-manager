<?php

namespace App\Services\LinkPreviews;

/**
 * The IP addresses a host name points to (IPv4 and IPv6). Bound in the
 * container so tests answer without real DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> Empty when the name does not resolve.
     */
    public function resolve(string $host): array;
}
