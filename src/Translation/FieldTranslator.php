<?php

namespace Inly\CoreWeb\Translation;

use Inly\CoreWeb\Client\CoreClient;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * Translates one field of a translation from its original's text through
 * Core's translation endpoint, in the shape the control panel's field holds.
 * A Bard field is translated node by node, so its sets stay where they are.
 */
final class FieldTranslator
{
    public const ENDPOINT = 'api/app/translations';

    public function __construct(private readonly CoreClient $client) {}

    /**
     * @param  string  $path  the control panel's path to the field, `page_builder.0.heading`
     * @param  mixed  $current  the field's value in the form, translated when the original's cannot be found
     */
    public function translate(Entry $translation, string $path, mixed $current): mixed
    {
        $origin = $translation->origin() ?? throw new FieldNotTranslatable(__('Only a translation can be translated from its original.'));
        [$field, $source] = $this->resolve($translation, $origin, explode('.', $path)) + [null, null];
        $source ??= $current;
        $type = $field?->fieldtype();

        if ($type instanceof Bard || (is_array($source) && $type === null)) {
            return $this->bard($type instanceof Bard ? $type : new Bard, $source, $current, $translation, $origin);
        }

        if ($type !== null && ! in_array($field->type(), ['text', 'textarea', 'markdown'], true)) {
            throw new FieldNotTranslatable(__('This field is not text.'));
        }

        if (! is_string($source) || blank($source)) {
            throw new FieldNotTranslatable(__('The original has no text here to translate.'));
        }

        return $this->send(['text' => $source], $translation, $origin, $field)['text'];
    }

    private function bard(Bard $bard, mixed $source, mixed $current, Entry $translation, Entry $origin): array
    {
        $refs = [];
        $nodes = $this->holdRefs($bard->preProcess($source), $refs);

        return $this->restoreRefs($this->translateNodes($bard, $nodes, $current, $translation, $origin), $refs);
    }

    /**
     * Swaps each `statamic://` link for a placeholder, since rendering a link
     * to HTML resolves it to the entry's URL and it would no longer follow a
     * rename once saved.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  list<string>  $refs
     * @return array<array-key, mixed>
     */
    private function holdRefs(array $nodes, array &$refs): array
    {
        foreach ($nodes as $key => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'link' && is_string($node['attrs']['href'] ?? null) && str_starts_with($node['attrs']['href'], 'statamic://')) {
                $refs[] = $node['attrs']['href'];
                $node['attrs']['href'] = '#core-web-ref-'.(count($refs) - 1);
            }

            $nodes[$key] = ($node['type'] ?? null) === 'set' ? $node : $this->holdRefs($node, $refs);
        }

        return $nodes;
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  list<string>  $refs
     * @return array<array-key, mixed>
     */
    private function restoreRefs(array $nodes, array $refs): array
    {
        foreach ($nodes as $key => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'link' && preg_match('/^#core-web-ref-(\d+)$/', (string) ($node['attrs']['href'] ?? ''), $match) && isset($refs[(int) $match[1]])) {
                $node['attrs']['href'] = $refs[(int) $match[1]];
            }

            $nodes[$key] = ($node['type'] ?? null) === 'set' ? $node : $this->restoreRefs($node, $refs);
        }

        return $nodes;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function translateNodes(Bard $bard, array $nodes, mixed $current, Entry $translation, Entry $origin): array
    {
        if ($bard->config('inline')) {
            $html = (new Augmentor($bard))->withStatamicImageUrls()->renderProsemirrorToHtml(['type' => 'doc', 'content' => $nodes]);

            return $bard->preProcess($this->send(['html' => $html], $translation, $origin, null)['html']);
        }

        $texts = [];

        foreach ($nodes as $index => $node) {
            if (($node['type'] ?? null) !== 'set') {
                $texts["n{$index}"] = (new Augmentor($bard))->withStatamicImageUrls()->renderProsemirrorToHtml(['type' => 'doc', 'content' => [$node]]);
            }
        }

        $translated = $texts === [] ? [] : $this->send(array_filter($texts, 'filled'), $translation, $origin, null);
        $ownSets = collect(is_array($current) ? $current : [])->where('type', 'set')->keyBy(fn (array $node) => $node['attrs']['id'] ?? null);
        $result = [];

        foreach ($nodes as $index => $node) {
            if (($node['type'] ?? null) === 'set') {
                $result[] = $ownSets->get($node['attrs']['id'] ?? null, $node);
            } elseif (isset($translated["n{$index}"])) {
                array_push($result, ...$bard->preProcess($translated["n{$index}"]));
            } else {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    private function send(array $texts, Entry $translation, Entry $origin, ?Field $field): array
    {
        if (! $this->client->configured()) {
            throw new FieldNotTranslatable(__('This site is not connected to Inly Core.'));
        }

        if ($origin->site()->locale() === $translation->site()->locale()) {
            throw new FieldNotTranslatable(__('The original and this page are in the same language.'));
        }

        $response = $this->client->postResponse(self::ENDPOINT, [
            'source_locale' => $origin->site()->locale(),
            'target_locale' => $translation->site()->locale(),
            'context' => $field?->display() ? __('A website page\'s :field field.', ['field' => $field->display()]) : __('Text on a website page.'),
            'texts' => collect($texts)->map(fn (string $text, string $key) => ['key' => $key, 'text' => $text])->values()->all(),
        ], timeout: 60);

        if ($response->status() === 403) {
            throw new FieldNotTranslatable(__('Inly Core does not let this site translate. Grant its app client "Translate content with AI".'));
        }

        // Core's own refusals (too much text, today's allowance used, an incomplete batch) say what to do.
        if (in_array($response->status(), [422, 429, 502], true) && is_string($response->json('message'))) {
            throw new FieldNotTranslatable($response->json('message'));
        }

        if ($response->failed()) {
            throw new RuntimeException("Core translation failed [{$response->status()}].");
        }

        $translations = (array) $response->json('translations', []);

        foreach (array_keys($texts) as $key) {
            if (! is_string($translations[$key] ?? null)) {
                throw new FieldNotTranslatable(__('The translation came back incomplete. Try again.'));
            }
        }

        return $translations;
    }

    /**
     * The field at a control panel path and the original's value there. The
     * translation's rows are mapped to the original's by id, so a page with
     * its own blocks still finds the text it translates.
     *
     * @param  list<string>  $segments
     * @return array{0: ?Field, 1: mixed}
     */
    private function resolve(Entry $translation, Entry $origin, array $segments): array
    {
        $handle = array_shift($segments);
        $field = $translation->blueprint()->field($handle);
        $original = $origin->value($handle);
        $own = $translation->has($handle) ? $translation->get($handle) : $original;

        while ($segments !== [] && $field !== null) {
            $type = $field->fieldtype();
            $segment = array_shift($segments);

            if ($type instanceof Replicator && ctype_digit($segment)) {
                $row = is_array($own) ? ($own[(int) $segment] ?? null) : null;
                $match = $this->match(is_array($original) ? $original : [], $row, (int) $segment);

                if ($type instanceof Bard) {
                    $values = $match['attrs']['values'] ?? null;
                    $segments = array_values(array_diff($segments, ['attrs', 'values']));
                    [$own, $original] = [$row['attrs']['values'] ?? null, $values];
                    $set = $values['type'] ?? null;
                } else {
                    [$own, $original] = [$row, $match];
                    $set = $match['type'] ?? null;
                }

                $next = array_shift($segments);
                $field = $set === null || $next === null ? null : $type->fields($set)->get($next);
                [$own, $original] = [$own[$next] ?? null, $original[$next] ?? null];
            } elseif ($type instanceof Grid && ctype_digit($segment)) {
                $row = is_array($own) ? ($own[(int) $segment] ?? null) : null;
                $match = $this->match(is_array($original) ? $original : [], $row, (int) $segment);
                $next = array_shift($segments);
                $field = $next === null ? null : $type->fields()->get($next);
                [$own, $original] = [$row[$next] ?? null, $match[$next] ?? null];
            } elseif ($type instanceof Group) {
                $field = $type->fields()->get($segment);
                [$own, $original] = [$own[$segment] ?? null, $original[$segment] ?? null];
            } else {
                return [null, null];
            }
        }

        return $segments === [] ? [$field, $original] : [null, null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    private function match(array $rows, ?array $row, int $index): ?array
    {
        $id = $row['id'] ?? $row['_id'] ?? $row['attrs']['id'] ?? null;

        if (blank($id)) {
            return $rows[$index] ?? null;
        }

        foreach ($rows as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? $candidate['attrs']['id'] ?? null) === $id) {
                return $candidate;
            }
        }

        return null;
    }
}
