<?php

namespace App\Modules\Identity\Domain;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Password rules for every school account: at least 12 characters (ASVS 2.1.1), not the
 * person's login or name (assertNotPersonal), and not a common password. The common list is bundled with the app
 * (resources/security/common-passwords.txt); no outside service is called.
 *
 * Besides the exact list, a password is refused when it is only a common
 * word (or a run of one character, or a keyboard or digit run) padded with
 * digits and symbols, such as "password@123" or "1234567890".
 */
final class PasswordPolicy implements ValidationRule
{
    /** Words that make a weak password however many digits follow them. */
    private const WEAK_BASES = [
        'password', 'passw', 'pass', 'qwerty', 'qwertyuiop', 'asdfgh', 'zxcvbn', 'abc', 'abcd', 'abcdef', 'abcdefgh',
        'iloveyou', 'welcome', 'admin', 'administrator', 'letmein', 'india', 'bharat', 'hindustan', 'school',
        'campus', 'campustab', 'teacher', 'principal', 'student', 'parent', 'clerk', 'monkey', 'dragon',
        'sunshine', 'princess', 'football', 'cricket', 'baseball', 'superman', 'batman', 'master', 'login',
        'test', 'changeme', 'default', 'secret', 'computer', 'internet', 'hello', 'jaihind', 'jaishreeram',
        'krishna', 'ganesh', 'mumbai', 'delhi', 'bhopal', 'indore', 'kolkata', 'chennai', 'pune',
    ];

    /** @var array<string, true>|null */
    private static ?array $list = null;

    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', 'max:200', Password::min((int) config('identity.passwords.min_length', 12)), new self];
    }

    public function validate(string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isCommon($value)) {
            $fail('This password is too common or too easy to guess. Use a longer phrase only you would know.');
        }
    }

    /**
     * The password must not be the person's login or name, alone or padded
     * with digits and symbols ("kavya.rao2026", "+919811100001").
     *
     * @param  list<string>  $words  from User::personalWords()
     *
     * @throws ValidationException
     */
    public static function assertNotPersonal(#[SensitiveParameter] string $password, array $words, string $attribute = 'password'): void
    {
        $plain = mb_strtolower(trim($password));
        $letters = preg_replace('/[^\pL]/u', '', $plain) ?? '';

        foreach ($words as $word) {
            $word = mb_strtolower(trim($word));
            $wordLetters = preg_replace('/[^\pL]/u', '', $word) ?? '';

            if ($word === '') {
                continue;
            }

            $sameWord = $plain === $word || preg_replace('/[\s\-()]/', '', $plain) === preg_replace('/[\s\-()]/', '', $word);
            $paddedName = mb_strlen($wordLetters) >= 3 && $letters === $wordLetters;

            if ($sameWord || $paddedName) {
                throw ValidationException::withMessages([
                    $attribute => 'Your password cannot be your name or how you sign in.',
                ]);
            }
        }
    }

    public static function isCommon(#[SensitiveParameter] string $password): bool
    {
        $lower = mb_strtolower(trim($password));

        if (isset(self::list()[$lower])) {
            return true;
        }

        // One character repeated, or a straight run of digits or letters.
        if (preg_match('/^(.)\1+$/u', $lower) === 1 || self::isRun($lower)) {
            return true;
        }

        // A weak word padded with digits and symbols: "password@123", "123india!".
        $letters = preg_replace('/[^a-z]/', '', $lower) ?? '';

        if ($letters !== '' && in_array($letters, self::WEAK_BASES, true)) {
            return true;
        }

        return $letters === '' && self::isRun(preg_replace('/\D/', '', $lower) ?? '');
    }

    private static function isRun(string $value): bool
    {
        if (strlen($value) < 6) {
            return false;
        }

        foreach (['0123456789012345678901234567890', '9876543210987654321098765432109', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm'] as $run) {
            if (str_contains($run, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function list(): array
    {
        if (self::$list !== null) {
            return self::$list;
        }

        $path = (string) config('identity.passwords.common_list');
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        self::$list = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                self::$list[mb_strtolower($line)] = true;
            }
        }

        return self::$list;
    }
}
