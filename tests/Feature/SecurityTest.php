<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Batches\AiBatches;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Jobs\ProcessAiRequestJob;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\Fixtures\Subject;
use AlexPavliukov\AiCore\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

final class SecurityTest extends TestCase
{
    public function test_a_raw_exception_never_reaches_the_ledger_but_a_provider_error_does(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $executor = resolve(AiRequestExecutor::class);

        FakeAgent::fake([static fn (): never => throw new RuntimeException('SQLSTATE[42S02] select * from secrets')]);
        $leaky = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $executor->execute($leaky, new FakeAgent, FakeModel::Small, 'Analyze.');
        $this->assertSame("The AI request failed (ref #{$leaky->id}).", $leaky->refresh()->error_message);

        FakeAgent::fake([static fn (): never => throw RateLimitedException::forProvider('openai')]);
        $limited = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $executor->execute($limited, new FakeAgent, FakeModel::Small, 'Analyze.');
        $this->assertStringContainsString('openai', (string) $limited->refresh()->error_message);
    }

    public function test_a_member_cannot_join_reattach_or_read_the_prompts_of_someone_elses_batch(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $owner = $this->user('owner@example.com');
        $intruder = $this->user('intruder@example.com');

        $this->actingAs($owner);
        $mine = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'batch-1', 'Secret wish');
        $mine->update(['status' => ProcessStatus::COMPLETED, 'payload' => ['input' => 'Secret prompt']]);

        $this->assertArrayNotHasKey('payload', resolve(AiBatches::class)->status('batch-1')->requests->first()?->toArray() ?? []);

        $this->actingAs($intruder);
        $theirs = $this->subject();
        resolve(AiBatches::class)->reattach('batch-1', $theirs);
        $this->assertNotSame($theirs->getKey(), $mine->refresh()->requestable_id);

        $this->expectException(InvalidArgumentException::class);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'batch-1');
    }

    public function test_without_a_signed_in_member_only_ownerless_rows_are_joined_or_moved(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $memberId = DB::table('users')->insertGetId(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'secret']);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'members', userId: $memberId);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'system');

        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Check, 'system');
        $this->assertSame(2, AiRequest::query()->where('batch_id', 'system')->count());

        $guestsRecord = $this->subject();
        resolve(AiBatches::class)->reattach('members', $guestsRecord);
        $this->assertSame(0, AiRequest::query()->where('requestable_id', $guestsRecord->getKey())->count());

        $this->expectException(InvalidArgumentException::class);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'members');
    }

    public function test_a_string_user_key_is_an_owner_not_nobody(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'system');
        $this->actingAs(new class extends User
        {
            public function getAuthIdentifier(): string
            {
                return '01JAXQ6M3V8S0Q5N1C2D3E4F5G';
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'system');
    }

    public function test_an_applier_must_implement_the_contract_and_the_queued_prompt_is_encrypted(): void
    {
        $this->seedAiCatalog(FakeModel::Small);

        $this->assertInstanceOf(ShouldBeEncrypted::class, new ProcessAiRequestJob(1, FakeRequestType::Analysis, 'Analyze.'));

        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type (a wrong applier, on purpose)
        $this->aiRequests()->dispatch(FakeRequestType::Analysis, $this->subject(), 'Analyze.', applier: Subject::class);
    }

    private function aiRequests(): AiRequests
    {
        return resolve(AiRequests::class);
    }

    private function user(string $email): User
    {
        $user = new User;
        $user->forceFill(['name' => $email, 'email' => $email, 'password' => 'secret'])->save();

        return $user;
    }
}
