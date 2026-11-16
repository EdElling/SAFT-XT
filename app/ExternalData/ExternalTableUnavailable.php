<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\Exceptions\BusinessRuleException;

/** Eine angebundene Tabelle ist nicht nutzbar: Verbindung inaktiv oder entfernt. */
final class ExternalTableUnavailable extends BusinessRuleException
{
    public static function for(string $table): self
    {
        return new self(__('table_connector.unavailable', ['table' => $table]), 'external.unavailable');
    }
}
