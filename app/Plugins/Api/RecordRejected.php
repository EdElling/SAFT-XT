<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\Exceptions\BusinessRuleException;

/**
 * Ein Plugin lehnt eine Änderung ab (in einem Listener für RecordSaving oder RecordDeleting). Die Meldung ist für die
 * Anzeige bestimmt; SAFT zeigt sie wie jeden fachlichen Regelverstoss an und speichert nichts.
 *
 * @api
 */
final class RecordRejected extends BusinessRuleException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'record_rejected');
    }
}
