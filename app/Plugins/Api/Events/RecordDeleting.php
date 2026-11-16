<?php

declare(strict_types=1);

namespace App\Plugins\Api\Events;

/**
 * Vor dem Löschen (auch in den Papierkorb). Ein Listener kann mit RecordRejected ablehnen.
 *
 * @api
 */
final readonly class RecordDeleting extends RecordEvent {}
