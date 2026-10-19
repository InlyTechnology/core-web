<?php

namespace Inly\CoreWeb\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Content\EnvelopeRefused;
use Inly\CoreWeb\Content\WrongEnvironment;
use Inly\CoreWeb\Testing\CoreEnvelopeSealer;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The test vectors of the Statamic connector API v1, the `test-vectors.json`
 * block of inly-core's API page (docs/learn/website-content/protocol-v1.md as
 * of inly-core v31.1.0, since renamed statamic-connector-api.md), copied
 * verbatim into tests/fixtures. Update the fixture
 * from that block whenever the API changes.
 */
class ProtocolTestVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/statamic-connector-api-v1-test-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The vectors under Core's own key, as the fixture gives them.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function openVectors(): iterable
    {
        foreach (self::vectors()['open'] as $vector) {
            if (isset($vector['core_public_keys'])) {
                yield $vector['name'] => [$vector];
            }
        }
    }

    public function test_the_fixture_holds_core_key_vectors(): void
    {
        $this->assertGreaterThanOrEqual(16, iterator_count(self::openVectors()));
    }

    /**
     * The shared-secret vectors, which a site never receives, re-sealed with
     * the test key so their structural refusals run against Core's key too:
     * the claims kept byte for byte, a purpose added where they are an
     * object, and an environment for a write. The vectors about the secret
     * itself (a rotation's grace window, another secret) say nothing about
     * Core's key and are left out.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function resealedVectors(): iterable
    {
        $sealer = CoreEnvelopeSealer::test();
        $document = $sealer->document();

        foreach (self::vectors()['open'] as $vector) {
            if (isset($vector['core_public_keys']) || isset($vector['previous_secret']) || $vector['expect'] === EnvelopeRefused::SIGNATURE) {
                continue;
            }

            $original = $vector['headers'];
            // Decoded as objects, so an empty query stays `{}` and the claims keep their order.
            $claims = json_decode(base64_decode($original[CoreEnvelope::PAYLOAD_HEADER], true), false, flags: JSON_THROW_ON_ERROR);
            $purpose = $vector['request']['method'] === 'GET' ? CoreEnvelope::READ : CoreEnvelope::DRAFT;

            if ($claims instanceof stdClass) {
                $claims->purpose = $purpose;
                $claims->environment = 'production';
            }

            $headers = $sealer->seal((array) $claims);

            foreach ([CoreEnvelope::PAYLOAD_HEADER, CoreEnvelope::SIGNATURE_HEADER] as $header) {
                if (! isset($original[$header])) {
                    unset($headers[$header]);
                }
            }

            yield $vector['name'] => [[
                ...$vector,
                'core_public_keys' => $document,
                'environment' => 'production',
                'headers' => $headers,
                'purpose' => $purpose,
            ]];
        }
    }

    public function test_the_fixture_holds_shared_secret_vectors_to_reseal(): void
    {
        $this->assertGreaterThanOrEqual(20, iterator_count(self::resealedVectors()));
    }

    /** @param  array<string, mixed>  $vector */
    #[DataProvider('openVectors')]
    #[DataProvider('resealedVectors')]
    public function test_an_open_vector(array $vector): void
    {
        $this->travelTo(Carbon::createFromTimestamp($vector['now']));

        // The keys are the vector's document alone: with no Core to read, a fetch for an unknown key id finds nothing more.
        $keys = new CoreSigningKeys('', new Repository(new ArrayStore), 'keys');
        $keys->remember(CoreSigningKeys::parse($vector['core_public_keys']));

        $envelope = new CoreEnvelope($keys, $vector['audience'], $vector['environment'] ?? 'production', new Repository(new ArrayStore));
        $headers = $vector['headers'];
        $answer = null;

        for ($presented = 0; $presented < ($vector['present'] ?? 1); $presented++) {
            try {
                $envelope->openTarget(
                    $vector['request']['method'],
                    $vector['request']['uri'],
                    $vector['request']['body'],
                    $headers[CoreEnvelope::PAYLOAD_HEADER] ?? null,
                    $headers[CoreEnvelope::SIGNATURE_HEADER] ?? null,
                    $headers[CoreEnvelope::KEY_ID_HEADER] ?? null,
                    $vector['purpose'],
                );
                $answer = 'accept';
            } catch (EnvelopeRefused $e) {
                $answer = $e->reason;
            } catch (WrongEnvironment) {
                $answer = 'wrong_environment';
            }

            if ($presented < ($vector['present'] ?? 1) - 1) {
                $this->assertSame('accept', $answer, 'an earlier presentation');
            }
        }

        $this->assertSame($vector['expect'], $answer);
    }

    public function test_the_sealer_seals_as_core_does(): void
    {
        $sealed = 0;

        // Decoded as objects, so an empty query stays `{}` and the claims keep their order.
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/statamic-connector-api-v1-test-vectors.json'), false, flags: JSON_THROW_ON_ERROR);

        foreach ($vectors->seal as $vector) {
            if (! isset($vector->core_seed)) {
                continue;
            }

            $headers = (new CoreEnvelopeSealer((string) hex2bin($vector->core_seed)))->seal((array) $vector->claims);

            $this->assertSame($vector->payload, $headers[CoreEnvelope::PAYLOAD_HEADER], $vector->name);
            $this->assertSame($vector->signature, $headers[CoreEnvelope::SIGNATURE_HEADER], $vector->name);
            $this->assertSame($vector->key_id, $headers[CoreEnvelope::KEY_ID_HEADER], $vector->name);
            $sealed++;
        }

        $this->assertGreaterThan(0, $sealed);
    }
}
