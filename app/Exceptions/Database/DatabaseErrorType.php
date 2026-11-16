<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

enum DatabaseErrorType: string
{
    case UniqueViolation = 'db.unique_violation';
    case ForeignKeyViolation = 'db.foreign_key_violation';
    case NotNullViolation = 'db.not_null_violation';
    case CheckViolation = 'db.check_violation';
    case ValueOutOfRange = 'db.value_out_of_range';
    case Concurrency = 'db.concurrency';
    case ConnectionLost = 'db.connection_lost';
    case Unknown = 'db.error';

    public function status(): int
    {
        return match ($this) {
            self::UniqueViolation, self::ForeignKeyViolation => 409,
            self::NotNullViolation, self::CheckViolation, self::ValueOutOfRange => 422,
            self::Concurrency, self::ConnectionLost => 503,
            self::Unknown => 500,
        };
    }

    /** Erwartbare Konflikte: Nutzer kann sie selbst beheben. */
    public function isExpected(): bool
    {
        return $this->status() < 500;
    }
}
