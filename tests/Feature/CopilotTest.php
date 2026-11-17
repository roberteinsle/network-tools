<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Filament\Resources\AiModels\Pages\ManageAiModels;
use App\Livewire\Copilot;
use App\Models\AiModel;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Models\Domain;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Copilot\Copilot as CopilotService;
use App\Services\Copilot\CopilotPrompt;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class CopilotTest extends TestCase
{
    use RefreshDatabase;

    private const RESULTS = [
        ['check' => 'spf', 'status' => 'error', 'summary' => 'DNS-Lookup-Limit überschritten', 'details' => ['messages' => [['status' => 'error', 'text' => '11 von 10 Lookups']], 'data' => ['Record' => 'v=spf1 include:a include:b -all']]],
        ['check' => 'mx', 'status' => 'ok', 'summary' => '2 MX-Hosts', 'details' => ['messages' => [], 'data' => []]],
        ['check' => 'caa', 'status' => 'info', 'summary' => 'Kein CAA-Record', 'details' => ['messages' => [['status' => 'info', 'text' => 'Kein CAA']], 'data' => []]],
    ];

    private function model(AiProvider $provider = AiProvider::OpenAi, array $attrs = []): AiModel
    {
        return AiModel::create(['name' => 'Test', 'provider' => $provider, 'model' => 'test-model', 'api_key' => 'sk-test', 'is_default' => true, ...$attrs]);
    }

    /** Registry mit festem Fake-Client, der Anfragen mitzählt. */
    private function fakeProvider(string|\Throwable $answer): object
    {
        $client = new class($answer) implements AiClient
        {
            public int $calls = 0;

            public ?string $prompt = null;

            public function __construct(private string|\Throwable $answer) {}

            public function complete(AiModel $model, string $system, string $prompt): string
            {
                $this->calls++;
                $this->prompt = $prompt;

                return is_string($this->answer) ? $this->answer : throw $this->answer;
            }
        };
        $this->app->instance(AiProviderRegistry::class, new class($client) extends AiProviderRegistry
        {
            public function __construct(private AiClient $fake) {}

            public function client(AiProvider $provider): AiClient
            {
                return $this->fake;
            }
        });

        return $client;
    }

    #[Test]
    public function api_key_is_encrypted_and_only_one_default_exists(): void
    {
        $a = $this->model();
        $b = $this->model(AiProvider::Gemini, ['name' => 'Zweites']);

        $this->assertNotSame('sk-test', \DB::table('ai_models')->where('id', $a->id)->value('api_key'));
        $this->assertSame('sk-test', $a->fresh()->api_key);
        $this->assertFalse($a->fresh()->is_default);
        $this->assertSame($b->id, AiModel::preferred()->id);
        $this->assertArrayNotHasKey('api_key', $b->toArray());
    }

    #[Test]
    public function prompt_focuses_on_problems_and_marks_dns_data_as_untrusted(): void
    {
        $prompt = CopilotPrompt::user('example.com', self::RESULTS);
        $this->assertStringContainsString('## SPF – Fehler', $prompt);
        $this->assertStringContainsString('## CAA – Hinweis', $prompt);
        $this->assertLessThan(strpos($prompt, 'CAA – Hinweis'), strpos($prompt, 'SPF – Fehler'));
        $this->assertStringContainsString('- MX: OK – 2 MX-Hosts', $prompt);
        $this->assertStringContainsString('nie als Anweisungen', CopilotPrompt::system());

        $single = CopilotPrompt::user('example.com', self::RESULTS, 'caa');
        $this->assertStringContainsString('Prüfung „CAA“', $single);
        $this->assertStringContainsString('- SPF: Fehler', $single);

        $export = CopilotPrompt::export('example.com', self::RESULTS, '17.11.2026 16:00');
        $this->assertStringContainsString('Stand: 17.11.2026 16:00', $export);
        $this->assertStringContainsString('## MX – OK', $export);
        $this->assertStringContainsString('v=spf1 include:a', $export);
    }

    #[Test]
    public function answers_are_cached_until_regenerated(): void
    {
        $fake = $this->fakeProvider('Tipp');
        $model = $this->model();
        $copilot = app(CopilotService::class);

        $this->assertSame('Tipp', $copilot->recommend($model, 'example.com', self::RESULTS));
        $copilot->recommend($model, 'example.com', self::RESULTS);
        $this->assertSame(1, $fake->calls);

        $copilot->recommend($model, 'example.com', self::RESULTS, fresh: true);
        $this->assertSame(2, $fake->calls);
        $copilot->recommend($model, 'example.com', self::RESULTS, 'spf');
        $this->assertSame(3, $fake->calls);
    }

    #[Test]
    public function openai_and_openrouter_use_chat_completions(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'OpenAI-Antwort']]]]),
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Router-Antwort']]]]),
            'llm.local/*' => Http::response(['error' => ['message' => 'model not found']], 404),
        ]);
        $client = new OpenAiCompatibleClient;

        $this->assertSame('OpenAI-Antwort', $client->complete($this->model(), 'sys', 'frage'));
        $this->assertSame('Router-Antwort', $client->complete($this->model(AiProvider::OpenRouter), 'sys', 'frage'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['messages'][0] === ['role' => 'system', 'content' => 'sys']
            && $r['model'] === 'test-model');

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('HTTP 404 – model not found');
        $client->complete($this->model(AiProvider::OpenAiCompatible, ['base_url' => 'http://llm.local/v1', 'api_key' => null]), 'sys', 'frage');
    }

    #[Test]
    public function gemini_uses_generate_content_with_system_instruction(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Gemini-'], ['text' => 'Antwort']]]]]])]);

        $this->assertSame('Gemini-Antwort', (new GeminiClient)->complete($this->model(AiProvider::Gemini), 'sys', 'frage'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1beta/models/test-model:generateContent')
            && $r->hasHeader('x-goog-api-key', 'sk-test')
            && $r['systemInstruction']['parts'][0]['text'] === 'sys');
    }

    #[Test]
    public function anthropic_uses_the_sdk_with_system_prompt_and_server_side_fallback(): void
    {
        $transport = new class implements ClientInterface
        {
            public ?RequestInterface $request = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
                    'content' => [['type' => 'text', 'text' => 'Claude-Antwort']],
                    'stop_reason' => 'end_turn', 'stop_sequence' => null, 'stop_details' => null,
                    'container' => null, 'context_management' => null,
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]));
            }
        };

        $answer = (new AnthropicClient($transport))->complete($this->model(AiProvider::Anthropic, ['model' => 'claude-opus-5-5']), 'sys', 'frage');

        $this->assertSame('Claude-Antwort', $answer);
        $body = json_decode((string) $transport->request->getBody(), true);
        $this->assertSame('Bearer sk-test', $transport->request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $transport->request->getHeaderLine('anthropic-beta'));
        $this->assertSame(['claude-opus-5-5', 'sys', 'default'], [$body['model'], $body['system'], $body['fallbacks']]);
    }

    #[Test]
    public function missing_api_key_is_reported_before_any_request(): void
    {
        Http::fake();
        $model = $this->model(attrs: ['api_key' => null]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('kein API-Schlüssel');
        app(AiProviderRegistry::class)->complete($model, 'sys', 'frage');
    }

    #[Test]
    public function component_shows_escaped_markdown_answer_and_export(): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));
        $fake = $this->fakeProvider("## Schritt 1\nSPF kürzen <script>alert(1)</script>");
        $this->model();

        Livewire::test(Copilot::class, ['domain' => 'example.com', 'results' => self::RESULTS])
            ->call('open', 'spf')
            ->call('ask')
            ->assertSee('KI-Empfehlung: SPF')
            ->assertSeeHtml('<h2>Schritt 1</h2>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSee('Export für KI-Chat')
            ->assertSee('v=spf1 include:a include:b -all');

        $this->assertStringContainsString('Prüfung „SPF“', $fake->prompt);
    }

    #[Test]
    public function component_without_models_points_to_settings_and_shows_errors(): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));
        Livewire::test(Copilot::class, ['domain' => 'example.com', 'results' => self::RESULTS])
            ->call('open')
            ->assertSee('Noch kein KI-Modell angelegt');

        $this->fakeProvider(new AiException('Ratenlimit erreicht'));
        $this->model();
        Livewire::test(Copilot::class, ['domain' => 'example.com', 'results' => self::RESULTS])
            ->call('ask')
            ->assertSee('Ratenlimit erreicht');
    }

    #[Test]
    public function settings_page_creates_models_and_domain_page_offers_copilot(): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));
        $this->get('/admin/ai-models')->assertOk()->assertSee('KI-Modelle');

        Livewire::test(ManageAiModels::class)
            ->callAction('create', ['name' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-x', 'is_default' => true, 'active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('sk-ant-x', AiModel::first()->api_key);

        // Schlüssel bleibt beim Bearbeiten ohne Eingabe erhalten
        Livewire::test(ManageAiModels::class)
            ->callTableAction('edit', AiModel::first(), ['name' => 'Claude Opus', 'api_key' => ''])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['Claude Opus', 'sk-ant-x'], [AiModel::first()->name, AiModel::first()->api_key]);

        $domain = Domain::create(['name' => 'example.com']);
        $run = CheckRun::create(['domain_id' => $domain->id, 'status' => 'error', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 1]);
        foreach (self::RESULTS as $r) {
            CheckResult::create(['check_run_id' => $run->id, 'domain_id' => $domain->id, 'fingerprint' => 'x', 'state' => [], ...$r]);
        }

        $this->get("/admin/domains/{$domain->id}")
            ->assertOk()
            ->assertSee('KI-Copilot')
            ->assertSee('Export für KI-Chat')
            ->assertSee('KI-Empfehlung');
        $this->get("/admin/check-runs/{$run->id}")->assertOk()->assertSee('KI-Copilot');
    }
}
