<?php

declare(strict_types=1);

return [
    // Höchstzahl Datensätze je Sammellauf „Dokumente erstellen“ (B-124, D3a); 0 = ohne Grenze. Der Lauf ist eine Arbeit
    // (Fundament 2, F3b) in kurzen Schritten – eine Grenze braucht es nur, wenn der Speicherplatz knapp ist.
    'batch_max' => (int) env('DOCUMENTS_BATCH_MAX', 0),
];
