<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * What `dependency-licenses` makes of a license id or expression.
 *
 * Ordered from harmless to worst: `OR` keeps the best option, `AND` the
 * worst (`SpdxExpressionService`). Internal, not part of the report.
 */
enum LicenseVerdictEnum: int
{
    case Permitted = 0;
    case Unknown = 1;
    case StrongCopyleft = 2;
    case Denied = 3;
}
