<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art, die Datensätze schreibt: Der Schritt läuft in RecordWriter::batch(), und der Fortschritt der
 * Arbeit wird in derselben Transaktion gespeichert. Scheitert der Schritt, bleibt weder Datenänderung noch Fortschritt;
 * der nächste Versuch beginnt beim selben Block.
 *
 * @api
 */
interface WritesRecords {}
