<?php

namespace Inly\CoreWeb\Forms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A Core form's public definition (version 1, as Core's definition endpoint
 * serves it), read for a site's own form markup: its fields, which of them
 * a visitor must fill given the values so far, and the rules a submission
 * is checked with before it goes to Core. Conditions are read as Core reads
 * them (VisibleWhen), so a field Core skips is skipped here too.
 */
final class FormDefinition
{
    /** The definition version this class reads; a newer one is still read field by field. */
    public const VERSION = 1;

    /** Field types this class knows; any other renders as a text input and is logged. */
    public const TYPES = ['string', 'text', 'number', 'boolean', 'email', 'url', 'phone', 'date', 'license_plate', 'enum', 'multi_enum', 'file', 'image'];

    /**
     * The keys of a definition field this class and the default markup know:
     * read, or deliberately left to the site's own markup (width, slots).
     */
    public const KEYS = [
        'name', 'type', 'label', 'description', 'placeholder', 'required', 'hidden', 'source', 'default', 'options',
        'autocomplete', 'display', 'accept', 'maxKb', 'multiple', 'maxFiles', 'minFiles', 'slots', 'maxSide', 'capture',
        'withTime', 'minLength', 'maxLength', 'min', 'max', 'earliest', 'latest', 'width', 'visibleWhen', 'section', 'step',
    ];

    /** The privacy box Core adds to a form whose notice is a checkbox. */
    public const PRIVACY_FIELD = '_privacy_policy';

    /** @var array<string, array<string, mixed>> */
    private array $fields = [];

    /** @param  array<string, mixed>  $definition  the endpoint's whole answer */
    public function __construct(public readonly array $definition)
    {
        foreach ((array) ($definition['form']['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['name'] ?? null) && $field['name'] !== '') {
                $this->fields[$field['name']] = $field;
            }
        }
    }

    public function version(): int
    {
        return (int) ($this->definition['version'] ?? 0);
    }

    /** Core's form uuid. */
    public function id(): string
    {
        return (string) ($this->definition['form']['id'] ?? '');
    }

    /** @return array<string, array<string, mixed>> every field, by name, in the form's order */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed>|null */
    public function field(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** A field's type, as the definition names it ('string' for none). */
    public static function typeOf(array $field): string
    {
        return is_string($field['type'] ?? null) ? $field['type'] : 'string';
    }

    public static function knows(array $field): bool
    {
        return in_array(self::typeOf($field), self::TYPES, true);
    }

    public static function isFile(array $field): bool
    {
        return in_array(self::typeOf($field), ['file', 'image'], true);
    }

    /** Hidden fields Core fills from the page (`source`) or a default, never ones a visitor types into. */
    public static function isHidden(array $field): bool
    {
        return ($field['hidden'] ?? false) === true;
    }

    /**
     * The values each field starts with: its default, or nothing.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields as $name => $field) {
            $default = $field['default'] ?? null;

            $defaults[$name] = match (true) {
                $default !== null => $default,
                self::typeOf($field) === 'multi_enum' => [],
                self::isFile($field) => [],
                self::typeOf($field) === 'boolean' => false,
                default => '',
            };
        }

        return $defaults;
    }

    /**
     * Whether a field shows for these values, as Core decides it: its own
     * condition holds, reading a field a condition hid as blank, and a
     * choice whose options all have conditions still offers one.
     *
     * @param  array<string, mixed>  $values
     */
    public function visible(string $name, array $values): bool
    {
        return $this->visibility($values)[$name] ?? false;
    }

    /**
     * Every field's visibility, in the form's order.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, bool>
     */
    public function visibility(array $values): array
    {
        return $this->resolve($values)[0];
    }

    /**
     * The options of a choice field that show for these values.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{value: string, label: string}>
     */
    public function options(string $name, array $values = []): array
    {
        return self::offered((array) ($this->fields[$name]['options'] ?? []), $this->resolve($values)[1]);
    }

    /**
     * Walks the fields in order as Core's rules do, blanking each hidden one
     * so a condition watching it reads blank.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, bool>, 1: array<string, mixed>}
     */
    private function resolve(array $values): array
    {
        $shown = [];

        foreach ($this->fields as $name => $field) {
            $visible = self::matches($field['visibleWhen'] ?? null, $values);
            $options = (array) ($field['options'] ?? []);

            if ($visible && self::conditionalOptions($options) && self::offered($options, $values) === []) {
                $visible = false;
            }

            if (! $visible) {
                $values[$name] = null;
            }

            $shown[$name] = $visible;
        }

        return [$shown, $values];
    }

    /** @param  array<int, mixed>  $options */
    private static function conditionalOptions(array $options): bool
    {
        foreach ($options as $option) {
            if (is_array($option) && is_array($option['visibleWhen'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $options
     * @param  array<string, mixed>  $values
     * @return list<array{value: string, label: string}>
     */
    private static function offered(array $options, array $values): array
    {
        $offered = [];

        foreach ($options as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option) || ! self::matches($option['visibleWhen'] ?? null, $values)) {
                continue;
            }

            $value = self::comparable($option['value']);
            $offered[] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
        }

        return $offered;
    }

    /**
     * The required fields a visitor has to fill for these values: visible,
     * and not hidden (Core fills those itself).
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function required(array $values = []): array
    {
        $required = [];
        $visibility = $this->visibility($values);

        foreach ($this->fields as $name => $field) {
            if (($field['required'] ?? false) === true && ! self::isHidden($field) && $visibility[$name]) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * Laravel rules for the visible fields, keyed by `$prefix` and the field
     * name (`values.email`), as Core's strict mode checks them.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<mixed>>
     */
    public function rules(array $values, string $prefix = 'values.'): array
    {
        $rules = [];
        $visibility = $this->visibility($values);

        foreach ($this->fields as $name => $field) {
            if (self::isHidden($field) || ! $visibility[$name]) {
                continue;
            }

            $required = ($field['required'] ?? false) === true;
            $presence = $required ? 'required' : 'nullable';
            $key = $prefix.$name;
            $options = array_column($this->options($name, $values), 'value');

            match (self::typeOf($field)) {
                'email' => $rules[$key] = [$presence, 'string', 'email', 'max:255'],
                'url' => $rules[$key] = [$presence, 'string', 'max:2048', 'url:http,https'],
                'number' => $rules[$key] = [$presence, 'numeric', ...self::bound('min', $field['min'] ?? null), ...self::bound('max', $field['max'] ?? null)],
                'date' => $rules[$key] = [$presence, 'date', ...self::window($field)],
                // Core's shape check, read with the spacing a visitor types; the country's own format is Core's to check.
                'license_plate' => $rules[$key] = [$presence, 'string', 'max:20', 'regex:/^\s*[\p{L}\p{N}]+(?:[\s-]+[\p{L}\p{N}]+)*\s*$/u'],
                // Core requires a tick only of a consent box; the definition cannot tell one from a plain checkbox but
                // for the privacy box, so Core's 422 names an unticked consent box.
                'boolean' => $rules[$key] = $name === self::PRIVACY_FIELD && $required ? ['accepted'] : [$presence, 'boolean'],
                'enum' => $rules[$key] = [$presence, 'string', Rule::in($options)],
                'multi_enum' => [
                    $rules[$key] = [$presence, 'array', ...($required ? ['min:1'] : [])],
                    $rules[$key.'.*'] = ['string', Rule::in($options)],
                ],
                'file', 'image' => [
                    $rules[$key] = [$presence, 'array', ...self::fileCount($field, $required)],
                    $rules[$key.'.*'] = ['file', ...(is_int($field['maxKb'] ?? null) ? ['max:'.$field['maxKb']] : []), ...(self::typeOf($field) === 'image' ? ['image'] : [])],
                ],
                'text' => $rules[$key] = [$presence, 'string', ...self::length($field, null)],
                default => $rules[$key] = [$presence, 'string', ...self::length($field, 255)],
            };
        }

        return $rules;
    }

    /**
     * What a submission sends Core: the visible fields' values, files left
     * out (they travel as multipart parts), and `$captured` for the hidden
     * fields Core fills from the page.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $captured  hidden field name => value
     * @return array<string, mixed>
     */
    public function payload(array $values, array $captured = []): array
    {
        $payload = [];
        $visibility = $this->visibility($values);

        foreach ($this->fields as $name => $field) {
            if (self::isHidden($field)) {
                $value = $captured[$name] ?? $field['default'] ?? null;

                if ($value !== null && $value !== '') {
                    $payload[$name] = $value;
                }

                continue;
            }

            if (self::isFile($field) || ! $visibility[$name] || ! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $payload[$name] = match (self::typeOf($field)) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($value) ? $value + 0 : $value,
                'license_plate' => is_string($value) ? self::plate($value) : $value,
                default => $value,
            };
        }

        return $payload;
    }

    /**
     * The value a page source stands for (`page.url`, `query.utm_source`).
     *
     * @param  array{url?: string, title?: string, referrer?: string, lang?: string, query?: array<string, mixed>, context?: array<string, mixed>}  $page
     */
    public static function sourceValue(string $source, array $page): ?string
    {
        [$scope, $key] = array_pad(explode('.', $source, 2), 2, '');

        $value = match ($scope) {
            'page' => $page[$key] ?? null,
            'referrer' => $page['referrer'] ?? null,
            'query' => $page['query'][$key] ?? null,
            'context' => $page['context'][$key] ?? null,
            default => null,
        };

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** `abc  123 ` goes as `ABC 123`: Core stores it in its country's form, but refuses a run of separators. */
    private static function plate(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /** @return list<string> a string field's `minLength` / `maxLength`, `$cap` when it declares no maximum */
    private static function length(array $field, ?int $cap): array
    {
        $max = is_int($field['maxLength'] ?? null) ? $field['maxLength'] : $cap;

        return [...self::bound('min', $field['minLength'] ?? null), ...self::bound('max', $max)];
    }

    /** @return list<string> */
    private static function bound(string $rule, mixed $limit): array
    {
        return is_int($limit) || is_float($limit) ? [$rule.':'.$limit] : [];
    }

    /**
     * A date field's window as Core declares it (`today`, `today+N` /
     * `today-N` days, or a `YYYY-MM-DD` date), read on the site's own day;
     * the last day is whole, so a time on it still passes.
     *
     * @return list<string>
     */
    private static function window(array $field): array
    {
        $earliest = self::day($field['earliest'] ?? null);
        $latest = self::day($field['latest'] ?? null);

        return [
            ...($earliest ? ['after_or_equal:'.$earliest->toDateString()] : []),
            ...($latest ? ['before:'.$latest->addDay()->toDateString()] : []),
        ];
    }

    private static function day(mixed $bound): ?CarbonImmutable
    {
        if (! is_string($bound)) {
            return null;
        }

        if (preg_match('/^today(?:([+-])(\d+))?$/', $bound, $match) === 1) {
            $days = (int) ($match[2] ?? 0);

            return CarbonImmutable::today()->addDays(($match[1] ?? '+') === '-' ? -$days : $days);
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $bound) === 1 ? CarbonImmutable::parse($bound)->startOfDay() : null;
    }

    /** @return list<string> */
    private static function fileCount(array $field, bool $required): array
    {
        return [
            ...(is_int($field['minFiles'] ?? null) ? ['min:'.$field['minFiles']] : ($required ? ['min:1'] : [])),
            ...(is_int($field['maxFiles'] ?? null) ? ['max:'.$field['maxFiles']] : (($field['multiple'] ?? false) === true ? [] : ['max:1'])),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private static function matches(mixed $condition, array $values): bool
    {
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return true;
        }

        $actual = self::values(Arr::get($values, $condition['field']));
        $expected = array_map(self::comparable(...), (array) ($condition['value'] ?? []));

        return match ($condition['operator'] ?? 'equals') {
            'filled' => $actual !== [],
            'blank' => $actual === [],
            'not_equals' => ! in_array($expected[0] ?? '', $actual, true),
            'in' => array_intersect($actual, $expected) !== [],
            'not_in' => array_intersect($actual, $expected) === [],
            default => in_array($expected[0] ?? '', $actual, true),
        };
    }

    /** @return list<string> */
    private static function values(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false || $value === []) {
            return [];
        }

        return array_values(array_map(self::comparable(...), is_array($value) ? $value : [$value]));
    }

    private static function comparable(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
