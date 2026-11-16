<?php

declare(strict_types=1);

namespace App\Tasks;

use RuntimeException;

/**
 * Die Aufgabe kann gerade nicht laufen (z. B. läuft dieselbe Arbeit schon); kein Fehler. Die Meldung erscheint im
 * Laufprotokoll mit dem Status „übersprungen“.
 *
 * @api
 */
final class TaskSkipped extends RuntimeException {}
