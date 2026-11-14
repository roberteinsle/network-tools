<?php

namespace App\Checks;

use App\Enums\CheckType;

interface Check
{
    public function type(): CheckType;

    public function run(CheckContext $ctx): CheckOutcome;
}
