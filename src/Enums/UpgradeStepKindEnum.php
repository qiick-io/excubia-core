<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * What one upgrade plan step asks for, so a consumer such as excubia-app can
 * draw the step without parsing its message (#276). One case per kind of
 * step `UpgradePlanService` builds, listed in plan order. The backing values
 * are part of the JSON report contract (`docs/report-schema.json`): new kinds
 * are appended, existing values never change.
 */
enum UpgradeStepKindEnum: string
{
    /** One vulnerable Composer package (`composer-advisories`). */
    case ComposerAdvisory = 'composer-advisory';

    /** One vulnerable npm package (`npm-advisories`). */
    case NpmAdvisory = 'npm-advisory';

    /** The PHP version is at or near end of life (`php-eol`). */
    case PhpEol = 'php-eol';

    /** Packages without a release that supports the next Laravel major (`laravel-next-blocker`). */
    case NextMajorBlocked = 'next-major-blocked';

    /** The Laravel major is at or near end of life (`laravel-eol`). */
    case LaravelEol = 'laravel-eol';

    /** One abandoned Composer package (`composer-abandoned`). */
    case ComposerAbandoned = 'composer-abandoned';

    /** Every stale or archived Composer package that is not abandoned (`composer-stale`). */
    case ComposerStale = 'composer-stale';

    /** Composer direct dependencies behind on a patch, without an advisory (`composer-outdated`). */
    case ComposerPatch = 'composer-patch';

    /** npm direct dependencies behind on a patch, without an advisory (`npm-outdated`). */
    case NpmPatch = 'npm-patch';

    /** Denied, then strong copyleft, licenses to review (`dependency-licenses`). */
    case LicenseReview = 'license-review';
}
