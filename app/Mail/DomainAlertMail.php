<?php

namespace App\Mail;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\CheckRun;
use App\Models\Domain;
use App\Services\ChangeDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DomainAlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Domain $domain,
        public CheckRun $run,
        public array $changes,
        public bool $initial = false,
    ) {}

    public function envelope(): Envelope
    {
        $worse = collect($this->changes)->where('kind', 'worse')->count();
        $prefix = $this->run->status->emoji();

        $subject = match (true) {
            $this->initial => "Erstprüfung {$this->domain->name}: {$this->run->status->getLabel()}",
            $worse > 0 => "{$this->domain->name}: {$worse} neue(s) Problem(e)",
            default => "{$this->domain->name}: ".count($this->changes).' Änderung(en)',
        };

        return new Envelope(subject: "{$prefix} {$subject}");
    }

    public function content(): Content
    {
        $changes = array_map(fn ($c) => [
            'label' => CheckType::from($c['check'])->getLabel(),
            'kind' => ChangeDetector::kindLabel($c['kind']),
            'from' => $c['from'] ? CheckStatus::from($c['from']) : null,
            'to' => CheckStatus::from($c['to']),
            'from_summary' => $c['from_summary'],
            'to_summary' => $c['to_summary'],
        ], $this->changes);

        $problems = $this->run->results
            ->filter(fn ($r) => $r->status->isProblem())
            ->map(fn ($r) => ['label' => $r->check->getLabel(), 'status' => $r->status, 'summary' => $r->summary])
            ->values()
            ->all();

        return new Content(markdown: 'mail.domain-alert', with: [
            'changeRows' => $changes,
            'problems' => $problems,
            'url' => DomainResource::getUrl('view', ['record' => $this->domain], panel: 'admin'),
        ]);
    }
}
