<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of change the audit trail records.
 *
 * Financial events beyond the lifecycle of a row — a transaction posted, a credit
 * settled, a balance rebuilt — are added here as those operations are built. Section 15
 * names several that do not exist yet.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
}
