<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use RuntimeException;

/**
 * Interne Ausnahme von RecordWriter::trial(): rollt den Probelauf zurück. Verlässt den Writer nie.
 *
 * @internal
 */
final class TrialRollback extends RuntimeException {}
