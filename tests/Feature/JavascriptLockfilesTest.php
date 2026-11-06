<?php

declare(strict_types=1);

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The Yarn and pnpm fixtures (#21): each locks the vulnerable public
 * `lodash` 4.17.20 and the private `@acme/ui` (a file or workspace
 * dependency), so the recorded OSV answer for one query fits every one.
 */
dataset('javascript lockfile fixtures', [
    'Yarn v1' => ['yarn-v1', 'yarn.lock'],
    'Yarn Berry' => ['yarn-berry', 'yarn.lock'],
    'pnpm 6' => ['pnpm-6', 'pnpm-lock.yaml'],
    'pnpm 9' => ['pnpm-9', 'pnpm-lock.yaml'],
]);

function javascriptLockfileFixture(string $name): string
{
    return dirname(__DIR__).'/Fixtures/Projects/'.$name;
}

function recordedOsvClient(): FakeHttpClient
{
    return new FakeHttpClient([
        'https://api.osv.dev/v1/querybatch' => json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/Responses/osv-querybatch.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ),
    ]);
}

/**
 * `excubia check --only=npm-advisories` on `$project`.
 *
 * @return array{exitCode: int, display: string, client: FakeHttpClient}
 */
function npmAdvisoriesCheckRun(string $project, string $format): array
{
    $client = recordedOsvClient();
    $tester = new CommandTester(new CheckCommand($client));
    $exitCode = $tester->execute([
        'path' => $project,
        '--only' => 'npm-advisories',
        '--format' => $format,
        '--no-banner' => true,
        '--no-cache' => true,
    ]);

    return ['exitCode' => $exitCode, 'display' => $tester->getDisplay(), 'client' => $client];
}

/**
 * @return array<string, LockedPackageData>
 */
function javascriptPackagesByName(string $project): array
{
    $snapshot = (new ProjectSnapshotService)->snapshot($project, ExcubiaConfigurationData::defaults());
    $byName = [];

    foreach ($snapshot->npmPackages as $package) {
        $byName[$package->name] = $package;
    }

    return $byName;
}

it('reads the public and the private package of each lockfile', function (string $fixture, string $lockfile) {
    $snapshot = (new ProjectSnapshotService)->snapshot(javascriptLockfileFixture($fixture), ExcubiaConfigurationData::defaults());
    $byName = javascriptPackagesByName(javascriptLockfileFixture($fixture));

    expect($snapshot->javascriptLockfile)->toBe($lockfile)
        ->and($snapshot->hasNpmLockfile)->toBeTrue()
        ->and(array_keys($byName))->toEqualCanonicalizing(['lodash', '@acme/ui'])
        ->and($byName['lodash']->version)->toBe('4.17.20')
        ->and($byName['lodash']->isPrivate)->toBeFalse()
        ->and($byName['lodash']->isDirectDependency)->toBeTrue()
        ->and($byName['lodash']->licenses)->toBe([])
        ->and($byName['@acme/ui']->isPrivate)->toBeTrue()
        ->and($byName['@acme/ui']->isDirectDependency)->toBeTrue();
})->with('javascript lockfile fixtures');

it('reports the vulnerable package and never sends the private one', function (string $fixture, string $lockfile) {
    $run = npmAdvisoriesCheckRun(javascriptLockfileFixture($fixture), 'json');
    $report = json_decode($run['display'], true, 512, JSON_THROW_ON_ERROR);
    $findings = [];

    foreach ($report['checks'][0]['findings'] as $finding) {
        $findings[$finding['subject']] = $finding;
    }

    $validator = new Validator;
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    $document = json_decode($run['display'], false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, $schema, Constraint::CHECK_MODE_STRICT);

    expect($run['client']->requests)->toBe([
        'POST https://api.osv.dev/v1/querybatch {"queries":[{"package":{"name":"lodash","ecosystem":"npm"},"version":"4.17.20"}]}',
    ])
        ->and($validator->getErrors())->toBe([])
        ->and($findings['lodash']['severity'])->toBe('medium')
        ->and($report['javascriptLockfile'])->toBe($lockfile)
        ->and($findings['lodash']['status'])->toBe('fail')
        ->and($findings['lodash']['advisoryId'])->toBe('GHSA-29mw-wpgm-hmr9')
        ->and($findings['lodash']['message'])->toContain($lockfile)
        ->and($findings['lodash']['details'])->toBe(['package' => 'lodash', 'lockedVersion' => '4.17.20', 'fixedVersions' => [], 'lockfile' => $lockfile])
        ->and($findings['private packages']['message'])->toBe('1 private package not sent to OSV.dev: @acme/ui.');
})->with('javascript lockfile fixtures');

it('points the SARIF location at the lockfile that was read', function (string $fixture, string $lockfile) {
    $sarif = json_decode(npmAdvisoriesCheckRun(javascriptLockfileFixture($fixture), 'sarif')['display'], true, 512, JSON_THROW_ON_ERROR);
    $result = $sarif['runs'][0]['results'][0];

    expect($sarif['runs'][0]['results'])->toHaveCount(1)
        ->and($result['ruleId'])->toBe('npm-advisories')
        ->and($result['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toBe($lockfile);
})->with('javascript lockfile fixtures');

it('summarizes the packages of a lockfile without license data in one row', function (string $fixture, string $lockfile) {
    $client = new FakeHttpClient([]);
    $tester = new CommandTester(new CheckCommand($client));
    $tester->execute([
        'path' => javascriptLockfileFixture($fixture),
        '--only' => 'dependency-licenses',
        '--format' => 'json',
        '--no-banner' => true,
    ]);
    $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($client->calls)->toBe(0)
        ->and($report['checks'][0]['findings'])->toBe([[
            'checkId' => 'dependency-licenses',
            'severity' => 'info',
            'status' => 'skipped',
            'subject' => $lockfile,
            'message' => '2 packages from '.$lockfile.' have no license data: the lockfile does not record licenses.',
            'fixHint' => '',
            'evidenceUrl' => null,
            'fingerprint' => hash('sha256', 'dependency-licenses'."\0".$lockfile."\0"),
            'skipReason' => 'not-applicable',
        ]])
        ->and($report['licenses']['byEcosystem']['npm'])->toBe(['packageCount' => 0, 'byLicense' => []]);
})->with('javascript lockfile fixtures');

describe('lockfile selection', function () {
    $composer = [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ];
    $lockfiles = [
        'package-lock.json' => '{"lockfileVersion": 3, "packages": {"": {}, "node_modules/from-npm": {"version": "1.0.0", "resolved": "https://registry.npmjs.org/from-npm/-/from-npm-1.0.0.tgz"}}}',
        'yarn.lock' => "# yarn lockfile v1\n\nfrom-yarn@^1.0.0:\n  version \"1.0.0\"\n  resolved \"https://registry.yarnpkg.com/from-yarn/-/from-yarn-1.0.0.tgz\"\n",
        'pnpm-lock.yaml' => "lockfileVersion: '9.0'\n\npackages:\n\n  from-pnpm@1.0.0:\n    resolution: {integrity: sha512-abc}\n",
    ];

    $selected = static function (array $files) use ($composer): array {
        $project = temporaryProject([...$composer, ...$files]);
        $snapshot = (new ProjectSnapshotService)->snapshot($project, ExcubiaConfigurationData::defaults());

        return [$snapshot->javascriptLockfile, array_map(static fn (LockedPackageData $package): string => $package->name, $snapshot->npmPackages)];
    };

    it('prefers pnpm, then Yarn, then npm without a packageManager', function () use ($selected, $lockfiles) {
        expect($selected($lockfiles))->toBe(['pnpm-lock.yaml', ['from-pnpm']])
            ->and($selected(['yarn.lock' => $lockfiles['yarn.lock'], 'package-lock.json' => $lockfiles['package-lock.json']]))->toBe(['yarn.lock', ['from-yarn']])
            ->and($selected(['package-lock.json' => $lockfiles['package-lock.json']]))->toBe(['package-lock.json', ['from-npm']])
            ->and($selected([]))->toBe([null, []]);
    });

    it('uses the lockfile of the packageManager in package.json first', function () use ($selected, $lockfiles) {
        expect($selected([...$lockfiles, 'package.json' => '{"packageManager": "npm@10.9.0"}']))->toBe(['package-lock.json', ['from-npm']])
            ->and($selected([...$lockfiles, 'package.json' => '{"packageManager": "yarn@4.5.1+sha512.abc"}']))->toBe(['yarn.lock', ['from-yarn']])
            ->and($selected([...$lockfiles, 'package.json' => '{"packageManager": "pnpm@9.12.3"}']))->toBe(['pnpm-lock.yaml', ['from-pnpm']]);
    });

    it('falls back to the fixed order when the packageManager lockfile is missing or unknown', function () use ($selected, $lockfiles) {
        expect($selected(['yarn.lock' => $lockfiles['yarn.lock'], 'package-lock.json' => $lockfiles['package-lock.json'], 'package.json' => '{"packageManager": "pnpm@9.12.3"}']))->toBe(['yarn.lock', ['from-yarn']])
            ->and($selected([...$lockfiles, 'package.json' => '{"packageManager": "bun@1.1.0"}']))->toBe(['pnpm-lock.yaml', ['from-pnpm']])
            ->and($selected([...$lockfiles, 'package.json' => '{"packageManager": 7}']))->toBe(['pnpm-lock.yaml', ['from-pnpm']]);
    });

    it('skips npm-advisories and names the lockfiles when there is none', function () use ($composer) {
        $run = npmAdvisoriesCheckRun(temporaryProject($composer), 'json');
        $report = json_decode($run['display'], true, 512, JSON_THROW_ON_ERROR);

        expect($report)->not->toHaveKey('javascriptLockfile')
            ->and($report['checks'][0]['status'])->toBe('skipped')
            ->and($report['checks'][0]['findings'][0]['message'])->toBe('No JavaScript lockfile is present (pnpm-lock.yaml, yarn.lock or package-lock.json).')
            ->and($run['client']->calls)->toBe(0);
    });
});

describe('private registries', function () {
    $privacyProject = static function (array $files): array {
        $isPrivate = [];

        foreach (javascriptPackagesByName(temporaryProject(['composer.json' => '{}', 'composer.lock' => '{"packages": [], "packages-dev": []}', ...$files])) as $name => $package) {
            $isPrivate[$name] = $package->isPrivate;
        }

        ksort($isPrivate);

        return $isPrivate;
    };
    $pnpmLock = "lockfileVersion: '9.0'\n\npackages:\n\n  '@corp/internal@1.0.0':\n    resolution: {integrity: sha512-abc}\n\n  left-pad@1.3.0:\n    resolution: {integrity: sha512-def}\n\n"
        ."  from-tarball@https://npm.corp.test/from-tarball-1.0.0.tgz:\n    resolution: {tarball: https://npm.corp.test/from-tarball-1.0.0.tgz}\n    version: 1.0.0\n\n"
        ."  on-public-tarball@1.0.0:\n    resolution: {tarball: https://registry.npmjs.org/on-public-tarball/-/on-public-tarball-1.0.0.tgz}\n\n"
        ."  from-git@https://codeload.github.com/acme/from-git/tar.gz/abc:\n    resolution: {tarball: https://codeload.github.com/acme/from-git/tar.gz/abc}\n    version: 2.0.0\n";
    $berryLock = "__metadata:\n  version: 8\n\n\"@corp/internal@npm:^1.0.0\":\n  version: 1.0.0\n  resolution: \"@corp/internal@npm:1.0.0\"\n\n"
        ."\"left-pad@npm:^1.3.0\":\n  version: 1.3.0\n  resolution: \"left-pad@npm:1.3.0\"\n\n"
        ."\"from-git@https://github.com/acme/from-git.git#commit=abc\":\n  version: 2.0.0\n  resolution: \"from-git@https://github.com/acme/from-git.git#commit=abc\"\n\n"
        ."\"resolve@patch:resolve@npm%3A1.22.1#~builtin<compat/resolve>\":\n  version: 1.22.1\n  resolution: \"resolve@patch:resolve@npm%3A1.22.1#~builtin<compat/resolve>::version=1.22.1&hash=c3c19d\"\n";

    it('marks pnpm tarballs, git and .npmrc scoped registries private', function () use ($privacyProject, $pnpmLock) {
        expect($privacyProject(['pnpm-lock.yaml' => $pnpmLock]))->toBe([
            '@corp/internal' => false,
            'from-git' => true,
            'from-tarball' => true,
            'left-pad' => false,
            'on-public-tarball' => false,
        ])
            ->and($privacyProject(['pnpm-lock.yaml' => $pnpmLock, '.npmrc' => "; corp\n@corp:registry=https://npm.corp.test/\nalways-auth=true\n"]))->toMatchArray([
                '@corp/internal' => true,
                'left-pad' => false,
            ])
            ->and($privacyProject(['pnpm-lock.yaml' => $pnpmLock, '.npmrc' => "registry = https://registry.npmjs.org/\n@other:registry=https://registry.yarnpkg.com/\n"]))->toMatchArray([
                '@corp/internal' => false,
                'left-pad' => false,
            ])
            ->and(array_unique($privacyProject(['pnpm-lock.yaml' => $pnpmLock, '.npmrc' => "registry=https://npm.corp.test/\n"])))->toBe(['@corp/internal' => true]);
    });

    it('marks Berry non-npm protocols and .yarnrc.yml scoped registries private', function () use ($privacyProject, $berryLock) {
        expect($privacyProject(['yarn.lock' => $berryLock]))->toBe([
            '@corp/internal' => false,
            'from-git' => true,
            'left-pad' => false,
            'resolve' => true,
        ])
            ->and($privacyProject(['yarn.lock' => $berryLock, '.yarnrc.yml' => "nodeLinker: node-modules\n\nnpmScopes:\n  corp:\n    npmRegistryServer: \"https://npm.corp.test\"\n    npmAlwaysAuth: true\n"]))->toMatchArray([
                '@corp/internal' => true,
                'left-pad' => false,
            ])
            ->and(array_unique($privacyProject(['yarn.lock' => $berryLock, '.yarnrc.yml' => "npmRegistryServer: 'https://npm.corp.test'\n"])))->toBe(['@corp/internal' => true]);
    });

    it('marks Yarn v1 entries private unless the public registry served them', function () use ($privacyProject) {
        $yarnLock = "# yarn lockfile v1\n\n\"@corp/internal@^1.0.0\":\n  version \"1.0.0\"\n  resolved \"https://npm.corp.test/@corp/internal/-/internal-1.0.0.tgz\"\n\n"
            ."left-pad@^1.3.0:\n  version \"1.3.0\"\n  resolved \"https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz\"\n\n"
            ."aliased@npm:real-name@^2.0.0:\n  version \"2.0.0\"\n  resolved \"https://registry.yarnpkg.com/real-name/-/real-name-2.0.0.tgz\"\n\n"
            ."from-git@https://github.com/acme/from-git.git#abc:\n  version \"2.0.0\"\n  resolved \"https://github.com/acme/from-git.git#abc\"\n\n"
            ."spoofed@^1.0.0:\n  version \"1.0.0\"\n  resolved \"https://registry.npmjs.org.corp.test/spoofed/-/spoofed-1.0.0.tgz\"\n";

        expect($privacyProject(['yarn.lock' => $yarnLock]))->toBe([
            '@corp/internal' => true,
            'from-git' => true,
            'left-pad' => false,
            'real-name' => false,
            'spoofed' => true,
        ]);
    });
});

it('treats a yarn.lock mirror tarball as public in package-lock.json too', function () {
    $project = temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'package-lock.json' => '{"lockfileVersion": 3, "packages": {"node_modules/a": {"version": "1.0.0", "resolved": "https://registry.yarnpkg.com/a/-/a-1.0.0.tgz"}, "node_modules/b": {"version": "1.0.0", "resolved": "http://registry.npmjs.org/b/-/b-1.0.0.tgz"}}}',
    ]);

    expect(javascriptPackagesByName($project)['a']->isPrivate)->toBeFalse()
        ->and(javascriptPackagesByName($project)['b']->isPrivate)->toBeTrue();
});

it('marks pnpm 6 dev packages and keeps one row per version', function () {
    $project = temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'pnpm-lock.yaml' => "lockfileVersion: '6.1'\n\npackages:\n\n  /@types/node@20.0.0:\n    resolution: {integrity: sha512-a}\n    dev: true\n\n"
            ."  /react-dom@18.2.0(react@18.2.0):\n    resolution: {integrity: sha512-b}\n    peerDependencies:\n      react: ^18.2.0\n    dev: false\n\n"
            ."  /react-dom@18.2.0(react@18.3.0):\n    resolution: {integrity: sha512-b}\n    dev: false\n\n"
            ."  github.com/acme/tool/abc123:\n    resolution: {tarball: https://codeload.github.com/acme/tool/tar.gz/abc123}\n    name: tool\n    version: 0.1.0\n    dev: false\n",
    ]);
    $byName = javascriptPackagesByName($project);

    expect(array_keys($byName))->toBe(['@types/node', 'react-dom', 'tool'])
        ->and((new ProjectSnapshotService)->snapshot($project, ExcubiaConfigurationData::defaults())->npmPackages)->toHaveCount(3)
        ->and($byName['@types/node']->isDevelopmentDependency)->toBeTrue()
        ->and($byName['react-dom']->isDevelopmentDependency)->toBeFalse()
        ->and($byName['react-dom']->isPrivate)->toBeFalse()
        ->and($byName['tool']->isPrivate)->toBeTrue();
});

it('rejects lockfiles it cannot read with a configuration error', function (array $files, string $message) {
    $project = temporaryProject(['composer.json' => '{}', 'composer.lock' => '{"packages": [], "packages-dev": []}', ...$files]);

    expect(fn () => (new ProjectSnapshotService)->snapshot($project, ExcubiaConfigurationData::defaults()))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'pnpm 5' => [['pnpm-lock.yaml' => "lockfileVersion: 5.4\n"], 'pnpm-lock.yaml lockfileVersion must be 6 or 9.'],
    'pnpm packages list' => [['pnpm-lock.yaml' => "lockfileVersion: '9.0'\npackages: [a]\n"], 'pnpm-lock.yaml packages must be a mapping.'],
    'pnpm package scalar' => [['pnpm-lock.yaml' => "lockfileVersion: '9.0'\npackages:\n  a@1.0.0: yes\n"], 'pnpm-lock.yaml package entries must be mappings.'],
    'Berry package scalar' => [['yarn.lock' => "__metadata:\n  version: 8\na@npm:1.0.0: yes\n"], 'yarn.lock package entries must be mappings.'],
    'Yarn v1 garbage' => [['yarn.lock' => "# yarn lockfile v1\nnot a lockfile\n"], 'yarn.lock is not a Yarn lockfile.'],
    'broken YAML' => [['pnpm-lock.yaml' => "lockfileVersion: '9.0\n"], 'pnpm-lock.yaml line 1 has an unclosed quoted string.'],
]);
