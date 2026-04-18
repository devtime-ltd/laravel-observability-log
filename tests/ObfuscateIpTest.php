<?php

use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;

describe('IPv4', function () {
    it('masks one octet', fn () => expect(ObfuscateIp::level(1)('198.51.100.123'))->toBe('198.51.100.0'));
    it('masks two octets', fn () => expect(ObfuscateIp::level(2)('198.51.100.123'))->toBe('198.51.0.0'));
    it('masks three octets', fn () => expect(ObfuscateIp::level(3)('198.51.100.123'))->toBe('198.0.0.0'));
    it('masks fully', fn () => expect(ObfuscateIp::level(4)('198.51.100.123'))->toBe('0.0.0.0'));
});

describe('IPv6', function () {
    $ip = '2001:0db8:1234:5678:9abc:def0:1234:5678';

    it('masks one level', fn () => expect(ObfuscateIp::level(1)($ip))->toBe('2001:db8:1234:5678:9abc:def0::'));
    it('masks two levels', fn () => expect(ObfuscateIp::level(2)($ip))->toBe('2001:db8:1234:5678::'));
    it('masks three levels', fn () => expect(ObfuscateIp::level(3)($ip))->toBe('2001:db8::'));
    it('masks fully', fn () => expect(ObfuscateIp::level(4)($ip))->toBe('::'));
});

it('returns null for null input', function () {
    expect(ObfuscateIp::level(1)(null))->toBeNull();
});

it('rejects levels outside 1-4', function () {
    expect(fn () => ObfuscateIp::level(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => ObfuscateIp::level(5))->toThrow(InvalidArgumentException::class);
});
