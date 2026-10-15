# laravel-ai-core

Public Composer package `apavliukov/laravel-ai-core`, namespace `AlexPavliukov\AiCore`.

## What the package is

The shared AI layer above [laravel/ai](https://github.com/laravel/ai) for a Laravel host
application. The SDK owns the agent API and the transport; this package owns execution
and the ledger:

- a model catalogue: the host's provider and model enums implement the core contracts
  (`Contracts/`), carry prices and capabilities, and are synced into `ai_providers` /
  `ai_models` by `ai-core:sync`;
- the request ledger `ai_requests`, one row per provider call, with usage in the SDK's
  own shape (`TextUsage::toArray()`, plus `web_search_calls`) and the computed cost;
- `AiRequests`, the public service: dispatch to the queue, run inline, classify, record
  a call made outside an agent, open a pending row;
- the executor and `ProcessAiRequestJob` (`@internal`), cost accounting (`AiCost`),
  fingerprints and batch status (`AiBatches`);
- extension points as plain classes the host extends and binds: `SystemPrompts`,
  `ModelAssignments`.

Out of scope: UI, domain agents and actions, tenancy (the host adds it in its own model
subclass and migration), quotas and analytics (the host listens to the core events).

## Rules

- Code, comments, docs, commit messages and tests are in English.
- The package is public and host-agnostic: never name or describe a particular host
  application; say "the host application".
- No UI and no UI framework: the package gives data (`AiBatches::status()`), the host
  renders and polls it however it likes. Where the host stores things (settings, prompts, assignments) is the host's choice: the
  core ships a default and the host extends and binds its own class; never depend on
  a storage package. No dependency on any error tracker: report through `report()`.
- No interface with a single implementation: an extension point is a concrete class.
- Never commit, tag, push or publish without an explicit request.

## Commands

The package runs on the host PHP (8.4), not in Sail:

- tests: `vendor/bin/phpunit`
- static analysis: `vendor/bin/phpstan analyse` (level 9, `src` and `tests`, must be clean)
- formatting: `vendor/bin/pint --dirty --format agent`
- release: `yarn release:patch|minor|major` (commit-and-tag-version, Conventional Commits)

## Tests

Testbench with in-memory SQLite. Fixtures in `tests/Fixtures` stand in for the host:
`FakeProvider`, `FakeModel`, `FakeRequestType`, `FakeAgent`, `CannedAgent` (a hand-built
SDK response, for what the SDK fake cannot express: provider tool calls, truncation),
`Subject`. Every behaviour of the public surface gets a feature test there.
