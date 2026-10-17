<?php

namespace Inly\CoreWeb\Forms;

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
    public const TYPES = ['string', 'text', 'number', 'boolean', 'email', 'url', 'phone', 'date', 'enum', 'multi_enum', 'file', 'image'];

    /**
     * The keys of a definition field this class and the default markup know:
     * read, or deliberately left to the site's own markup (width, slots).
     */
    public const KEYS = [
        'name', 'type', 'label', 'description', 'placeholder', 'required', 'hidden', 'source', 'default', 'options',
        'autocomplete', 'display', 'accept', 'maxKb', 'multiple', 'maxFiles', 'minFiles', 'slots', 'maxSide', 'capture',
        'withTime', 'width', 'visibleWhen', 'section', 'step',
    ];

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
     * Whether a field shows for these values: its own condition holds.
     *
     * @param  array<string, mixed>  $values
     */
    public function visible(string $name, array $values): bool
    {
        $field = $this->fields[$name] ?? null;

        return $field !== null && self::matches($field['visibleWhen'] ?? null, $values);
    }

    /**
     * The options of a choice field that show for these values.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{value: string, label: string}>
     */
    public function options(string $name, array $values = []): array
    {
        $options = [];

        foreach ((array) ($this->fields[$name]['options'] ?? []) as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option) || ! self::matches($option['visibleWhen'] ?? null, $values)) {
                continue;
            }

            $value = self::comparable($option['value']);
            $options[] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
        }

        return $options;
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

        foreach ($this->fields as $name => $field) {
            if (($field['required'] ?? false) === true && ! self::isHidden($field) && $this->visible($name, $values)) {
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

        foreach ($this->fields as $name => $field) {
            if (self::isHidden($field) || ! $this->visible($name, $values)) {
                continue;
            }

            $required = ($field['required'] ?? false) === true;
            $presence = $required ? 'required' : 'nullable';
            $key = $prefix.$name;
            $options = array_column($this->options($name, $values), 'value');

            match (self::typeOf($field)) {
                'email' => $rules[$key] = [$presence, 'string', 'email', 'max:255'],
                'url' => $rules[$key] = [$presence, 'string', 'url', 'max:2048'],
                'number' => $rules[$key] = [$presence, 'numeric'],
                'date' => $rules[$key] = [$presence, 'date'],
                'boolean' => $rules[$key] = $required ? ['accepted'] : ['nullable', 'boolean'],
                'enum' => $rules[$key] = [$presence, 'string', Rule::in($options)],
                'multi_enum' => [
                    $rules[$key] = [$presence, 'array', ...($required ? ['min:1'] : [])],
                    $rules[$key.'.*'] = ['string', Rule::in($options)],
                ],
                'file', 'image' => [
                    $rules[$key] = [$presence, 'array', ...self::fileCount($field, $required)],
                    $rules[$key.'.*'] = ['file', ...(is_int($field['maxKb'] ?? null) ? ['max:'.$field['maxKb']] : []), ...(self::typeOf($field) === 'image' ? ['image'] : [])],
                ],
                'text' => $rules[$key] = [$presence, 'string', 'max:10000'],
                default => $rules[$key] = [$presence, 'string', 'max:255'],
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

        foreach ($this->fields as $name => $field) {
            if (self::isHidden($field)) {
                $value = $captured[$name] ?? $field['default'] ?? null;

                if ($value !== null && $value !== '') {
                    $payload[$name] = $value;
                }

                continue;
            }

            if (self::isFile($field) || ! $this->visible($name, $values) || ! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $payload[$name] = match (self::typeOf($field)) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($value) ? $value + 0 : $value,
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

        $actual = self::values($values[$condition['field']] ?? null);
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
