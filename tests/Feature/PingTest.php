<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Snapshots\RefreshSnapshotJob;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** POST /api/core/objects: Core's signed ping queues one refresh per snapshot its type refreshes. */
class PingTest extends TestCase
{
    use FakesCore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withPingSecret();
        Queue::fake();
    }

    private function registry(): SnapshotRegistry
    {
        return app(SnapshotRegistry::class);
    }

    public function test_a_registered_ping_queues_one_refresh_per_type(): void
    {
        $this->registry()->register(Snapshot::make('things'), pings: ['things', 'thing']);

        $this->receivePing(['type' => 'things', 'id' => 'all'])->assertStatus(202)->assertExactJson(['status' => 'accepted']);
        $this->receivePing(['type' => 'thing', 'id' => 'all'])->assertStatus(202);
        $this->receivePing(['type' => 'unknown', 'id' => 'all'])->assertStatus(422);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'things' && $job->uniqueId() === 'things');
        Queue::assertPushed(RefreshSnapshotJob::class, 1);
    }

    public function test_a_ping_naming_one_object_carries_its_id_action_and_public_id(): void
    {
        $this->registry()->register(Snapshot::make('things'), pings: ['thing']);

        $this->receivePing(['type' => 'thing', 'id' => 17, 'public_id' => 'ABC', 'action' => 'deleted'])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => [$job->type, $job->id, $job->action, $job->key] === ['things', '17', 'deleted', 'ABC']);
    }

    public function test_one_ping_type_refreshes_every_snapshot_that_registers_it(): void
    {
        $this->registry()
            ->register(Snapshot::make('cars'), pings: ['model'])
            ->register(Snapshot::make('boats'), pings: ['model'], wholePings: ['category']);

        $this->receivePing(['type' => 'model', 'id' => '5'])->assertStatus(202);
        $this->receivePing(['type' => 'category', 'id' => '9'])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'cars' && $job->id === '5');
        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'boats' && $job->id === '5');
        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'boats' && $job->id === 'all');
        Queue::assertPushed(RefreshSnapshotJob::class, 3);
    }

    public function test_a_bulk_ping_with_id_zero_refreshes_the_whole_snapshot(): void
    {
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->receivePing(['type' => 'things', 'id' => 0])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->id === 'all' && $job->uniqueId() === 'things');
    }

    public function test_an_idle_ping_type_is_accepted_and_queues_nothing(): void
    {
        $this->registry()->idle(['category', 'categories']);

        $this->receivePing(['type' => 'category', 'id' => '12', 'public_id' => 'c0ffee00-0000-4000-8000-000000000000'])->assertStatus(202);
        $this->receivePing(['type' => 'categories', 'id' => 'all'])->assertStatus(202);
        $this->receivePing(['type' => 'something-else', 'id' => 'all'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_an_idle_ping_type_a_snapshot_registers_refreshes_it(): void
    {
        $this->registry()->idle(['category'])->register(Snapshot::make('catalog'), wholePings: ['category']);

        $this->assertSame([], $this->registry()->idleTypes());
        $this->receivePing(['type' => 'category', 'id' => '12'])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'catalog' && $job->id === 'all');
    }

    public function test_a_custom_job_is_what_the_ping_queues(): void
    {
        $this->registry()->register(Snapshot::make('stock'), pings: ['ads'], job: fn (string $id, string $action) => new CustomRefreshJob($id, $action));

        $this->receivePing(['type' => 'ads', 'id' => 'all'])->assertStatus(202);

        Queue::assertPushed(CustomRefreshJob::class, fn (CustomRefreshJob $job) => $job->type === 'stock');
    }

    public function test_an_unsigned_wrongly_signed_or_secretless_ping_is_refused(): void
    {
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->postJson('/api/core/objects', [])->assertStatus(422);
        $this->postJson('/api/core/objects', ['payload' => 'x', 'signature' => 'y'])->assertForbidden();

        $ping = $this->signedPing(['type' => 'things', 'id' => 'all']);
        config(['core-web.ping.secret' => '']);
        $this->postJson('/api/core/objects', $ping)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_endpoint_needs_no_csrf_token_and_is_named(): void
    {
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->assertSame(url('api/core/objects'), route('api.core.objects'));
        $this->post('/api/core/objects', $this->signedPing(['type' => 'things', 'id' => 'all']), ['Accept' => 'application/json'])->assertStatus(202);
    }
}

class CustomRefreshJob extends RefreshSnapshotJob
{
    public function __construct(string $id = 'all', string $action = 'saved')
    {
        parent::__construct('stock', $id, $action);
    }
}
