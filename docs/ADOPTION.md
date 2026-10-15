# Adopting `apavliukov/laravel-ai-core`

A guide for an agent (or developer) moving an existing Laravel application's AI layer
onto this package. It describes the package model, its invariants, what leaves the
application and what stays, the migration steps, the decisions you must resolve
against the specific application, and the anti-patterns to remove.

> The package is the shared layer above `laravel/ai`: the SDK owns the agent API and
> the transport, the package owns execution and the ledger. The goal of an adoption is
> to **delete the application's own generic AI plumbing and converge on the package**,
> not to bend the package to the application's existing shape. A refactor onto the
> package should not change behaviour; where it must (the ledger's usage shape, error
> messages), the change is listed below.

- **Composer:** `apavliukov/laravel-ai-core` (`^0.3`)
- **Namespace:** `AlexPavliukov\AiCore\`
- **Requires:** PHP `^8.4`, Laravel `^13`, `laravel/ai ^1.1`

---

## 0. Before you start — resolve these with the application's owner

These are not inferable from the package. Read the application's AI code and decide
each one explicitly:

1. **Existing tables.** Does the application already have provider, model and request
   tables? If their columns match the package schema (see §4), keep them and do not
   publish the package migration; point `ai-core.table_names` at them if their names
   differ. Foreign-key columns are fixed (`ai_provider_id`, `ai_model_id`): rename
   yours in your own migration if they differ.
2. **Tenancy.** Does the ledger carry a tenant column? The package has none. Keep it in
   your own migration and your `AiRequest` subclass (§5).
3. **Which providers are enabled, and in what order per capability.** `is_active` is a
   hard switch (§3). Decide `priority()` for Text, WebSearch, Transcription and
   Classification, and which provider rows must be enabled on production at deploy.
4. **Model assignments per request type.** Does an admin pick a model per request
   type? If yes, where is it stored (settings package, a table, config)? The package
   stores nothing (§7).
5. **System prompts.** Code only (the default), or editable for some types? Editable
   prompts in a multi-tenant product drift towards one tenant; prefer code.
6. **Historic usage rows.** In what shape is usage stored today, and is anything
   (statistics, exports, an analytics mirror) reading old rows? See §9.
7. **Queue.** Which connection runs AI work, and is its `retry_after` above the job
   timeout (§10)?
8. **What the UI shows on failure.** `error_message` is now safe to show but generic for
   non-provider failures (§11). Check every place that displayed raw errors.

---

## 1. The model in one screen

```
host enums (implement contracts)          package
─────────────────────────────────          ─────────────────────────────────────────
ProviderEnum : AiProviderContract   ──►    ai-core:sync ──► ai_providers / ai_models
ModelEnum    : AiModelContract                                 (is_active, active_model_id)
RequestType  : AiRequestTypeContract
  agentClass() ──► Agent extends BaseAgent
                                           AiRequests::dispatch() ──► ai_requests row (pending)
action builds the user prompt ──────────►                       └─► ProcessAiRequestJob
                                           AiRequests::run()      ──► AiRequestExecutor
                                                                       ├─ prompt (SDK)
                                                                       ├─ truncation / rejection
                                                                       ├─ applier (host)
                                                                       └─ tokens, cost, status
UI polls AiBatches::status($batchId) ◄──── BatchStatus (state, requests, error)
```

- **Agents, actions, result DTOs, UI** stay in the application.
- **Catalogue, ledger, execution, cost, batch status** come from the package.

---

## 2. Public surface

| Class | Role |
|---|---|
| `AiRequests` | The service: `dispatch()`, `run()`, `open()`, `classify()`, `record()`, `fail()`, `textModel()`, `modelFor()`, `classificationModel()` |
| `Agents\BaseAgent` | Base agent: essential prompt + system prompt + addendum; `agentState()`, `validate()` hooks |
| `Agents\Concerns\SearchesTheWeb` | Gives an agent the SDK `WebSearch` tool |
| `Agents\SystemPrompts` | Where a system prompt comes from (extend and bind) |
| `Catalog\ModelAssignments` | Model per request type (extend and bind) |
| `Batches\AiBatches` | `status()`, `reattach()` |
| `Dto\AiResponseDto`, `Dto\BaseAiResultDto` | Response DTO (`fromRequest()`), base for result DTOs |
| `Contracts\*` | `AiProviderContract`, `AiModelContract`, `AiRequestTypeContract`, `AppliesAiResult` |
| `Enums\AiCapability`, `Enums\ProcessStatus` | Capabilities, row status |
| `Events\*` | `AiRequestDispatching`, `AiRequestCompleted`, `AiRequestFailed` |
| `Testing\InteractsWithAiCore` | `seedAiCatalog()`, `fakeAgents()` |
| `ai-core:sync` | Enum → database catalogue sync |

`Execution\AiRequestExecutor` and `Jobs\ProcessAiRequestJob` are internal: reach them
through `AiRequests`. The executor's timeout constants are public (§10).

---

## 3. Invariants

- **`is_active` is a hard switch.** A disabled provider receives no request of any
  kind: text, transcription, classification. Several providers may be enabled; the
  order they are tried in is `AiProviderContract::priority(AiCapability)`.
- **Text model selection:** the model assigned to the request type when its provider
  is enabled and the model is catalogued; else the active model of the first enabled
  provider, in the priority of the type's most specific required capability
  (WebSearch over Text), that can do everything the type requires.
- **Capability model selection** (`modelFor()`, classification): the first catalogued
  model with the capability, of the first enabled provider in that capability's
  priority whose API key is configured.
- **No model is an error.** `dispatch()`, `run()`, `open()`, `classify()` and
  `record()` throw `AiMisconfiguredException`; they never no-op silently. Ask
  `textModel()` / `modelFor()` (nullable) to decide whether to offer an AI action.
- **An assigned model that cannot do what its type requires** throws before the
  provider is called. An assignment to a retired or disabled model is logged and
  ignored.
- **One ledger row per provider call**, written before the call (pending), updated by
  the executor. A result the host writes itself (an applier) is written before the row
  reads completed, so a poll that sees `COMPLETED` always sees the result.
- **Usage is stored in the SDK's shape:** `TextUsage::toArray()` plus `web_search_calls`
  for agents, `TranscriptionUsage::toArray()` for audio. Cost is computed by the
  package from the model's `pricing()`.
- **`error_message` is safe to show.** Only the SDK's templated provider errors
  (`FailoverableException`), the package's `AiMisconfiguredException`, truncation and
  agent rejections keep their text; everything else is stored as
  `The AI request failed (ref #id).` and reported in full.
- **Batch ownership.** Joining a batch (`open()`/`dispatch()` with a `batchId`) and
  `reattach()` only touch rows of the same owner: the given user id, else the signed-in
  user, else rows with no user. `AiBatches::status()` reads across tenants on purpose
  (the batch id is a server-issued UUID); never accept a batch id from the browser.
- **The queued job is encrypted** and never retried.

---

## 4. Migration steps (existing application)

1. `composer require apavliukov/laravel-ai-core`. Keep `laravel/ai` in your own
   `require` (your agents use the SDK API directly).
2. Publish the config (`vendor:publish --tag=ai-core-config`). Publish the migration
   only for a fresh schema; for existing tables, compare with
   `database/migrations/*_create_ai_core_tables.php` and write your own migration for
   the difference (renamed foreign keys, missing `user_id`, `batch_id`, `fingerprint`,
   `custom_prompt`).
3. Make the enums implement the contracts (§6) and register them in
   `config/ai-core.php` together with your model subclasses (§5).
4. Replace the application's catalogue seeder/sync with `ai-core:sync` (or call
   `SyncAiCatalog` from your seeder). Enable the providers decided in §0.3 with a data
   migration or a deploy step.
5. Replace the application's base agent and registry with the package's: agents extend
   `BaseAgent`; overridden hooks are `protected agentState()` and `protected validate()`.
6. Replace hand-rolled dispatch/execute/record code in actions with `AiRequests`:
   `dispatch()` for queued work, `run()` inside a job, `open()` + `record()`/`fail()` for
   calls outside an agent (transcription), `classify()` for classifications.
7. Replace result appliers with `AppliesAiResult` implementations; their
   `applyResult()` receives the package's base `AiRequest`.
8. Keep the polling UI in the application, over `AiBatches::status()` (§8).
9. Migrate historic usage rows (§9) and fix whatever reads them.
10. Move queue settings to `ai-core.queue.connection` and check `retry_after` (§10).
11. Switch test helpers to `InteractsWithAiCore` (§12).
12. Delete what the package now owns (§13). The full test suite must be green before
    and after, with only the intended changes (§9, §11).

---

## 5. Models, tenancy and config

Point `ai-core.models.*` at your subclasses when you need factories, tenancy or
display accessors:

```php
final class AiRequest extends \AlexPavliukov\AiCore\Models\AiRequest
{
    use BelongsToTenant;
    use HasFactory;

    #[Initialize]
    public function mergeTenantFillable(): void
    {
        $this->mergeFillable(['tenant_id']);
    }

    protected static function booted(): void
    {
        static::creating(static function (self $aiRequest): void {
            // Set by AiRequests::open(); factories and seeders set the tenant themselves.
            if (! $aiRequest->relationLoaded('requestable')) {
                return;
            }

            $aiRequest->tenant_id = $aiRequest->requestable->tenantId();
        });
    }
}
```

- `AiRequests::open()` sets the `requestable` relation before saving, so the hook reads
  the subject without another query. Take the tenant **from the subject**, not from
  the session: a worker, the CLI or a platform admin has no current tenant, or
  another one.
- Display accessors (formatted cost, prompts, legacy response parsing) belong in the
  subclass; override `responseText()` if old rows store answers in another shape.
- `config/ai-core.php` holds the model classes, the enums, `table_names`,
  `custom_prompt_heading` and `queue.connection`. If the application already sends a
  Custom Prompt under its own heading, copy that text into `custom_prompt_heading`:
  the heading is part of the request fingerprint, and changing it re-asks every
  fingerprinted request once.

---

## 6. Contracts the enums implement

- **`AiProviderContract`** (string-backed): `label()`, `lab()` (the SDK `Lab`),
  `defaultModel()` (replaces a retired active model), `static priority(AiCapability)`:
  `list<self>` in the order to try.
- **`AiModelContract`** (string-backed): `label()`, `pricing()` (dollars per 1M tokens:
  `input`, `cached`, `output`, optional `cache_write`; `audio_minute`; `web_search` per
  1000 calls), `capabilities()`, `provider()`. Capability filtering is
  `AiCore::modelsWithCapability()`; do not keep a copy on the enum.
- **`AiRequestTypeContract`** (int-backed, stored in the ledger): `agentClass()` (null
  for types not answered by an agent), `failsBatch()`, `requiredCapabilities()`
  (`[AiCapability::Text]`, plus `WebSearch` for an agent that searches).

Labels, groups, recommended models and anything else the UI needs stay on the
application's enums, outside the contracts.

---

## 7. Extension points

Both are plain classes. Extend and bind in a service provider:

```php
$this->app->singleton(ModelAssignments::class, SettingsModelAssignments::class);
$this->app->bind(SystemPrompts::class, EditableSystemPrompts::class);
```

- `ModelAssignments`: `assigned()` returns null by default (no assignments);
  `assign()` throws until a subclass stores them. Where to store is the application's
  choice; the package depends on no storage package.
- `SystemPrompts::for(BaseAgent $agent)`: the agent's code by default. A subclass
  answers for some request types and returns `parent::for()` for the rest.

---

## 8. Batches and polling

`dispatch()` returns a batch id. Keep it on the server (a Livewire `#[Locked]`
property, the session) and poll `AiBatches::status($batchId)`:

- `BatchState::Running` until every row is done;
- `Failed` when a row whose type `failsBatch()` failed, with its `error`;
- `Completed` otherwise, with the rows. Build response DTOs with
  `AiResponseDto::fromRequest($row)`.

`payload` and `custom_prompt` are hidden from serialization of the returned rows; that
only guards against accidental `toArray()`/JSON, not access. Work that runs against a
placeholder subject before its real owner exists is moved with
`AiBatches::reattach($batchId, $owner)`.

---

## 9. Usage rows

The ledger stores usage as the SDK serializes it. Rewrite historic rows in a data
migration (chunked, with an exact `down()`), for example:

- `cache_creation_input_tokens` → `cache_write_input_tokens`; drop `total_tokens`;
- `{duration_seconds}` → `TranscriptionUsage` with `audio_seconds`;
- legacy `{prompt_tokens, completion_tokens}` → `input_tokens` / `output_tokens`;
- raw provider usage → keep `input_tokens`, `output_tokens`, map cached and reasoning
  details to `cache_read_input_tokens` / `reasoning_tokens`.

Then update every reader (statistics, exports): `total` is `input + output`. Skip rows
that are already in the target shape.

---

## 10. Queue and timeouts

`ProcessAiRequestJob` runs on `ai-core.queue.connection`, is never retried and times out
after `AiRequestExecutor::TIMEOUT_SECONDS`; the provider call itself has
`PROMPT_TIMEOUT_SECONDS`, classifications `CLASSIFICATION_TIMEOUT_SECONDS`. The
connection's `retry_after` (and any supervisor timeout) must sit above the job timeout;
keep a test that asserts it. Other AI jobs of the application should use the same
connection setting.

---

## 11. Errors and events

- `error_message` reaching the screen is safe (§3). Tests that asserted raw provider or
  host messages now expect `The AI request failed (ref #id).`
- `AiRequestDispatching` fires before the pending row exists; a listener that throws
  (a quota) stops the request. `AiRequestCompleted` / `AiRequestFailed` fire after the
  row is written; use them for analytics mirrors and alerts.
- The package reports through `report()`; it depends on no error tracker.

---

## 12. Tests

- `use InteractsWithAiCore;` in the base test case or an AI test concern.
- `seedAiCatalog(...$models)` syncs the catalogue and enables each model's provider with
  that model active. Enable the classification provider wherever classification must
  run.
- `fakeAgents([...])` gives every registered agent the same fake answers (the SDK's
  `Agent::fake()` per agent class still works).
- The SDK fake drops provider tool calls and always reports a finished answer; to test
  truncation or web search counting, hand the executor a built `AgentResponse` from a
  stub agent that overrides `prompt()`.

---

## 13. What leaves the application

Delete, after switching callers:

- the base agent and agent registry;
- the executor, the queued AI job, cost calculation and usage normalisation;
- traits or services that open pending rows, resolve the active provider or model,
  resolve the classification model, dispatch requests;
- the response DTO and the result-DTO base class;
- the capability enum and the process-status enum;
- the catalogue seeder logic (keep a seeder that calls the sync);
- batch reattachment and batch-status code (keep the UI polling glue).

Keep: the enums, agents, actions, classifications, result DTOs, transcription flow, the
tenant column and model subclass, the polling UI, the statistics UI.

---

## 14. Anti-patterns to remove

- Choosing "the first active provider" by hand, or calling an inactive provider because
  its key is configured.
- Returning null or skipping silently when no model is available.
- Storing provider or database exception text in `error_message`.
- Taking the tenant of a ledger row from the session instead of from its subject.
- Accepting a batch id from the browser.
- A public overridable hook on an abstract agent (a Pint `final_public_method_for_abstract_class`
  rule turns it final and breaks every subclass); override `agentState()` / `validate()`.
- Keeping a second capability filter or a second usage shape in the application.
- Depending on a settings package inside the AI layer for something only the
  application stores.
