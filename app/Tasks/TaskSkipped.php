<?php

declare(strict_types=1);

namespace App\Tasks;

use RuntimeException;

/** Die Aufgabe kann gerade nicht laufen (z. B. läuft dieselbe Arbeit schon); kein Fehler. */
final class TaskSkipped extends RuntimeException {}
