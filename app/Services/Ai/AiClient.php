<?php

namespace App\Services\Ai;

use App\Models\AiModel;

/** Eine Textanfrage an ein KI-Modell: Systemanweisung + Nutzertext rein, Antworttext raus. */
interface AiClient
{
    /** @throws AiException bei Konfigurations-, Netz- oder Anbieterfehlern (Meldung ist für Nutzer gedacht) */
    public function complete(AiModel $model, string $system, string $prompt): string;
}
