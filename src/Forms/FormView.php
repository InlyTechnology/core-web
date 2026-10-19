<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Support\Str;

/**
 * What a CoreForm's views read, as `$coreForm`: the definition, the values
 * so far and the fields its markup leaves out. Kept off the component, so
 * none of it is a Livewire action a visitor could call.
 */
final class FormView
{
    private bool $missingPlaced = false;

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $rendered
     */
    public function __construct(
        public readonly string $id,
        public readonly ?FormDefinition $definition,
        public readonly array $values,
        private readonly array $rendered,
    ) {}

    /** @return array<string, mixed> the definition's `form` object, empty without one */
    public function form(): array
    {
        return (array) ($this->definition?->definition['form'] ?? []);
    }

    /**
     * The required fields the view does not render, for the current values.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        if ($this->definition === null) {
            return [];
        }

        return array_map(
            fn (string $name) => (array) $this->definition->field($name),
            CoreForm::missingFrom($this->definition, $this->rendered, $this->values),
        );
    }

    public function visible(string $name): bool
    {
        return $this->definition?->visible($name, $this->values) ?? false;
    }

    /** @return list<array{value: string, label: string}> */
    public function options(string $name): array
    {
        return $this->definition?->options($name, $this->values) ?? [];
    }

    /** Field names other fields' conditions read, which the default markup binds live. */
    public function controls(string $name): bool
    {
        foreach ($this->definition?->fields() ?? [] as $field) {
            foreach ([$field['visibleWhen'] ?? null, ...array_column((array) ($field['options'] ?? []), 'visibleWhen')] as $condition) {
                if (is_array($condition) && ($condition['field'] ?? null) === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Core's inline markdown (a label linking the privacy policy), with any HTML in it stripped. */
    public static function inline(?string $text): string
    {
        return blank($text) ? '' : (string) Str::inlineMarkdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** Placed by @include('core-web::forms.missing'), so the form does not add them a second time. */
    public function placeMissing(): void
    {
        $this->missingPlaced = true;
    }

    public function missingPlaced(): bool
    {
        return $this->missingPlaced;
    }
}
