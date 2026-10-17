<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * The site's own form classes (core-web.forms.renderers) and the report of
 * what they render that it sends Core (PUT /api/forms/renderings). Core
 * keeps one list per app client and replaces it with every report, so a
 * renderer the site drops stops guarding its form on the next report.
 */
final class FormRenderings
{
    public const PATH = '/api/forms/renderings';

    public const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(private readonly CoreClient $core) {}

    /**
     * The renderer classes by name.
     *
     * @return array<string, class-string<RendersCoreForm>>
     */
    public static function renderers(): array
    {
        $renderers = [];

        foreach ((array) config('core-web.forms.renderers', []) as $name => $class) {
            $name = (string) $name;

            if (preg_match('~^'.self::NAME_PATTERN.'$~', $name) !== 1) {
                throw new InvalidArgumentException("core-web.forms.renderers: [{$name}] is not a renderer name; use a lowercase slug (a-z, 0-9, single hyphens).");
            }

            if (! is_string($class) || ! is_subclass_of($class, RendersCoreForm::class)) {
                throw new InvalidArgumentException("core-web.forms.renderers: [{$name}] must name a class implementing ".RendersCoreForm::class.'.');
            }

            $renderers[$name] = $class;
        }

        return $renderers;
    }

    /**
     * What each renderer renders, against the forms as Core lists them: a
     * renderer whose form or field Core does not have is left out, and
     * logged, since there is nothing in Core for it to guard.
     *
     * @param  array<string, array<string, mixed>>  $forms  CoreForms documents, by key
     * @return list<array{form: string, renderer: string, fields: list<array{name: string, type: string, options?: list<string>}>}>
     */
    public static function payload(array $forms): array
    {
        $renderings = [];

        foreach (self::renderers() as $name => $class) {
            $form = $forms[$class::coreForm()] ?? null;
            $declared = $form['definition'] ?? $form['last_definition'] ?? null;
            $definition = is_array($declared) ? new FormDefinition($declared) : null;

            if ($form === null || $definition === null) {
                Log::warning('core-web forms: a renderer\'s Core form is unknown, or off since before the site read it', ['renderer' => $name, 'form' => $class::coreForm()]);

                continue;
            }

            $fields = [];

            foreach ($class::renders() as $key => $value) {
                $field = is_int($key) ? (string) $value : (string) $key;
                $declaredField = $definition->field($field);

                if ($declaredField === null) {
                    Log::warning('core-web forms: a renderer renders a field its Core form lacks', ['renderer' => $name, 'field' => $field]);

                    continue;
                }

                $fields[] = [
                    'name' => $field,
                    'type' => FormDefinition::typeOf($declaredField),
                    ...(is_array($value) ? ['options' => array_values(array_map('strval', $value))] : []),
                ];
            }

            $renderings[] = ['form' => (string) $form['uuid'], 'renderer' => $name, 'fields' => $fields];
        }

        return $renderings;
    }

    /**
     * Sends Core the report. Core answers which renderings it took and which
     * it refused (a form not linked to this site's app client).
     *
     * @param  array<string, array<string, mixed>>|null  $forms  null reads the forms snapshot
     * @return array{accepted: list<array<string, mixed>>, refused: list<array<string, mixed>>}
     */
    public function report(?array $forms = null): array
    {
        if (! $this->core->configured()) {
            throw new RuntimeException('core-web forms: Core is not configured (core-web.client).');
        }

        $forms ??= app(SnapshotStore::class)->documents(app(SnapshotRegistry::class)->get(CoreForms::TYPE));
        $response = $this->core->sendResponse('put', self::PATH, ['renderings' => self::payload($forms)], timeout: 10);

        if ($response->failed()) {
            throw new RuntimeException("Core refused the form renderings [{$response->status()}]: ".$response->body());
        }

        $answer = [
            'accepted' => array_values((array) $response->json('accepted', [])),
            'refused' => array_values((array) $response->json('refused', [])),
        ];

        if ($answer['refused'] !== []) {
            Log::warning('core-web forms: Core refused renderings', ['refused' => $answer['refused']]);
        }

        return $answer;
    }
}
