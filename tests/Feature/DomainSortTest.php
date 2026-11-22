<?php

namespace Tests\Feature;

use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DomainSortTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function domains_are_sorted_by_customer_size_then_name(): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));
        $small = Customer::create(['name' => 'Aaa small']);
        $big = Customer::create(['name' => 'Zzz big']);

        foreach (['z-big.example', 'a-big.example', 'm-big.example'] as $name) {
            Domain::create(['name' => $name, 'customer_id' => $big->id]);
        }
        Domain::create(['name' => 'b-small.example', 'customer_id' => $small->id]);
        Domain::create(['name' => 'a-none.example']);

        Livewire::test(ListDomains::class)->assertCanSeeTableRecords(
            Domain::query()->whereIn('name', ['a-big.example', 'm-big.example', 'z-big.example', 'b-small.example', 'a-none.example'])
                ->get()->sortBy(fn ($d) => array_search($d->name, ['a-big.example', 'm-big.example', 'z-big.example', 'b-small.example', 'a-none.example']))->values(),
            inOrder: true,
        );
    }
}
