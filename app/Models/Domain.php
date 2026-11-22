<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DkimProvider;
use App\Enums\NotifyLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'name', 'active', 'check_interval_minutes', 'inherit_dkim_providers',
        'dkim_providers', 'dkim_selectors', 'web_hosts', 'extra_ips', 'check_mx_tls', 'notify_ip_changes',
        'disabled_checks', 'notification_emails', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'inherit_dkim_providers' => 'boolean',
            'check_mx_tls' => 'boolean',
            'notify_ip_changes' => 'boolean',
            'dkim_providers' => 'array',
            'dkim_selectors' => 'array',
            'web_hosts' => 'array',
            'extra_ips' => 'array',
            'disabled_checks' => 'array',
            'notification_emails' => 'array',
            'last_status' => CheckStatus::class,
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $domain): void {
            $domain->name = self::normalizeName($domain->name);
        });
    }

    /** Kleinschreibung, ohne Punkt am Ende, IDN als Punycode. */
    public static function normalizeName(string $name): string
    {
        $name = rtrim(strtolower(trim($name)), '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $name)) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        return $name;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function checkRuns(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(CheckRun::class)->latestOfMany();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
    }

    /** @return list<DkimProvider> */
    public function effectiveDkimProviders(): array
    {
        $values = $this->inherit_dkim_providers
            ? ($this->customer?->dkim_providers ?? [])
            : ($this->dkim_providers ?? []);

        return array_values(array_filter(array_map(fn ($v) => DkimProvider::tryFrom((string) $v), $values)));
    }

    public function isCheckEnabled(CheckType $type): bool
    {
        if ($type === CheckType::TlsMx && ! $this->check_mx_tls) {
            return false;
        }

        return ! in_array($type->value, $this->disabled_checks ?? [], true);
    }

    /** @return list<string> */
    public function webHosts(): array
    {
        $hosts = array_filter(array_map(fn ($h) => self::normalizeName((string) $h), $this->web_hosts ?? []));

        return $hosts !== [] ? array_values(array_unique($hosts)) : [$this->name, 'www.'.$this->name];
    }

    /** @return list<string> */
    /**
     * Empfänger einer Alarm-Mail: globale Adressen und Adressen der Domain immer,
     * Adressen des Kunden nur, wenn dessen Benachrichtigungsstufe die Meldung umfasst.
     *
     * @param  list<array>  $changes
     * @return list<string>
     */
    public function recipientsFor(array $changes, CheckRun $run, bool $initial): array
    {
        $customer = $this->customer;
        $customerWants = $customer !== null
            && ($customer->notify_level ?? NotifyLevel::All)->wants($changes, $run->results, $initial);

        $emails = array_merge(
            AppSetting::current()->notification_emails ?? [],
            $customerWants ? ($customer->notification_emails ?? []) : [],
            $this->notification_emails ?? [],
        );

        return array_values(array_unique(array_filter(array_map('trim', $emails))));
    }
}
