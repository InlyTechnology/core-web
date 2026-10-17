<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A Livewire form with the site's own markup that posts to a Core form.
 * The subclass names the Core form, the fields its view renders and the
 * view; the definition Core serves does the rest: defaults, validation,
 * the hidden fields Core fills from the page, and any required field the
 * view leaves out, rendered after it with default markup so an entry still
 * passes Core's checks. In local development that last case shows a
 * warning above those fields.
 *
 * The view binds `values.{field}`; `$status` is '' until a submission, then
 * a SubmissionResult value. Register the class in core-web.forms.renderers
 * so Core guards the fields it renders.
 */
abstract class CoreForm extends Component implements RendersCoreForm
{
    use WithFileUploads;

    /** @var array<string, mixed> field name => value */
    public array $values = [];

    /** @var array{url?: string, referrer?: string, lang?: string, title?: string, query?: array<string, string>, context?: array<string, mixed>} */
    #[Locked]
    public array $page = [];

    #[Locked]
    public string $status = '';

    private ?FormDefinition $definition = null;

    private bool $missingPlaced = false;

    /** The Blade view of the form's own fields, without the <form> element. */
    abstract protected function view(): string;

    /** The submit button's view, after the fields; null when the form's own view has its own. */
    protected function submitView(): ?string
    {
        return 'core-web::forms.submit';
    }

    /** The page title a hidden `page.title` field sends; Livewire cannot read it from the page. */
    protected function pageTitle(): ?string
    {
        return null;
    }

    /**
     * Values for hidden `context.{key}` fields.
     *
     * @return array<string, scalar|null>
     */
    protected function context(): array
    {
        return [];
    }

    /**
     * Rules on top of the definition's, keyed like it (`values.{field}`).
     *
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [];
    }

    /** Called after Core took an entry, before the values are cleared. */
    protected function sent(): void {}

    public function mount(): void
    {
        $request = request();

        $this->values = array_merge($this->definition()?->defaults() ?? [], $this->values);
        $this->page = [
            'url' => $request->url(),
            'referrer' => (string) $request->headers->get('referer', ''),
            'lang' => app()->getLocale(),
            'title' => (string) $this->pageTitle(),
            'query' => array_filter($request->query(), 'is_string'),
            'context' => $this->context(),
        ];
    }

    /** The Core form's definition, or null while the site has none for it. */
    public function definition(): ?FormDefinition
    {
        return $this->definition ??= CoreForms::fromContainer()->definition(static::coreForm());
    }

    /**
     * The required fields the view does not render, for the current values.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        $definition = $this->definition();

        return $definition === null ? [] : array_map(
            fn (string $name) => (array) $definition->field($name),
            self::missingFrom($definition, static::rendered(), $this->values),
        );
    }

    /**
     * The required fields of a definition that a list of rendered fields
     * leaves out, for these values.
     *
     * @param  list<string>  $rendered
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missingFrom(FormDefinition $definition, array $rendered, array $values = []): array
    {
        return array_values(array_diff($definition->required($values), $rendered));
    }

    /** @return list<string> the names of the fields the view renders */
    public static function rendered(): array
    {
        return array_map(
            fn (int|string $key, mixed $value) => is_int($key) ? (string) $value : $key,
            array_keys(static::renders()),
            static::renders(),
        );
    }

    /** Whether the view placed the missing fields itself (@include('core-web::forms.missing')). */
    public function placeMissing(): void
    {
        $this->missingPlaced = true;
    }

    public function missingPlaced(): bool
    {
        return $this->missingPlaced;
    }

    /** Field names other fields' conditions read, which the default markup binds live. */
    public function controlling(): array
    {
        $names = [];

        foreach ($this->definition()?->fields() ?? [] as $field) {
            foreach ([$field['visibleWhen'] ?? null, ...array_column((array) ($field['options'] ?? []), 'visibleWhen')] as $condition) {
                if (is_array($condition) && is_string($condition['field'] ?? null)) {
                    $names[$condition['field']] = true;
                }
            }
        }

        return array_keys($names);
    }

    public function submit(FormSubmitter $submitter): void
    {
        $definition = $this->definition();

        if ($definition === null) {
            $this->status = SubmissionResult::UNAVAILABLE->value;

            return;
        }

        $files = [];

        foreach ($definition->fields() as $name => $field) {
            if (FormDefinition::isFile($field)) {
                $this->values[$name] = array_values(array_filter(Arr::wrap($this->values[$name] ?? []), fn (mixed $file) => $file instanceof UploadedFile));
                $files[$name] = $definition->visible($name, $this->values) ? $this->values[$name] : [];
            }
        }

        $this->validate(
            [...$definition->rules($this->values), ...$this->extraRules()],
            [],
            array_combine(
                array_map(fn (string $name) => "values.{$name}", array_keys($definition->fields())),
                array_map(fn (array $field) => (string) ($field['label'] ?? $field['name']), $definition->fields()),
            ),
        );

        $key = 'core-web-forms|'.static::coreForm().'|'.sha1((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('core-web.forms.submit_rate_limit', 5)))) {
            $this->status = SubmissionResult::THROTTLED->value;

            return;
        }

        RateLimiter::hit($key, 60);

        $captured = [];

        foreach ($definition->fields() as $name => $field) {
            if (is_string($field['source'] ?? null) && ($value = FormDefinition::sourceValue($field['source'], $this->page)) !== null) {
                $captured[$name] = $value;
            }
        }

        $result = $submitter->submit($definition->id(), $definition->payload($this->values, $captured), array_filter($files));
        $this->status = $result->value;

        if ($result === SubmissionResult::INVALID) {
            foreach ($submitter->errors() as $field => $message) {
                $this->addError("values.{$field}", $message);
            }

            return;
        }

        if ($result === SubmissionResult::SENT) {
            $this->sent();
            $this->values = $definition->defaults();
        }
    }

    public function render(): View
    {
        return view('core-web::forms.form', [
            'coreForm' => $this,
            'body' => $this->view(),
            'submitView' => $this->submitView(),
        ]);
    }
}
