@php($coreFormText = (array) ($coreForm->definition()?->definition['form'] ?? []))
<button type="submit" class="core-form-submit" wire:loading.attr="disabled">
    {{ ($coreFormText['messages']['submit'] ?? null) ?: ($coreFormText['labels']['submit'] ?? 'Send') }}
</button>
