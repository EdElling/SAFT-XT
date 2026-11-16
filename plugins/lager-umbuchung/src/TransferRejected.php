<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use RuntimeException;

/** Umbuchung nicht möglich; die Meldung ist übersetzt und für die Anzeige gedacht. */
final class TransferRejected extends RuntimeException {}
