<?php

declare(strict_types=1);

namespace App\Plugins\Api\Events;

/**
 * Nach dem Löschen (auch in den Papierkorb).
 *
 * @api
 */
final readonly class RecordDeleted extends RecordEvent {}
