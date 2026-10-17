<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use RuntimeException;
use Throwable;

/**
 * The Core forms the site's own form classes post to (core-web.forms.renderers),
 * kept as a snapshot: Core's form list (`core/form`, the client's
 * `view_forms` scope) keyed by each form's key, with the public definition
 * of every form a renderer names. Core pings `forms` on every save of a
 * form, so a changed field reaches the site's markup with no deploy.
 */
final class CoreForms
{
    public const TYPE = 'core-web-forms';

    public const PING = 'forms';

    public const OBJECT = 'core/form';

    public function __construct(
        private readonly Snapshot $snapshot,
        private readonly SnapshotStore $store,
    ) {}

    public static function fromContainer(): self
    {
        return new self(app(SnapshotRegistry::class)->get(self::TYPE), app(SnapshotStore::class));
    }

    public static function snapshot(): Snapshot
    {
        return Snapshot::make(self::TYPE)
            ->version(1)
            // A form changes when an admin edits it, and Core pings each save: the schedule is only the net under a lost ping.
            ->ttl(fresh: 129600, stale: 2592000, retry: 60)
            ->pull(fn (): array => self::pull(CoreClient::fromConfig()))
            ->onChange(function (?string $partition, array $documents): void {
                try {
                    app(FormRenderings::class)->report($documents);
                } catch (Throwable $e) {
                    Log::warning('core-web forms: the renderings report failed', ['error' => $e->getMessage()]);
                }
            });
    }

    /**
     * Every form row by key, with the definition of each one a renderer
     * names. A Core that lists no forms (404: its forms are off) has none.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pull(CoreClient $core): array
    {
        if (! $core->configured()) {
            return [];
        }

        $rendered = array_flip(array_map(fn (string $class) => $class::coreForm(), FormRenderings::renderers()));
        $forms = [];
        $rows = 0;

        try {
            foreach ($core->objects(self::OBJECT, 100) as $listed) {
                $rows++;
                $form = self::document((array) ($listed['data']['attributes'] ?? []));

                if ($form !== null) {
                    $forms[$form['key']] = $form;
                }
            }
        } catch (RuntimeException $e) {
            if ($rows === 0 && str_contains($e->getMessage(), '[404]')) {
                return [];
            }

            throw $e;
        }

        foreach ($forms as $key => $form) {
            if (! isset($rendered[$key])) {
                continue;
            }

            $definition = $form['enabled'] ? self::fetchDefinition($core, $form) : null;
            $forms[$key]['definition'] = $definition;
            $forms[$key]['last_definition'] = $definition ?? self::lastDefinition($key);

            if ($definition !== null) {
                self::keepDefinition($key, $definition);
                self::logUnknownTypes($key, new FormDefinition($definition));
            }

            if (! $form['accepts_server']) {
                Log::warning('core-web forms: a rendered Core form does not take server entries; switch them on in Core', ['form' => $key]);
            }
        }

        ksort($forms);

        return $forms;
    }

    /**
     * One row of Core's form list, or null without a key or a uuid.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{uuid: string, key: string, name: string, site_key: string, accepts_server: bool, enabled: bool, definition: ?array<string, mixed>, last_definition: ?array<string, mixed>}|null
     */
    public static function document(array $attributes): ?array
    {
        $key = trim((string) ($attributes['slug'] ?? ''));
        $uuid = trim((string) ($attributes['uuid'] ?? ''));

        if ($key === '' || $uuid === '') {
            return null;
        }

        return [
            'uuid' => $uuid,
            'key' => $key,
            'name' => trim((string) ($attributes['name'] ?? '')) ?: $key,
            'site_key' => (string) ($attributes['site_key'] ?? ''),
            'accepts_server' => (bool) ($attributes['accepts_server'] ?? false),
            'enabled' => (bool) ($attributes['enabled'] ?? false),
            'definition' => null,
            'last_definition' => null,
        ];
    }

    /**
     * A form's public definition, read with its site key. A form Core turned
     * off since it was listed has none; any other failure fails the pull, so
     * the last good copy is served instead of a form without fields.
     *
     * @param  array{uuid: string, site_key: string}  $form
     * @return array<string, mixed>|null
     */
    private static function fetchDefinition(CoreClient $core, array $form): ?array
    {
        $response = CoreClient::request()
            ->acceptJson()
            ->connectTimeout((int) config('core-web.forms.timeouts.connect', 3))
            ->timeout((int) config('core-web.forms.timeouts.definition', 5))
            ->get($core->baseUrl().'/api/forms/'.rawurlencode($form['uuid']).'/definition', [
                'sk' => $form['site_key'],
                'locale' => app()->getLocale(),
            ]);

        if ($response->status() === 403) {
            return null;
        }

        if ($response->failed() || ! is_array($response->json('form'))) {
            throw new RuntimeException("Core form definition error [{$response->status()}] for form [{$form['uuid']}]");
        }

        return (array) $response->json();
    }

    /**
     * The last definition read of a form, kept apart from the snapshot: a
     * form Core switched off serves none, and its renderings must still be
     * reported, or Core stops guarding its fields while it is off.
     *
     * @return array<string, mixed>|null
     */
    private static function lastDefinition(string $key): ?array
    {
        $definition = SnapshotStore::repository()->get(self::TYPE.':last-definition:'.$key);

        return is_array($definition) ? $definition : null;
    }

    /** @param  array<string, mixed>  $definition */
    private static function keepDefinition(string $key, array $definition): void
    {
        SnapshotStore::repository()->forever(self::TYPE.':last-definition:'.$key, $definition);
    }

    private static function logUnknownTypes(string $key, FormDefinition $definition): void
    {
        foreach ($definition->fields() as $name => $field) {
            if (! FormDefinition::knows($field)) {
                Log::notice('core-web forms: a field type core-web does not know renders as text', ['form' => $key, 'field' => $name, 'type' => FormDefinition::typeOf($field)]);
            }
        }
    }

    /** @return array<string, mixed>|null the form's row, by key */
    public function form(string $key): ?array
    {
        return $this->store->documents($this->snapshot)[$key] ?? null;
    }

    /** A rendered form's definition, or null while Core has none for it (unknown, off, or not yet pulled). */
    public function definition(string $key): ?FormDefinition
    {
        $definition = $this->form($key)['definition'] ?? null;

        return is_array($definition) ? new FormDefinition($definition) : null;
    }
}
