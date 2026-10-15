# Laravel AI Core

The shared AI layer above [laravel/ai](https://github.com/laravel/ai): a model catalogue
with prices, a ledger of every provider call with its usage and cost, an executor that
runs agents inline or on the queue, batch polling, and the extension points a host
application needs around them.

The SDK owns the agent API and the transport. This package owns execution and the
ledger. Your application keeps what is genuinely its own: the provider, model and
request-type enums, the agents, the actions that build prompts, the UI, and tenancy.

Moving an existing application onto the package? Read [docs/ADOPTION.md](docs/ADOPTION.md).

## Requirements

- PHP `^8.4`
- Laravel `^13.0`
- [`laravel/ai`](https://github.com/laravel/ai) `^1.1`

## Installation

The package is published on [Packagist](https://packagist.org/packages/apavliukov/laravel-ai-core):

```bash
composer require apavliukov/laravel-ai-core
```

Publish the config and the migration, then migrate:

```bash
php artisan vendor:publish --tag=ai-core-config
php artisan vendor:publish --tag=ai-core-migrations
php artisan migrate
```

Table names are configurable in `config/ai-core.php` (`table_names`); set them before
migrating. The published migration keys `user_id` as a big integer; if your users have
string keys (UUID, ULID), change that column in your copy before migrating.

## The catalogue

Describe your providers, models and request types as backed enums implementing the
core contracts, and register them in `config/ai-core.php`:

```php
'enums' => [
    'provider' => App\Enums\Ai\AiProvider::class,       // AiProviderContract
    'model' => App\Enums\Ai\AiModel::class,             // AiModelContract
    'request_type' => App\Enums\Ai\AiRequestType::class, // AiRequestTypeContract
],
```

- `AiProviderContract`: `label()`, `lab()` (the SDK lab), `defaultModel()`, and
  `static priority(AiCapability)`: the providers to try, in order, for a capability.
- `AiModelContract`: `label()`, `pricing()` (dollars per 1M tokens, plus `cache_write`,
  `audio_minute` and `web_search` per 1000 calls where they apply), `capabilities()`,
  `provider()`.
- `AiRequestTypeContract` (int-backed): `agentClass()`, `failsBatch()`,
  `requiredCapabilities()`.

Sync the enums into the database on every deploy:

```bash
php artisan ai-core:sync
```

New cases appear, removed ones are soft-deleted, and a retired active model is replaced
by its provider's default. Enabling a provider (`is_active`) and choosing its active model
is left to your admin UI.

`is_active` is a hard switch: a disabled provider receives no request of any kind. A text
request runs on the model assigned to its type (when that model's provider is enabled),
else on the active model of the first enabled provider in `priority(AiCapability::Text)`
that can do what the type requires. A transcription or a classification runs on the first
catalogued model with that capability, of the first enabled provider in that
capability's priority whose API key is set.

## Agents

Extend `AlexPavliukov\AiCore\Agents\BaseAgent`. Instructions are the essential prompt,
then the system prompt, then an optional addendum:

```php
final class SummaryAgent extends BaseAgent
{
    public function __construct(private readonly string $locale = 'en') {}

    public function requestType(): AiRequestTypeContract { return AiRequestType::Summary; }
    public function essentialPrompt(): string { return 'Answer in JSON.'; }
    public function systemPrompt(): string { return 'Summarise the facts.'; }

    protected function agentState(): array { return ['locale' => $this->locale]; }
}
```

`agentState()` is what the queue carries to rebuild the agent (keys are constructor
parameter names). Override `validate()` to reject a structured answer the schema alone
cannot police.

To let an agent search the web, implement `Laravel\Ai\Contracts\HasTools`, use the
`SearchesTheWeb` trait, and list `AiCapability::WebSearch` in the request type's
`requiredCapabilities()`. Searches are counted and priced from the model's `web_search`
rate.

## Running requests

Inject `AlexPavliukov\AiCore\AiRequests`:

```php
$batchId = $ai->dispatch(AiRequestType::Summary, $subject, $prompt, ['locale' => 'de']);
$response = $ai->run(AiRequestType::Summary, $subject, $prompt);
```

- `dispatch()` opens a pending ledger row and queues the call; it returns the batch id
  to poll.
- `run()` executes in the current process and returns an `AiResponseDto`.
- `open()`, `record()` and `fail()` cover calls made outside an agent (a transcription).
- `classify()` and `classificationModel()` run a `Laravel\Ai\Classification`.
- `textModel()` tells which model a request type would run on; `modelFor(AiCapability)`
  picks the model for a call that needs one capability (a transcription).

No model is an error, never a silent no-op: when no enabled provider can answer, every
call throws `AiMisconfiguredException` (the exception handler reports it). Use
`textModel()` / `modelFor()`, which return null, to decide whether to offer an AI action
at all.

Every call returns an `AiResponseDto`; `AiResponseDto::fromRequest($row)` builds the same
DTO from a ledger row, e.g. when a poll finds a batch completed.

A custom prompt passed to `dispatch()` or `run()` is appended to the request message under
`ai-core.custom_prompt_heading`. A result your application writes itself can be applied
in the worker, before the row reads completed, by passing an `AppliesAiResult` class as
`applier`.

Queued calls run `ProcessAiRequestJob` on `ai-core.queue.connection`. The connection's
`retry_after` must exceed `AiRequestExecutor::TIMEOUT_SECONDS`.

## Polling a batch

`AiBatches::status($batchId)` reports whether a batch is still running, completed or
failed, with the batch's requests and the error that failed it. Polling it from your UI
(a Livewire `wire:poll`, an endpoint, anything) is up to your application.
`AiBatches::reattach()` moves a batch to the record it turned out to belong to.

`status()` reads a batch across tenants, so keep batch ids on the server: never accept
one from the browser (in Livewire, mark the property `#[Locked]`). Joining a batch in
`open()`/`dispatch()` and `reattach()` only touch the signed-in member's own rows.

A failed row's `error_message` is safe to show: the SDK's provider errors and the core's
configuration errors keep their text, anything else reads "The AI request failed
(ref #id)" and is reported in full through `report()`.

## Extension points

Both are plain classes: extend and bind your own in a service provider.

- `SystemPrompts::for(BaseAgent $agent)`: where a system prompt comes from. Defaults to
  the agent's code.
- `ModelAssignments`: a model per request type. By default there are none and every
  type runs on the active model; store assignments wherever your application keeps
  settings.

## Tenancy and other host concerns

The ledger has no tenant column. Point `ai-core.models.request` at your own subclass of
`AlexPavliukov\AiCore\Models\AiRequest`, add the column in your own migration, and fill
it in a `creating` hook; rows opened by the core have their `requestable` relation set
before saving. Quotas, analytics and alerts listen to `AiRequestDispatching` (throw to
stop a request before its row exists), `AiRequestCompleted` and `AiRequestFailed`.

## Testing

Use `AlexPavliukov\AiCore\Testing\InteractsWithAiCore` in your tests:
`seedAiCatalog($model)` syncs the catalogue and activates a model, and
`fakeAgents([...])` gives every registered agent the same fake answers.

## License

MIT.
