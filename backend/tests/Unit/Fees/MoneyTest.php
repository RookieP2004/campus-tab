<?php

use App\Modules\Fees\Domain\FeeCounters;
use App\Modules\Fees\Domain\Money;
use App\Support\Http\ApiException;

/*
| Money is whole paise; rupee strings are parsed digit by digit, never
| through floating point (owner's rule 1).
*/

it('parses rupee strings exactly into paise', function (string $in, int $paise) {
    expect(Money::parse($in))->toBe($paise);
})->with([
    ['0.1', 10], ['0.10', 10], ['1250', 125000], ['1,250.5', 125050], ['₹ 1,00,000.99', 10000099], ['19.99', 1999], ['0.29', 29],
]);

it('refuses anything that is not a plain amount', function (string $in) {
    expect(fn () => Money::parse($in))->toThrow(ApiException::class);
})->with(['10.555', '-5', '1e3', 'abc', '', '12.']);

it('formats paise back to rupees', function () {
    expect(Money::format(125050))->toBe('1250.50')->and(Money::format(5))->toBe('0.05')->and(Money::format(-100))->toBe('-1.00');
});

it('takes a percentage with integer arithmetic, rounding half up once', function () {
    expect(Money::percentOf(150050, 1000))->toBe(15005)
        ->and(Money::percentOf(333, 5000))->toBe(167)
        ->and(Money::percentOf(1, 5000))->toBe(1)
        ->and(Money::basisPoints('12.5'))->toBe(1250)
        ->and(fn () => Money::basisPoints('101'))->toThrow(ApiException::class);
});

it('builds receipt numbers in sequence with the school prefix', function () {
    expect(FeeCounters::receiptNumber('SPS', '2026-27', 42))->toBe('SPS/2026-27/000042')
        ->and(FeeCounters::receiptNumber(null, '2026-27', 1))->toBe('2026-27/000001')
        ->and(FeeCounters::receiptNumber('SPS/2026-27/', '2026-27', 7))->toBe('SPS/2026-27/000007')
        ->and(FeeCounters::receiptNumber('RCPT-', '2026-27', 7))->toBe('RCPT-2026-27/000007');
});
