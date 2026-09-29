<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether the server may send a request to a URL.
 *
 * Checking the hostname as written is not enough: `127.1`, `2130706433`, a
 * name that resolves to 10.0.0.5 and an IPv4-mapped IPv6 address all reach
 * the server's own network while looking harmless. So the host is resolved
 * and every address it resolves to must be on the public internet. One
 * private record among public ones is refused too, because the connection
 * could land on either.
 */
class PublicAddress
{
    /**
     * Ranges filter_var's global-range check lets through that still lead
     * somewhere the server should not go: NAT64 and 6to4 embed an IPv4
     * address, and multicast is never a webhook receiver.
     *
     * @var list<string>
     */
    private const EXTRA_BLOCKED_RANGES = ['64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '224.0.0.0/4', 'ff00::/8'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Resolve the URL's host to the addresses a request may connect to.
     *
     * Null when the host does not resolve or any of its addresses is not
     * public.
     *
     * @return non-empty-list<string>|null
     */
    public function addressesFor(string $url): ?array
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return null;
            }
        }

        return $addresses;
    }

    /**
     * Build the curl `resolve` entry that pins the request to a checked address.
     *
     * Without the pin curl resolves the name again on connect, and a DNS
     * answer with a short TTL can point it somewhere else in between.
     */
    public function pin(string $url, string $address): string
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443);

        return sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? "[{$address}]" : $address);
    }

    /**
     * Determine whether an address is on the public internet.
     */
    public function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! IpUtils::checkIp($address, self::EXTRA_BLOCKED_RANGES);
    }
}
