<button type="submit" class="core-form-submit" wire:loading.attr="disabled">
    {{ ($coreForm->form()['messages']['submit'] ?? null) ?: ($coreForm->form()['labels']['submit'] ?? 'Send') }}
</button>
