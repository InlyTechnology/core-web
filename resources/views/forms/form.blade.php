<div>
    <form wire:submit="submit" novalidate>
        @include($body)
        @unless ($coreForm->missingPlaced())
            @include('core-web::forms.missing')
        @endunless
        <div aria-hidden="true" style="position: absolute; left: -10000px;">
            <input type="text" wire:model="website_url" tabindex="-1" autocomplete="off">
        </div>
        @error('form')
            <p class="core-form-error" role="alert">{{ $message }}</p>
        @enderror
        @if (filled($coreForm->form()['notice'] ?? null))
            <p class="core-form-notice">{!! \Inly\CoreWeb\Forms\FormView::inline($coreForm->form()['notice']) !!}</p>
        @endif
        @if ($submitView)
            @include($submitView)
        @endif
    </form>
</div>
