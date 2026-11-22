<?php

declare(strict_types=1);

return [
    // Höchstzahl Datensätze je Sammellauf „Dokumente erstellen“ (B-124, D3a). Der Lauf geschieht direkt in der Anfrage
    // (SAFT braucht keinen Queue-Worker) – mehr Datensätze brauchen mehr Zeit und Speicher (PHP max_execution_time).
    'batch_max' => (int) env('DOCUMENTS_BATCH_MAX', 50),
];
