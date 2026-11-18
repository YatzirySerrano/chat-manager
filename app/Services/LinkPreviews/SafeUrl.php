<?php

namespace App\Services\LinkPreviews;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether the server may open a web address on a viewer's behalf
 * (server-side request forgery protection). Only http and https on their
 * standard ports, without user names or passwords, to a host whose every
 * resolved address is public: private networks, loopback, link-local
 * (cloud metadata), carrier-grade NAT, multicast, documentation and other
 * reserved ranges are refused, as are IPv6 forms that embed an IPv4
 * address. The checked address is returned so the connection is made to it
 * and not to whatever a second DNS lookup might answer (DNS rebinding).
 */
class SafeUrl
{
    /** @var list<string> */
    private const BLOCKED_RANGES = [
        ...IpUtils::PRIVATE_SUBNETS,
        '192.0.0.0/24',    // IETF protocol assignments
        '224.0.0.0/4',     // Multicast
        '255.255.255.255/32',
        'ff00::/8',        // IPv6 multicast
        '100::/64',        // Discard-only
    ];

    public function __construct(private HostResolver $resolver) {}

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     *
     * @throws BlockedAddress
     */
    public function check(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new BlockedAddress('invalid_url');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new BlockedAddress('invalid_url');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedAddress('invalid_scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedAddress('invalid_url');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new BlockedAddress('blocked_port');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        // An internationalized name is written in its ASCII form, also in
        // the address the connection uses.
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw new BlockedAddress('invalid_url');
            }

            $url = (string) preg_replace('/'.preg_quote($parts['host'], '/').'/u', $ascii, $url, 1);
            $host = $ascii;
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new BlockedAddress('blocked_address');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            // Numeric forms the resolver would read as an address ("2130706433", "0x7f.1").
            if (preg_match('/^[0-9.]+$|^0x/i', $host) === 1) {
                throw new BlockedAddress('blocked_address');
            }

            $addresses = $this->resolver->resolve($host);
        }

        if ($addresses === []) {
            throw new BlockedAddress('unresolved');
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new BlockedAddress('blocked_address');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }
}
