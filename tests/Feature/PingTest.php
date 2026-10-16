<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Content\CoreEnvelope;
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

    public function test_a_ping_sealed_with_cores_key_opens_without_the_shared_secret(): void
    {
        $this->fakeCore();
        config(['core-web.ping.secret' => '']);
        $this->registry()->register(Snapshot::make('things'), pings: ['thing']);

        $this->receiveSealedPing(['type' => 'thing', 'id' => 17, 'public_id' => 'ABC', 'action' => 'deleted'])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => [$job->type, $job->id, $job->action, $job->key] === ['things', '17', 'deleted', 'ABC']);
    }

    public function test_a_sealed_ping_for_another_purpose_audience_or_body_is_refused(): void
    {
        $this->fakeCore();
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);
        $ping = ['type' => 'things', 'id' => 'all', 'action' => 'saved'];

        $this->callSealed('POST', '/api/core/objects', CoreEnvelope::READ, $ping)->assertForbidden();
        $this->receiveSealedPing($ping, ['aud' => 'another-site'])->assertForbidden();
        $this->receiveSealedPing($ping, ['body_sha256' => hash('sha256', '{"type":"things","id":"all","action":"deleted"}')])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_sealed_ping_opens_once(): void
    {
        $this->fakeCore();
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);
        [$server, $body] = $this->sealedServer('POST', '/api/core/objects', CoreEnvelope::OBJECTS, ['type' => 'things', 'id' => 'all', 'action' => 'saved']);

        $this->call('POST', '/api/core/objects', [], [], [], $server, $body)->assertStatus(202);
        $this->call('POST', '/api/core/objects', [], [], [], $server, $body)->assertForbidden()->assertExactJson(['error' => 'envelope_refused', 'reason' => 'replayed']);

        Queue::assertPushed(RefreshSnapshotJob::class, 1);
    }

    public function test_a_sealed_body_with_slashes_and_non_ascii_opens_as_the_exact_bytes_sealed(): void
    {
        $this->fakeCore();
        $this->registry()->register(Snapshot::make('things'), pings: ['thing']);

        $this->receiveSealedPing(['type' => 'thing', 'id' => '7', 'public_id' => 'båtar/ä-7'])->assertStatus(202);

        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->key === 'båtar/ä-7');
    }

    public function test_a_refused_sealed_ping_says_why_in_json_without_an_accept_header(): void
    {
        $this->fakeCore();
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);
        [$server, $body] = $this->sealedServer('POST', '/api/core/objects', CoreEnvelope::OBJECTS, ['type' => 'things', 'id' => 'all', 'action' => 'saved'], ['aud' => 'another-site']);
        unset($server['HTTP_ACCEPT']);

        $this->call('POST', '/api/core/objects', [], [], [], $server, $body)
            ->assertForbidden()
            ->assertExactJson(['error' => 'envelope_refused', 'reason' => 'audience'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_sealed_body_that_is_no_object_is_a_422(): void
    {
        $this->fakeCore();
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->callSealed('POST', '/api/core/objects', CoreEnvelope::OBJECTS, '["things"]')->assertStatus(422)->assertExactJson(['error' => 'invalid_ping']);
    }

    public function test_a_sealed_ping_to_a_site_with_no_client_id_is_refused(): void
    {
        $this->fakeCore();
        config(['core-web.client.client_id' => '']);
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->receiveSealedPing(['type' => 'things', 'id' => 'all'])->assertForbidden()->assertExactJson(['error' => 'envelope_refused']);

        Queue::assertNothingPushed();
    }

    public function test_a_sealed_ping_opens_with_content_switched_off(): void
    {
        $this->fakeCore();
        config(['core-web.features.content' => false]);
        $this->registry()->register(Snapshot::make('things'), pings: ['things']);

        $this->receiveSealedPing(['type' => 'things', 'id' => 'all'])->assertStatus(202);
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
