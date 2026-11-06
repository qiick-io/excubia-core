<?php

declare(strict_types=1);

use Qiick\Excubia\Data\DependencyLicensesConfigurationData;
use Qiick\Excubia\Enums\LicenseVerdictEnum;
use Qiick\Excubia\Services\SpdxExpressionService;

function licenseVerdict(array $declaredLicenses, array $allowed = [], array $denied = []): LicenseVerdictEnum
{
    return (new SpdxExpressionService)->verdict($declaredLicenses, new DependencyLicensesConfigurationData($allowed, $denied));
}

it('classifies single ids', function (string $licenseId, LicenseVerdictEnum $verdict) {
    expect(licenseVerdict([$licenseId]))->toBe($verdict);
})->with([
    'permissive' => ['MIT', LicenseVerdictEnum::Permitted],
    'case-insensitive known id' => ['apache-2.0', LicenseVerdictEnum::Permitted],
    'weak copyleft' => ['LGPL-2.1-only', LicenseVerdictEnum::Permitted],
    'gpl' => ['GPL-3.0-only', LicenseVerdictEnum::StrongCopyleft],
    'agpl' => ['AGPL-3.0', LicenseVerdictEnum::StrongCopyleft],
    'sspl' => ['SSPL-1.0', LicenseVerdictEnum::StrongCopyleft],
    'eupl' => ['EUPL-1.2', LicenseVerdictEnum::StrongCopyleft],
    'plus suffix' => ['GPL-2.0+', LicenseVerdictEnum::StrongCopyleft],
    'or-later suffix' => ['LGPL-3.0-or-later', LicenseVerdictEnum::Permitted],
    'unknown id' => ['Acme-Custom-1.0', LicenseVerdictEnum::Unknown],
    'npm private marker' => ['UNLICENSED', LicenseVerdictEnum::Unknown],
    'composer proprietary' => ['proprietary', LicenseVerdictEnum::Unknown],
]);

it('treats a missing license and an unparseable expression as unknown', function (array $declaredLicenses) {
    expect(licenseVerdict($declaredLicenses))->toBe(LicenseVerdictEnum::Unknown);
})->with([
    'none declared' => [[]],
    'free text' => [['SEE LICENSE IN LICENSE.md']],
    'dangling operator' => [['MIT OR']],
    'unbalanced parenthesis' => [['(MIT OR Apache-2.0']],
    'operator first' => [['AND MIT']],
    'empty parentheses' => [['()']],
    'stray closing parenthesis' => [['MIT)']],
    'with without exception' => [['GPL-2.0-only WITH']],
]);

it('keeps the mildest option of OR and the worst part of AND', function (string $expression, LicenseVerdictEnum $verdict) {
    expect(licenseVerdict([$expression]))->toBe($verdict);
})->with([
    'or with a permissive option' => ['MIT OR GPL-3.0-only', LicenseVerdictEnum::Permitted],
    'or of two copylefts' => ['GPL-2.0-only OR AGPL-3.0-only', LicenseVerdictEnum::StrongCopyleft],
    'lower-case or in parentheses' => ['(GPL-3.0-only or MIT)', LicenseVerdictEnum::Permitted],
    'and with a copyleft part' => ['MIT AND GPL-3.0-only', LicenseVerdictEnum::StrongCopyleft],
    'and of permissive parts' => ['MIT AND BSD-3-Clause', LicenseVerdictEnum::Permitted],
    'and binds tighter than or' => ['MIT OR GPL-3.0-only AND Apache-2.0', LicenseVerdictEnum::Permitted],
    'parentheses group the or' => ['(MIT OR GPL-3.0-only) AND AGPL-3.0-only', LicenseVerdictEnum::StrongCopyleft],
    'nested parentheses' => ['((MIT))', LicenseVerdictEnum::Permitted],
    'with exception keeps the license' => ['GPL-2.0-only WITH Classpath-exception-2.0', LicenseVerdictEnum::StrongCopyleft],
    'unknown option loses to permissive' => ['Acme-1.0 OR ISC', LicenseVerdictEnum::Permitted],
    'unknown beats copyleft in or' => ['Acme-1.0 OR GPL-3.0-only', LicenseVerdictEnum::Unknown],
]);

it('reads several Composer entries as a choice', function () {
    expect(licenseVerdict(['GPL-3.0-only', 'MIT']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['GPL-3.0-only', 'SEE LICENSE IN x']))->toBe(LicenseVerdictEnum::Unknown);
});

it('applies denied before allowed and both before the built-in lists', function () {
    expect(licenseVerdict(['GPL-3.0-only'], allowed: ['GPL-*']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['MIT'], denied: ['mit']))->toBe(LicenseVerdictEnum::Denied)
        ->and(licenseVerdict(['GPL-3.0-only'], allowed: ['GPL-*'], denied: ['GPL-3.0']))->toBe(LicenseVerdictEnum::Denied)
        ->and(licenseVerdict(['Acme-Custom-1.0'], allowed: ['Acme-Custom-?.?']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['MIT OR SSPL-1.0'], denied: ['SSPL-*']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['MIT AND SSPL-1.0'], denied: ['SSPL-*']))->toBe(LicenseVerdictEnum::Denied);
});

it('matches the suffix spellings of an id', function () {
    expect(licenseVerdict(['GPL-2.0+'], allowed: ['GPL-2.0-or-later']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['GPL-2.0-or-later'], allowed: ['GPL-2.0']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['GPL-2.0-only'], allowed: ['GPL-2.0']))->toBe(LicenseVerdictEnum::Permitted)
        ->and(licenseVerdict(['GPL-3.0-only'], allowed: ['GPL-2.0']))->toBe(LicenseVerdictEnum::StrongCopyleft);
});

it('lists the ids an expression names', function () {
    $service = new SpdxExpressionService;

    expect($service->licenseIds('MIT'))->toBe(['MIT'])
        ->and($service->licenseIds('(MIT OR Apache-2.0) AND MIT'))->toBe(['MIT', 'Apache-2.0'])
        ->and($service->licenseIds('GPL-2.0-only WITH Classpath-exception-2.0'))->toBe(['GPL-2.0-only'])
        ->and($service->licenseIds('SEE LICENSE IN LICENSE.md'))->toBe(['SEE LICENSE IN LICENSE.md']);
});
