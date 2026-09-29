<?php

namespace App\Support;

/**
 * Looks a hostname up in DNS.
 *
 * The one place the application asks DNS anything, so a test can swap it
 * for a fixed answer instead of depending on the network.
 */
class HostResolver
{
    /**
     * Get every IPv4 and IPv6 address the name points at.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
