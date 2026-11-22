<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use RuntimeException;

/** Eine Vorlage lässt sich nicht umsetzen (Syntax, unbekannte Formatierung) – Meldung für die Oberfläche. */
final class TemplateException extends RuntimeException {}
