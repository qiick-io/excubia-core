<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads the YAML subset that Yarn Berry (`yarn.lock`, `.yarnrc.yml`) and
 * pnpm (`pnpm-lock.yaml`) write, without a YAML dependency (#21).
 *
 * Supported: block mappings by space indentation, block sequences of
 * scalars, plain, single- and double-quoted keys and scalars, one-line flow
 * maps and sequences (`{integrity: sha512-…}`, `[darwin]`), `|` and `>`
 * block scalars, comment lines, multi-line plain or quoted scalars (folded
 * with a space) and `---` document markers (the last document is
 * returned: pnpm 10 writes its env lockfile as a first document).
 * Scalars stay strings, so `1.10` keeps its zero; only `true`, `false`,
 * `null` and `~` are converted.
 *
 * ponytail: anchors, aliases, tags, complex keys and mappings inside
 * sequence items are not supported; both tools generate none of them.
 * Upgrade path: `symfony/yaml`, which needs the maintainer's approval as a
 * new dependency. Unsupported syntax is a configuration error naming the file
 * and line, never a silent partial read.
 */
final class YamlSubsetParser
{
    /**
     * Lines without blanks and comments: indentation and trimmed text.
     *
     * @var list<array{indent: int, text: string, number: int}>
     */
    private array $lines = [];

    private int $position = 0;

    private string $fileName = '';

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidConfigurationException
     */
    public function parse(string $contents, string $fileName): array
    {
        $this->fileName = $fileName;
        $documents = [[]];

        foreach (preg_split('/\R/', $contents) ?: [] as $offset => $line) {
            $text = rtrim($line);
            $trimmed = ltrim($text, ' ');

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($text === '---' || $text === '...') {
                $documents[] = [];

                continue;
            }

            if (str_starts_with($trimmed, "\t")) {
                throw $this->error($offset + 1, 'indents with a tab');
            }

            $documents[array_key_last($documents)][] = ['indent' => strlen($text) - strlen($trimmed), 'text' => $trimmed, 'number' => $offset + 1];
        }

        $documents = array_values(array_filter($documents, static fn (array $lines): bool => $lines !== []));
        $this->lines = $documents === [] ? [] : $documents[array_key_last($documents)];
        $this->position = 0;

        if ($this->lines === []) {
            return [];
        }

        try {
            $value = $this->block($this->lines[0]['indent']);

            if ($this->hasLinesLeft()) {
                throw $this->error($this->lines[$this->position]['number'], 'has an unexpected indentation');
            }
        } finally {
            // The lines are only needed while parsing: the parser keeps no file content.
            $this->lines = [];
        }

        if (array_is_list($value) && $value !== []) {
            throw $this->error(1, 'must be a mapping');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A mapping or a sequence whose lines all sit at `$indent`.
     *
     * @return array<int|string, mixed>
     */
    private function block(int $indent): array
    {
        $line = $this->lines[$this->position];

        if ($line['text'] === '-' || str_starts_with($line['text'], '- ')) {
            return $this->sequence($indent);
        }

        return $this->mapping($indent);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(int $indent): array
    {
        $mapping = [];

        while ($this->position < count($this->lines) && $this->lines[$this->position]['indent'] === $indent) {
            $line = $this->lines[$this->position];

            if (str_starts_with($line['text'], '- ')) {
                throw $this->error($line['number'], 'mixes a sequence into a mapping');
            }

            [$key, $rest] = $this->keyAndRest($line['text'], $line['number']);
            $this->position++;
            $mapping[$key] = $this->valueAfterKey($rest, $indent, $line['number']);
        }

        return $mapping;
    }

    /**
     * `$isUnderKey` is a sequence written at its key's own indentation
     * (`key:` then `- item`): it ends at the next key instead of failing.
     *
     * @return list<mixed>
     */
    private function sequence(int $indent, bool $isUnderKey = false): array
    {
        $items = [];

        while ($this->position < count($this->lines) && $this->lines[$this->position]['indent'] === $indent) {
            $line = $this->lines[$this->position];

            if ($line['text'] !== '-' && ! str_starts_with($line['text'], '- ')) {
                if ($isUnderKey) {
                    break;
                }

                throw $this->error($line['number'], 'mixes a mapping into a sequence');
            }

            $rest = trim(substr($line['text'], 1));

            if ($rest === '' || $this->isMappingEntry($rest)) {
                throw $this->error($line['number'], 'has a mapping inside a sequence item');
            }

            $this->position++;
            $items[] = $this->valueAfterKey($rest, $indent, $line['number']);
        }

        return $items;
    }

    /**
     * The value of a key (or sequence item) whose own line ends with `$rest`.
     */
    private function valueAfterKey(string $rest, int $indent, int $lineNumber): mixed
    {
        $deeperLines = [];

        while ($this->position < count($this->lines) && $this->lines[$this->position]['indent'] > $indent) {
            $deeperLines[] = $this->lines[$this->position];
            $this->position++;
        }

        if ($rest === '') {
            if ($deeperLines !== []) {
                $this->position -= count($deeperLines);

                return $this->block($deeperLines[0]['indent']);
            }

            if ($this->position < count($this->lines) && $this->lines[$this->position]['indent'] === $indent && str_starts_with($this->lines[$this->position]['text'], '- ')) {
                return $this->sequence($indent, true);
            }

            return null;
        }

        if (preg_match('/^[|>][+-]?$/', $rest) === 1) {
            $separator = $rest[0] === '|' ? "\n" : ' ';

            return implode($separator, array_column($deeperLines, 'text'));
        }

        foreach ($deeperLines as $deeperLine) {
            $rest .= ' '.$deeperLine['text'];
        }

        return $this->inlineValue($rest, $lineNumber);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function keyAndRest(string $text, int $lineNumber): array
    {
        if ($text[0] === '"' || $text[0] === "'") {
            $offset = 0;
            $key = $this->quoted($text, $offset, $lineNumber);
            $rest = ltrim(substr($text, $offset));

            if (! str_starts_with($rest, ':')) {
                throw $this->error($lineNumber, 'has a quoted key without a colon');
            }

            return [$key, trim(substr($rest, 1))];
        }

        $separator = strpos($text, ': ');

        if ($separator !== false) {
            return [rtrim(substr($text, 0, $separator)), trim(substr($text, $separator + 2))];
        }

        if (str_ends_with($text, ':')) {
            return [rtrim(substr($text, 0, -1)), ''];
        }

        throw $this->error($lineNumber, 'has a line that is not a key');
    }

    private function isMappingEntry(string $text): bool
    {
        if ($text[0] === '"' || $text[0] === "'" || $text[0] === '{' || $text[0] === '[') {
            return false;
        }

        return str_contains($text, ': ') || str_ends_with($text, ':');
    }

    private function inlineValue(string $text, int $lineNumber): mixed
    {
        $offset = 0;
        $value = $this->flowValue($text, $offset, $lineNumber, '');
        $remainder = trim(substr($text, $offset));

        if ($remainder !== '' && ! str_starts_with($remainder, '#')) {
            throw $this->error($lineNumber, 'has text after a value');
        }

        return $value;
    }

    /**
     * One value starting at `$offset`, which moves past it. `$terminators`
     * end a plain scalar inside a flow collection (`,}` or `,]`).
     */
    private function flowValue(string $text, int &$offset, int $lineNumber, string $terminators): mixed
    {
        while ($offset < strlen($text) && $text[$offset] === ' ') {
            $offset++;
        }

        $first = $text[$offset] ?? '';

        if ($first === '"' || $first === "'") {
            return $this->quoted($text, $offset, $lineNumber);
        }

        if ($first === '{') {
            return $this->flowCollection($text, $offset, $lineNumber, '}');
        }

        if ($first === '[') {
            return $this->flowCollection($text, $offset, $lineNumber, ']');
        }

        $start = $offset;

        while ($offset < strlen($text)) {
            $character = $text[$offset];

            if ($terminators !== '' && str_contains($terminators, $character)) {
                break;
            }

            if ($character === '#' && $offset > $start && $text[$offset - 1] === ' ') {
                break;
            }

            $offset++;
        }

        return $this->scalar(trim(substr($text, $start, $offset - $start)));
    }

    /**
     * A one-line `{key: value, …}` or `[value, …]`.
     *
     * @return array<int|string, mixed>
     */
    private function flowCollection(string $text, int &$offset, int $lineNumber, string $closing): array
    {
        $offset++;
        $collection = [];
        $isMapping = $closing === '}';

        while (true) {
            while ($offset < strlen($text) && $text[$offset] === ' ') {
                $offset++;
            }

            if ($offset >= strlen($text)) {
                throw $this->error($lineNumber, 'has an unclosed flow collection');
            }

            if ($text[$offset] === $closing) {
                $offset++;

                return $collection;
            }

            if ($isMapping) {
                $key = $this->flowKey($text, $offset, $lineNumber);
                $collection[$key] = $this->flowValue($text, $offset, $lineNumber, ',}');
            } else {
                $collection[] = $this->flowValue($text, $offset, $lineNumber, ',]');
            }

            while ($offset < strlen($text) && $text[$offset] === ' ') {
                $offset++;
            }

            if (($text[$offset] ?? '') === ',') {
                $offset++;
            }
        }
    }

    private function flowKey(string $text, int &$offset, int $lineNumber): string
    {
        if ($text[$offset] === '"' || $text[$offset] === "'") {
            $key = $this->quoted($text, $offset, $lineNumber);
        } else {
            $start = $offset;

            while ($offset < strlen($text) && ! ($text[$offset] === ':' && in_array($text[$offset + 1] ?? ' ', [' ', ',', '}'], true))) {
                $offset++;
            }

            $key = trim(substr($text, $start, $offset - $start));
        }

        while ($offset < strlen($text) && $text[$offset] === ' ') {
            $offset++;
        }

        if (($text[$offset] ?? '') !== ':') {
            throw $this->error($lineNumber, 'has a flow mapping key without a colon');
        }

        $offset++;

        return $key;
    }

    /**
     * A single- or double-quoted string starting at `$offset`; `$offset`
     * moves past the closing quote.
     */
    private function quoted(string $text, int &$offset, int $lineNumber): string
    {
        $quote = $text[$offset];
        $offset++;
        $value = '';

        while ($offset < strlen($text)) {
            $character = $text[$offset];

            if ($quote === "'" && $character === "'") {
                if (($text[$offset + 1] ?? '') === "'") {
                    $value .= "'";
                    $offset += 2;

                    continue;
                }

                $offset++;

                return $value;
            }

            if ($quote === '"' && $character === '\\') {
                $value .= $this->escape($text, $offset, $lineNumber);

                continue;
            }

            if ($quote === '"' && $character === '"') {
                $offset++;

                return $value;
            }

            $value .= $character;
            $offset++;
        }

        throw $this->error($lineNumber, 'has an unclosed quoted string');
    }

    /**
     * The character a `\` escape in a double-quoted string stands for.
     */
    private function escape(string $text, int &$offset, int $lineNumber): string
    {
        $code = $text[$offset + 1] ?? '';
        $offset += 2;
        $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", ' ' => ' '];

        if (isset($simple[$code])) {
            return $simple[$code];
        }

        if ($code === 'u' && preg_match('/^[0-9a-fA-F]{4}$/', substr($text, $offset, 4)) === 1) {
            $character = (string) mb_chr((int) hexdec(substr($text, $offset, 4)), 'UTF-8');
            $offset += 4;

            return $character;
        }

        throw $this->error($lineNumber, 'has an unknown escape sequence');
    }

    private function scalar(string $text): string|bool|null
    {
        return match ($text) {
            'true' => true,
            'false' => false,
            'null', '~', '' => null,
            default => $text,
        };
    }

    /**
     * A method, not an inline comparison, because the block readers move
     * `position` and static analysis would keep its old value.
     */
    private function hasLinesLeft(): bool
    {
        return $this->position < count($this->lines);
    }

    private function error(int $lineNumber, string $problem): InvalidConfigurationException
    {
        return new InvalidConfigurationException($this->fileName.' line '.$lineNumber.' '.$problem.'.');
    }
}
