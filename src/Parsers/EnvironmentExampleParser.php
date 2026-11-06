<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

/**
 * Reads the key names of a `.env.example` file and drops every value.
 *
 * Accepted lines: `KEY=value`, `export KEY=value`, and commented-out
 * assignments such as `# DB_HOST=127.0.0.1` (Laravel's own `.env.example`
 * documents optional keys that way). Blank lines and other comments are
 * skipped. A quoted value that spans several lines is skipped to its
 * closing quote, so its inner lines are never read as keys.
 */
final class EnvironmentExampleParser
{
    private const ASSIGNMENT = '/^(#\s*)?(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/';

    /**
     * @return list<string> unique key names, sorted
     */
    public function keyNames(string $contents): array
    {
        $keys = [];
        $openQuote = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($openQuote !== null) {
                if ($this->closesQuote($line, $openQuote)) {
                    $openQuote = null;
                }

                continue;
            }

            if (preg_match(self::ASSIGNMENT, trim($line), $matches) !== 1) {
                continue;
            }

            $keys[$matches[2]] = true;

            if ($matches[1] === '') {
                $openQuote = $this->unclosedQuote(ltrim($matches[3]));
            }
        }

        $names = array_map('strval', array_keys($keys));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The quote character when the value opens a quote it does not close
     * on the same line; null otherwise.
     */
    private function unclosedQuote(string $value): ?string
    {
        $quote = $value[0] ?? '';

        if ($quote !== '"' && $quote !== "'") {
            return null;
        }

        return $this->closesQuote(substr($value, 1), $quote) ? null : $quote;
    }

    /**
     * True when the text holds the closing quote. Double quotes can be
     * escaped with a backslash; single quotes cannot.
     */
    private function closesQuote(string $text, string $quote): bool
    {
        if ($quote === "'") {
            return str_contains($text, "'");
        }

        return preg_match('/(?<!\\\\)(?:\\\\\\\\)*"/', $text) === 1;
    }
}
