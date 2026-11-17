<?php

namespace App\Services\Ai;

use RuntimeException;

/** Fehler beim Abruf einer KI-Antwort; die Meldung wird im Backend angezeigt (ohne Schlüssel). */
class AiException extends RuntimeException {}
