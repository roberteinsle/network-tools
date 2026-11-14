<?php

use Illuminate\Support\Facades\Schedule;

// Fällige Domains alle 5 Minuten in die Queue stellen (Intervall je Domain im Backend)
Schedule::command('domains:check-due')->everyFiveMinutes()->withoutOverlapping();

// Alte Prüfprotokolle nach der eingestellten Aufbewahrungsdauer löschen
Schedule::command('model:prune')->dailyAt('03:30');
