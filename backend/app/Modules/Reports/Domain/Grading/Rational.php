<?php

namespace App\Modules\Reports\Domain\Grading;

use InvalidArgumentException;

/**
 * An exact fraction of two integers. The grading engine works only in these
 * (marks arrive as whole half-marks), so no floating-point error can creep
 * into a result; the scheme's rounding rule is applied once, at the end,
 * through round().
 */
final class Rational
{
    public readonly int $num;

    public readonly int $den;

    public function __construct(int $num, int $den = 1)
    {
        if ($den === 0) {
            throw new InvalidArgumentException('A fraction cannot have a zero denominator.');
        }
        if ($den < 0) {
            [$num, $den] = [-$num, -$den];
        }
        $g = self::gcd(abs($num), $den);
        $this->num = intdiv($num, $g);
        $this->den = intdiv($den, $g);
    }

    public static function of(int $num, int $den = 1): self
    {
        return new self($num, $den);
    }

    public static function zero(): self
    {
        return new self(0, 1);
    }

    /**
     * An exact value from a decimal written in a scheme ("12.5", 10, 33).
     * Floats are read through their shortest decimal form, so 12.5 is 25/2.
     */
    public static function fromDecimal(int|float|string $value): self
    {
        if (is_int($value)) {
            return new self($value);
        }
        $text = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : trim($value);
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,6}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("Not a decimal number: {$text}");
        }
        $decimals = $m[3] ?? '';
        $num = (int) ($m[2].$decimals);

        return new self(($m[1] === '-' ? -1 : 1) * $num, 10 ** strlen($decimals));
    }

    public function add(self $o): self
    {
        return new self($this->num * $o->den + $o->num * $this->den, $this->den * $o->den);
    }

    public function sub(self $o): self
    {
        return new self($this->num * $o->den - $o->num * $this->den, $this->den * $o->den);
    }

    public function mul(self $o): self
    {
        return new self($this->num * $o->num, $this->den * $o->den);
    }

    public function div(self $o): self
    {
        return new self($this->num * $o->den, $this->den * $o->num);
    }

    public function compare(self $o): int
    {
        return ($this->num * $o->den) <=> ($o->num * $this->den);
    }

    public function lessThan(self $o): bool
    {
        return $this->compare($o) < 0;
    }

    public function isZero(): bool
    {
        return $this->num === 0;
    }

    /**
     * Rounds to `places` decimals with the scheme's rule:
     *   half_up    0.5 goes up (percentages are never negative)
     *   half_even  0.5 goes to the even neighbour
     *   up / down  ceiling / floor
     *   none       no rule chosen: two decimals, half up, for display
     */
    public function round(string $mode, int $places = 0): self
    {
        if ($mode === 'none') {
            $mode = 'half_up';
            $places = 2;
        }
        $scale = 10 ** $places;
        $scaled = $this->mul(new self($scale));
        $floor = intdiv($scaled->num, $scaled->den);
        if ($scaled->num < 0 && $scaled->num % $scaled->den !== 0) {
            $floor--;
        }
        $remainder = $scaled->sub(new self($floor));

        $result = match ($mode) {
            'down' => $floor,
            'up' => $remainder->isZero() ? $floor : $floor + 1,
            'half_up' => $remainder->compare(new self(1, 2)) >= 0 ? $floor + 1 : $floor,
            'half_even' => match ($remainder->compare(new self(1, 2))) {
                1 => $floor + 1,
                -1 => $floor,
                default => $floor % 2 === 0 ? $floor : $floor + 1,
            },
            default => throw new InvalidArgumentException("Unknown rounding rule: {$mode}"),
        };

        return new self($result, $scale);
    }

    /** Exact decimal text when the denominator divides a power of ten; otherwise to `places` decimals, cut (never rounded). */
    public function toDecimal(int $places = 2): string
    {
        $negative = $this->num < 0;
        $abs = abs($this->num);
        $whole = intdiv($abs, $this->den);
        $rest = $abs % $this->den;
        $digits = '';
        for ($i = 0; $i < $places && $rest !== 0; $i++) {
            $rest *= 10;
            $digits .= (string) intdiv($rest, $this->den);
            $rest %= $this->den;
        }

        return ($negative && ($whole !== 0 || $digits !== '') ? '-' : '').$whole.($digits === '' ? '' : '.'.$digits);
    }

    public function toFloat(): float
    {
        return $this->num / $this->den;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
