<?php

declare(strict_types=1);

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(AiCore::table('providers'), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedBigInteger('active_model_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create(AiCore::table('models'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained(AiCore::table('providers'));
            $table->string('name', 100)->unique();
            $table->string('display_name', 100);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table(AiCore::table('providers'), function (Blueprint $table): void {
            $table->foreign('active_model_id')->references('id')->on(AiCore::table('models'))->nullOnDelete();
        });

        Schema::create(AiCore::table('requests'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_id')->constrained(AiCore::table('models'));
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->morphs('requestable');
            $table->unsignedSmallInteger('request_type')->index();
            $table->unsignedSmallInteger('status')->default(ProcessStatus::PENDING->value)->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->json('payload');
            $table->char('fingerprint', 64)->nullable();
            $table->json('response')->nullable();
            $table->json('tokens')->nullable();
            $table->decimal('api_cost', 10, 6)->nullable();
            $table->unsignedInteger('response_time')->nullable();
            $table->text('error_message')->nullable();
            $table->text('custom_prompt')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['ai_model_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(AiCore::table('requests'));

        Schema::table(AiCore::table('providers'), function (Blueprint $table): void {
            $table->dropForeign(['active_model_id']);
        });

        Schema::dropIfExists(AiCore::table('models'));
        Schema::dropIfExists(AiCore::table('providers'));
    }
};
