<?php

use App\Support\IpAllowList;

it('allows everything while it is empty', function () {
    $allowList = IpAllowList::make([]);

    expect($allowList->isEmpty())->toBeTrue()
        ->and($allowList->allows('203.0.113.4'))->toBeTrue()
        ->and($allowList->allows(null))->toBeTrue();
});

it('matches a bare address', function () {
    $allowList = IpAllowList::make(['203.0.113.4']);

    expect($allowList->allows('203.0.113.4'))->toBeTrue()
        ->and($allowList->allows('203.0.113.5'))->toBeFalse();
});

it('matches a cidr range', function (string $ip, bool $allowed) {
    expect(IpAllowList::make(['10.0.0.0/8'])->allows($ip))->toBe($allowed);
})->with([
    ['10.0.0.1', true],
    ['10.255.255.254', true],
    ['11.0.0.1', false],
]);

it('matches an ipv6 range', function () {
    $allowList = IpAllowList::make(['2001:db8::/32']);

    expect($allowList->allows('2001:db8::1'))->toBeTrue()
        ->and($allowList->allows('2001:db9::1'))->toBeFalse();
});

it('refuses an address it cannot see once the list restricts something', function () {
    expect(IpAllowList::make(['203.0.113.4'])->allows(null))->toBeFalse();
});

it('parses free form text and drops duplicates', function () {
    $allowList = IpAllowList::parse("203.0.113.4\n10.0.0.0/8, 203.0.113.4  192.0.2.1\n\n");

    expect($allowList->toArray())->toBe(['203.0.113.4', '10.0.0.0/8', '192.0.2.1']);
});

it('stores nothing rather than an empty list', function () {
    expect(IpAllowList::parse('  ')->toStorage())->toBeNull()
        ->and(IpAllowList::parse('203.0.113.4')->toStorage())->toBe(['203.0.113.4']);
});

it('recognises usable entries', function (string $entry, bool $valid) {
    expect(IpAllowList::isValidEntry($entry))->toBe($valid);
})->with([
    ['203.0.113.4', true],
    ['10.0.0.0/8', true],
    ['2001:db8::/32', true],
    ['203.0.113.4/33', false],
    ['2001:db8::/129', false],
    ['not-an-ip', false],
    ['203.0.113.4/', false],
    ['203.0.113.999', false],
]);
