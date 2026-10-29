@if ($row['status'] === 'added')
    <ui-badge size="sm" color="green" text="{{ __('Added') }}"></ui-badge>
@elseif ($row['status'] === 'removed')
    <ui-badge size="sm" color="red" text="{{ __('Removed') }}"></ui-badge>
@elseif ($row['status'] === 'origin')
    <span class="text-xs text-red-700 dark:text-red-400">{{ __('Moved from here') }}</span>
@elseif ($row['status'] === 'changed')
    <ui-badge size="sm" color="amber" text="{{ __('Changed') }}"></ui-badge>
@endif
@if ($row['moved'])
    <ui-badge size="sm" color="blue" text="{{ __('Moved') }}"></ui-badge>
@endif
