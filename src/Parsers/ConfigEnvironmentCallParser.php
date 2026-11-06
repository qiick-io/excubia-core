<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

/**
 * Finds the environment keys a Laravel config file reads, with PHP's
 * tokenizer. The file is never included or executed.
 *
 * Recognised calls, with a string literal as first argument: `env('KEY')`,
 * `\env('KEY')`, `Env::get('KEY')` and `Env::getOrFail('KEY')` (also with
 * the `Illuminate\Support\Env` name written out). Methods called `env`
 * (`$this->env()`, `Foo::env()`), function declarations and calls with a
 * computed or interpolated key are ignored.
 */
final class ConfigEnvironmentCallParser
{
    private const KEY_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const ENV_CLASS_NAMES = ['env', '\env', 'illuminate\support\env', '\illuminate\support\env'];

    private const ENV_CLASS_METHODS = ['get', 'getorfail'];

    private const IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * Token ids before `env` that make it something other than a call to
     * the global helper.
     */
    private const NOT_A_HELPER_CALL_AFTER = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    /**
     * @return list<string> unique key names in source order
     */
    public function keyNames(string $phpSource): array
    {
        $tokens = $this->significantTokens($phpSource);
        $keys = [];

        foreach (array_keys($tokens) as $position) {
            $key = $this->literalKey($tokens, $position);

            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * The key when the call at `$position` is an environment read whose
     * first argument is a single string literal holding a key name.
     *
     * @param  list<array{int, string}|string>  $tokens
     */
    private function literalKey(array $tokens, int $position): ?string
    {
        $token = $tokens[$position];

        if (! is_array($token)) {
            return null;
        }

        $name = strtolower($token[1]);
        $isHelperName = ($token[0] === T_STRING && $name === 'env') || ($token[0] === T_NAME_FULLY_QUALIFIED && $name === '\env');
        $openingPosition = null;

        if ($isHelperName && ($tokens[$position + 1] ?? null) === '(' && ! $this->follows($tokens, $position, self::NOT_A_HELPER_CALL_AFTER)) {
            $openingPosition = $position + 1;
        } elseif (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && in_array($name, self::ENV_CLASS_NAMES, true)) {
            $openingPosition = $this->staticMethodOpening($tokens, $position);
        }

        if ($openingPosition === null || ($tokens[$openingPosition] ?? null) !== '(') {
            return null;
        }

        $literal = $tokens[$openingPosition + 1] ?? null;
        $after = $tokens[$openingPosition + 2] ?? null;

        if (! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING || ($after !== ',' && $after !== ')')) {
            return null;
        }

        return $this->keyName($literal);
    }

    /**
     * Position of `(` after `Env::get` / `Env::getOrFail`, or null.
     *
     * @param  list<array{int, string}|string>  $tokens
     */
    private function staticMethodOpening(array $tokens, int $position): ?int
    {
        $operator = $tokens[$position + 1] ?? null;
        $method = $tokens[$position + 2] ?? null;

        if (! is_array($operator) || $operator[0] !== T_DOUBLE_COLON || ! is_array($method) || $method[0] !== T_STRING) {
            return null;
        }

        return in_array(strtolower($method[1]), self::ENV_CLASS_METHODS, true) ? $position + 3 : null;
    }

    /**
     * @param  list<array{int, string}|string>  $tokens
     * @param  list<int>  $tokenIds
     */
    private function follows(array $tokens, int $position, array $tokenIds): bool
    {
        $previous = $tokens[$position - 1] ?? null;

        return is_array($previous) && in_array($previous[0], $tokenIds, true);
    }

    /**
     * The key of a string literal; null when it is not a plain key name
     * (an escape, an interpolation or other characters).
     *
     * @param  array{int, string}  $literal
     */
    private function keyName(array $literal): ?string
    {
        $key = substr($literal[1], 1, -1);

        return preg_match(self::KEY_NAME, $key) === 1 ? $key : null;
    }

    /**
     * Tokens without whitespace and comments, re-indexed.
     *
     * @return list<array{int, string}|string>
     */
    private function significantTokens(string $phpSource): array
    {
        $tokens = [];

        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token) && in_array($token[0], self::IGNORED_TOKENS, true)) {
                continue;
            }

            $tokens[] = is_array($token) ? [$token[0], $token[1]] : $token;
        }

        return $tokens;
    }
}
