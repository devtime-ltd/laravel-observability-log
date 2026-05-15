<?php

use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;

describe('IPv4', function () {
    it('masks one octet', fn () => expect(ObfuscateIp::levelOne('198.51.100.123'))->toBe('198.51.100.0'));
    it('masks two octets', fn () => expect(ObfuscateIp::levelTwo('198.51.100.123'))->toBe('198.51.0.0'));
    it('masks three octets', fn () => expect(ObfuscateIp::levelThree('198.51.100.123'))->toBe('198.0.0.0'));
    it('masks fully', fn () => expect(ObfuscateIp::levelFour('198.51.100.123'))->toBe('0.0.0.0'));
});

describe('IPv6', function () {
    $ip = '2001:0db8:1234:5678:9abc:def0:1234:5678';

    it('masks one level', fn () => expect(ObfuscateIp::levelOne($ip))->toBe('2001:db8:1234:5678:9abc:def0::'));
    it('masks two levels', fn () => expect(ObfuscateIp::levelTwo($ip))->toBe('2001:db8:1234:5678::'));
    it('masks three levels', fn () => expect(ObfuscateIp::levelThree($ip))->toBe('2001:db8::'));
    it('masks fully', fn () => expect(ObfuscateIp::levelFour($ip))->toBe('::'));
});

it('returns null for null input', function () {
    expect(ObfuscateIp::levelOne(null))->toBeNull();
    expect(ObfuscateIp::levelTwo(null))->toBeNull();
    expect(ObfuscateIp::levelThree(null))->toBeNull();
    expect(ObfuscateIp::levelFour(null))->toBeNull();
});

it('is var_export-safe as an array callable (config:cache compatible)', function () {
    $exported = var_export([ObfuscateIp::class, 'levelTwo'], true);
    $callable = eval('return '.$exported.';');

    expect(is_callable($callable))->toBeTrue();
    expect(call_user_func($callable, '198.51.100.123'))->toBe('198.51.0.0');
});
