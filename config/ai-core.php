<?php

declare(strict_types=1);

use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Models\AiRequest;

return [

    /*
    | Eloquent classes behind the catalogue and the ledger. Point `request` at
    | your own subclass to add tenancy or display accessors.
    */
    'models' => [
        'provider' => AiProvider::class,
        'model' => AiModel::class,
        'request' => AiRequest::class,
    ],

    /*
    | Table names of the catalogue and the ledger. Read by the models and the
    | published migration; change them before migrating.
    */
    'table_names' => [
        'providers' => 'ai_providers',
        'models' => 'ai_models',
        'requests' => 'ai_requests',
    ],

    /*
    | The project's enums implementing AiProviderContract, AiModelContract and
    | AiRequestTypeContract. Required; read on first use, not at boot.
    */
    'enums' => [
        'provider' => null,
        'model' => null,
        'request_type' => null,
    ],

    /*
    | The line a member's Custom Prompt is sent under, after the request
    | message. It is part of what a request asks, so changing it changes every
    | stored fingerprint once.
    */
    'custom_prompt_heading' => 'ADDITIONAL INSTRUCTIONS (where they contradict the rules above, follow these instead, except the response format and any numbers set for this run):',

    /*
    | Where ProcessAiRequestJob runs. Null means the default connection.
    | The connection's retry_after must exceed AiRequestExecutor::TIMEOUT_SECONDS.
    */
    'queue' => [
        'connection' => env('AI_QUEUE_CONNECTION'),
    ],

];
