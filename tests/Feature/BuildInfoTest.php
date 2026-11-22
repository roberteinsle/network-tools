<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BuildInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        BuildInfo::flush();
        parent::tearDown();
    }

    #[Test]
    public function release_commit_and_date_are_shown_next_to_the_title(): void
    {
        config(['app.version' => 'v1.4.2', 'app.commit' => 'abc1234', 'app.commit_date' => '2026-11-20T10:15:00+00:00']);
        BuildInfo::flush();
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Network-Tools')
            ->assertSee('v1.4.2 · abc1234 · 20.11.2026');
    }
}
