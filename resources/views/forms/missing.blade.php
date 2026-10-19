@php($coreForm->placeMissing())
@php($missingFields = $coreForm->missing())
@if ($missingFields !== [])
    @if (app()->environment('local'))
        <div class="core-form-warning" role="note">
            This form's view leaves out required fields of its Core form; they are added here so an entry still goes through:
            {{ implode(', ', array_column($missingFields, 'name')) }}
        </div>
    @endif
    @foreach ($missingFields as $field)
        @include('core-web::forms.field', ['field' => $field])
    @endforeach
@endif
