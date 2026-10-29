@if ($row['status'] === 'added')
    <ui-badge size="sm" color="green" text="{{ __('Added') }}"></ui-badge>
@elseif ($row['status'] === 'removed')
    <ui-badge size="sm" color="red" text="{{ __('Removed') }}"></ui-badge>
@elseif ($row['status'] === 'changed')
    <ui-badge size="sm" color="amber" text="{{ __('Changed') }}"></ui-badge>
@endif
@if ($row['moved'])
    <ui-badge size="sm" color="blue" text="{{ __('Moved') }}"></ui-badge>
    @if (filled($row['movedFrom'] ?? null))
        <span class="text-xs text-blue-700 dark:text-blue-400" v-pre>{{ __('from :place', ['place' => $row['movedFrom']]) }}</span>
    @endif
@endif
