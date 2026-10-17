<div class="own-markup">
    <input type="text" wire:model="values.plate">
    <input type="text" wire:model="values.name">
    @if ($status === 'sent')
        <p>Thanks!</p>
    @endif
</div>
