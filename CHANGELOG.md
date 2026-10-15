# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

## [0.3.1](https://github.com/apavliukov/laravel-ai-core/compare/v0.3.0...v0.3.1) (2026-10-15)

## [0.3.0](https://github.com/apavliukov/laravel-ai-core/compare/v0.2.2...v0.3.0) (2026-10-15)

### ⚠ BREAKING CHANGES

* AiProviderContract requires static priority(AiCapability);
  dispatch() and run() throw instead of returning null; executor fail() takes
  no model.

### Features

* hard provider switch with capability priorities ([3d70ccf](https://github.com/apavliukov/laravel-ai-core/commit/3d70ccff8a56099ad61b53e525ad6dfd4ed9972c)), closes [#1](https://github.com/apavliukov/laravel-ai-core/issues/1) [#2](https://github.com/apavliukov/laravel-ai-core/issues/2) [#3](https://github.com/apavliukov/laravel-ai-core/issues/3) [#4](https://github.com/apavliukov/laravel-ai-core/issues/4)

## [0.2.2](https://github.com/apavliukov/laravel-ai-core/compare/v0.2.1...v0.2.2) (2026-10-15)

### Bug Fixes

* keep string user keys as batch owners ([3a34106](https://github.com/apavliukov/laravel-ai-core/commit/3a34106277efabb7deac48055a13fea33c6335cd))

## [0.2.1](https://github.com/apavliukov/laravel-ai-core/compare/v0.2.0...v0.2.1) (2026-10-15)

### Bug Fixes

* check the batch owner even without a signed-in user ([7bd9960](https://github.com/apavliukov/laravel-ai-core/commit/7bd996055dff3ada5822efe253cb3eb227eb2ddf))

## [0.2.0](https://github.com/apavliukov/laravel-ai-core/compare/v0.1.0...v0.2.0) (2026-10-15)

### ⚠ BREAKING CHANGES

* AlexPavliukov\AiCore\Livewire\HasAsyncAiRequest is removed.

### Features

* drop the Livewire polling trait and harden the ledger ([2d267ba](https://github.com/apavliukov/laravel-ai-core/commit/2d267baadf5fec62fe450b802ce45056b2badbbe))

## 0.1.0 (2026-10-15)

### Features

* initial laravel-ai-core ([85415b0](https://github.com/apavliukov/laravel-ai-core/commit/85415b0194330ab379c463b198c2a0b157798d92))
