{{-- Default markup for one field of a Core form definition, bound to values.{name}. --}}
@php
    $name = (string) $field['name'];
    $type = \Inly\CoreWeb\Forms\FormDefinition::typeOf($field);
    $id = 'core-form-'.$coreForm->id.'-'.$name;
    $model = $coreForm->controls($name) ? 'wire:model.live' : 'wire:model';
    $required = ($field['required'] ?? false) === true;
    $label = \Inly\CoreWeb\Forms\FormView::inline((string) ($field['label'] ?? $name));
    $options = $coreForm->options($name);
    $inputType = match ($type) {
        'email' => 'email',
        'url' => 'url',
        'phone' => 'tel',
        'number' => 'number',
        'date' => ($field['withTime'] ?? false) ? 'datetime-local' : 'date',
        default => 'text',
    };
@endphp
@if (! \Inly\CoreWeb\Forms\FormDefinition::isHidden($field) && $coreForm->visible($name))
    <div class="core-form-field core-form-field--{{ $type }}" wire:key="{{ $id }}">
        @if ($type === 'boolean')
            <label for="{{ $id }}">
                <input type="checkbox" id="{{ $id }}" {{ $model }}="values.{{ $name }}" value="1" @required($required)>
                {!! $label !!}
            </label>
        @elseif ($type === 'multi_enum' || ($type === 'enum' && in_array($field['display'] ?? null, ['radio', 'checkbox'], true)))
            <fieldset>
                <legend>{!! $label !!}</legend>
                @foreach ($options as $option)
                    <label>
                        <input type="{{ $type === 'multi_enum' ? 'checkbox' : 'radio' }}" {{ $model }}="values.{{ $name }}" value="{{ $option['value'] }}">
                        {{ $option['label'] }}
                    </label>
                @endforeach
            </fieldset>
        @else
            <label for="{{ $id }}">{!! $label !!}</label>
            @if ($type === 'text')
                <textarea id="{{ $id }}" {{ $model }}="values.{{ $name }}" placeholder="{{ $field['placeholder'] ?? '' }}" @required($required)></textarea>
            @elseif ($type === 'enum')
                <select id="{{ $id }}" {{ $model }}="values.{{ $name }}" @required($required)>
                    <option value="">{{ $field['placeholder'] ?? ($coreForm->form()['labels']['choose'] ?? '') }}</option>
                    @foreach ($options as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            @elseif ($type === 'file' || $type === 'image')
                <input type="file" id="{{ $id }}" wire:model="values.{{ $name }}" @if ($field['multiple'] ?? false) multiple @endif accept="{{ $field['accept'] ?? '' }}" @required($required)>
            @else
                <input type="{{ $inputType }}" id="{{ $id }}" {{ $model }}="values.{{ $name }}" placeholder="{{ $field['placeholder'] ?? '' }}" autocomplete="{{ $field['autocomplete'] ?? '' }}" @required($required)>
            @endif
        @endif
        @if (filled($field['description'] ?? null))
            <p class="core-form-description">{{ $field['description'] }}</p>
        @endif
        @error('values.'.$name)
            <p class="core-form-error">{{ $message }}</p>
        @enderror
    </div>
@endif
