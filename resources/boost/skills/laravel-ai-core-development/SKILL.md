---
name: laravel-ai-core-development
description: >
  Work with the apavliukov/laravel-ai-core package on top of laravel/ai: AI agents and
  request types, AiRequests (dispatch, run, open, classify, record), result appliers,
  batches and polling, the model catalogue and provider priority, the ai_requests ledger,
  cost, events and AI tests. Use when adding an agent or a request type, wiring a new AI
  action, polling a queued AI request, adding a provider or model, debugging which model a
  request ran on or why it failed, or testing AI code. The application's own enums,
  tenancy and UI live in the project's code and CLAUDE.md manifest — read them alongside
  this skill.
---

# Laravel AI Core

Namespace `AlexPavliukov\AiCore\`. The SDK (`laravel/ai`) owns the agent API and the
transport; the package owns execution and the ledger: catalogue, `ai_requests` rows, the
executor, the queued job, cost and batch status. The application owns its enums, agents,
actions, result DTOs, tenancy and UI. Never re-implement the core in the application —
call it.

## How It Works

1. An action builds the user prompt from its domain input and calls `AiRequests`.
2. `AiRequests` picks the model (`ModelResolver`), fires `AiRequestDispatching`, opens a
   pending `ai_requests` row and either queues `ProcessAiRequestJob` (`dispatch()`) or runs
   inline (`run()`).
3. The executor rebuilds the agent from its state, prompts the SDK, fails truncated or
   rejected answers, runs the applier, then writes response, usage (SDK shape), cost and
   status, and fires `AiRequestCompleted` / `AiRequestFailed`.
4. The UI polls `AiBatches::status($batchId)` and builds DTOs with
   `AiResponseDto::fromRequest($row)`.

## Model Selection

- `ai_providers.is_active` is a **hard switch**: a disabled provider gets no request.
- Order per capability: `ProviderEnum::priority(AiCapability)`.
- Text: the model assigned to the request type (`ModelAssignments`) if its provider is
  enabled; else the active model of the first enabled provider, in the priority of the
  type's most specific required capability, that can do everything the type requires.
- One capability (transcription, classification): `AiRequests::modelFor($capability)` /
  `classificationModel()` — first catalogued model of the first enabled provider in that
  capability's priority, with an API key set.
- **No model throws `AiMisconfiguredException`** from `dispatch/run/open/classify/record`.
  Use the nullable `textModel()` / `modelFor()` to decide whether to show an AI action.

## Adding a Request Type and an Agent

1. Add a case to the application's request-type enum (implements
   `AiRequestTypeContract`, int-backed — the value is stored, never reuse one):
   - `agentClass()` → the agent class (null for transcription/classification types);
   - `failsBatch()` → whether its failure fails the whole batch;
   - `requiredCapabilities()` → `[AiCapability::Text]`, plus `AiCapability::WebSearch` if
     the agent searches.
2. Create the agent:

```php
final class SummaryAgent extends BaseAgent implements HasStructuredOutput
{
    public function __construct(private readonly string $locale = 'en') {}

    public function requestType(): AiRequestTypeContract
    {
        return AiRequestType::SUMMARY;
    }

    public function essentialPrompt(): string
    {
        return '...'; // what the code parses against; never editable
    }

    public function systemPrompt(): string
    {
        return '...'; // what the product asks
    }

    public function schema(JsonSchema $schema): array { /* SDK */ }

    protected function agentState(): array
    {
        return ['locale' => $this->locale]; // keys = constructor parameter names
    }

    protected function validate(array $structured): ?string
    {
        return $structured['items'] === [] ? 'No items returned.' : null;
    }
}
```

- Override **`protected agentState()` / `protected validate()`**, never `state()` /
  `rejection()` (final): a public hook on an abstract agent breaks under Pint's
  `final_public_method_for_abstract_class`.
- A searching agent implements `HasTools` and `use SearchesTheWeb;` (override
  `webSearch()` to cap searches or narrow domains).
- `instructionsAddendum()` appends text after the system prompt for a mode of a run.

## Calling AiRequests

Inject `AlexPavliukov\AiCore\AiRequests` into the action:

```php
$batchId = $this->aiRequests->dispatch(
    AiRequestType::SUMMARY,
    $subject,                 // the Eloquent model the request is about
    $this->userPrompt($subject),
    agentState: ['locale' => $locale],
    customPrompt: $customPrompt, // appended under ai-core.custom_prompt_heading
    applier: ApplySummary::class, // optional
    applyContext: ['locale' => $locale],
    batchId: $existingBatchId, // optional: join a batch the same user started
);
```

| Need | Call |
|---|---|
| Queue and poll | `dispatch()` → batch id |
| Answer in hand (already in a job) | `run()` → `AiResponseDto` |
| Call outside an agent (transcription) | `open()`, then `record($row, $model, $text, $response, $usage, $startedAt)` or `fail($row, $model, $exception, $startedAt)` |
| Classification | `classificationModel()`, `open()`, `classify($row, $state, $questions, $model)` |
| Which model would run | `textModel($type, $subject)`, `modelFor($capability)` |
| Skip unchanged work | `AiRequest::lastCompletedFingerprint($subject, $type)` vs `Fingerprint::text()` / `Fingerprint::classification()` |

## Appliers

An applier writes the structured answer **in the worker, before the row reads
completed**, so a poll that sees `COMPLETED` sees the result:

```php
final readonly class ApplySummary implements AppliesAiResult
{
    public function applyResult(AiRequest $aiRequest, array $structured, array $context): void
    {
        // $aiRequest is the package's base model; throwing fails the request.
    }
}
```

## Batches and Polling

- Keep the batch id on the server (Livewire `#[Locked]`, session). **Never accept a batch
  id from the browser**: `AiBatches::status()` reads across tenants by design.
- `status($batchId)` → `BatchStatus { state: Running|Completed|Failed, requests, error }`.
  `Failed` only when a row whose type `failsBatch()` failed.
- Build DTOs with `AiResponseDto::fromRequest($row)`; result DTOs extend
  `BaseAiResultDto::fromAiResponse()`.
- `AiBatches::reattach($batchId, $owner)` moves a batch from a placeholder subject to the
  record it turned out to belong to; only the same owner's rows move.
- The polling UI (trait, toast, `wire:poll`) is the application's code.

## Errors

- `error_message` is safe to show: SDK provider errors, misconfiguration, truncation and
  `validate()` rejections keep their text; anything else is
  `The AI request failed (ref #id).` and the full exception goes to `report()`.
- Never write provider or database exception text into the ledger or a toast yourself.

## Events

- `AiRequestDispatching` — before the pending row; throw to stop the request (quotas).
- `AiRequestCompleted` / `AiRequestFailed` — after the row is written (analytics, alerts).

## Catalogue

- Add a provider/model case to the enums, then `php artisan ai-core:sync` (also on every
  deploy). Removed cases are soft-deleted; a retired active model falls back to the
  provider's `defaultModel()`.
- Prices live in `ModelEnum::pricing()` (dollars per 1M tokens; `audio_minute`;
  `web_search` per 1000 calls). Cost is computed by the package.
- Enabling a provider is a data change (`is_active`), not code.

## Testing

```php
use InteractsWithAiCore;

$this->seedAiCatalog(ModelEnum::TEXT_MODEL, ModelEnum::CLASSIFIER); // enables each provider
$this->fakeAgents([new TextResponse('Done.', new TextUsage(100, 50), new Meta('openai', 'model'))]);
SummaryAgent::fake([...]); // per agent, SDK fake
Queue::fake(); Queue::assertPushed(ProcessAiRequestJob::class);
```

- The SDK fake drops provider tool calls and always reports a finished answer: for
  truncation or web search counting, give the executor a stub agent whose `prompt()`
  returns a built `AgentResponse`.
- Expect `The AI request failed (ref #id).` in `error_message` for non-provider failures.

## Never

- Re-implement model selection, cost, usage shape, the executor or batch status in the
  application.
- Call a disabled provider, or fall back to one because its key is set.
- Return null or skip silently when no model is available — let
  `AiMisconfiguredException` surface.
- Take a ledger row's tenant from the session — take it from the subject.
- Accept a batch id from the browser.
- Override `state()` / `rejection()`, or put user input into `agentState()` or `applier`.
- Change `ai-core.custom_prompt_heading` casually: it is part of every request
  fingerprint.
