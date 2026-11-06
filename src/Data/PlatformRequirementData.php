<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * One PHP or extension requirement found in `composer.lock`: the `require`
 * of a locked package, or the root `platform` / `platform-dev` keys (then
 * `requiredBy` is `composer.json`). `name` is lowercase, `php` or `ext-*`.
 */
final readonly class PlatformRequirementData
{
    public const ROOT_PACKAGE = 'composer.json';

    public function __construct(
        public string $name,
        public string $constraint,
        public string $requiredBy,
        public bool $isDevelopmentDependency,
    ) {}
}
