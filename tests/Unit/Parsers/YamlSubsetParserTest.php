<?php

declare(strict_types=1);

use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Parsers\YamlSubsetParser;

it('reads nested mappings, quoted keys and scalars that stay strings', function () {
    $yaml = <<<'YAML'
        # comment
        lockfileVersion: '9.0'
        version: 1.10
        isOn: true
        isOff: false
        nothing: ~
        "double \"quoted\" \u00e9": "tab\there"
        'single ''quoted''': 'it''s'

        packages:

          '@scope/name@1.0.0':
            resolution: {integrity: sha512-abc==, tarball: 'https://example.test/a.tgz'}
            engines: {node: ^14.13.1 || >=16.0.0}
            os: [darwin, "linux"]
            empty: {}
            none: []
            note: plain value # trailing comment
          /plain@2.0.0(peer@1.0.0):
            dev: true
        YAML;

    expect((new YamlSubsetParser)->parse($yaml, 'test.yaml'))->toBe([
        'lockfileVersion' => '9.0',
        'version' => '1.10',
        'isOn' => true,
        'isOff' => false,
        'nothing' => null,
        'double "quoted" é' => "tab\there",
        "single 'quoted'" => "it's",
        'packages' => [
            '@scope/name@1.0.0' => [
                'resolution' => ['integrity' => 'sha512-abc==', 'tarball' => 'https://example.test/a.tgz'],
                'engines' => ['node' => '^14.13.1 || >=16.0.0'],
                'os' => ['darwin', 'linux'],
                'empty' => [],
                'none' => [],
                'note' => 'plain value',
            ],
            '/plain@2.0.0(peer@1.0.0)' => ['dev' => true],
        ],
    ]);
});

it('reads block sequences, block scalars and folded multi-line values', function () {
    $yaml = <<<'YAML'
        list:
          - one
          - "two"
        sameIndent:
        - three
        literal: |
          line one
          line two
        folded: >-
          one
          two
        long: this message
          goes on
        quoted: "starts here
          and ends here"
        YAML;

    expect((new YamlSubsetParser)->parse($yaml, 'test.yaml'))->toBe([
        'list' => ['one', 'two'],
        'sameIndent' => ['three'],
        'literal' => "line one\nline two",
        'folded' => 'one two',
        'long' => 'this message goes on',
        'quoted' => 'starts here and ends here',
    ]);
});

it('returns the last document and an empty mapping for an empty file', function () {
    $parser = new YamlSubsetParser;

    expect($parser->parse("---\nfirst: 1\n---\nsecond: 2\n", 'test.yaml'))->toBe(['second' => '2'])
        ->and($parser->parse("# only a comment\n", 'test.yaml'))->toBe([]);
});

it('names the file and line of syntax it does not read', function (string $yaml, string $message) {
    expect(fn () => (new YamlSubsetParser)->parse($yaml, 'test.yaml'))
        ->toThrow(InvalidConfigurationException::class, 'test.yaml line '.$message);
})->with([
    'tab indent' => ["a:\n\tb: 1\n", '2 indents with a tab.'],
    'not a key' => ["just text\n", '1 has a line that is not a key.'],
    'unclosed quote' => ["a: \"open\n", '1 has an unclosed quoted string.'],
    'unclosed flow' => ["a: {b: c\n", '1 has an unclosed flow collection.'],
    'flow key without colon' => ["a: {b}\n", '1 has a flow mapping key without a colon.'],
    'text after value' => ["a: 'b' c\n", '1 has text after a value.'],
    'unknown escape' => ["a: \"\\q\"\n", '1 has an unknown escape sequence.'],
    'quoted key without colon' => ["'a' b\n", '1 has a quoted key without a colon.'],
    'mapping in sequence' => ["a:\n  - b: c\n", '2 has a mapping inside a sequence item.'],
    'sequence in mapping' => ["a:\n  b: 1\n  - c\n", '3 mixes a sequence into a mapping.'],
    'mapping after sequence' => ["a:\n  - b\n  c: 1\n", '3 mixes a mapping into a sequence.'],
    'bad indentation' => ["  a: 1\nb: 2\n", '2 has an unexpected indentation.'],
    'top-level sequence' => ["- a\n", '1 must be a mapping.'],
]);
