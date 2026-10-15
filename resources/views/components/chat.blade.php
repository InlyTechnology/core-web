@props(['agent', 'subject' => null])
{{-- Only the Alpine wrapper: the markup inside is the site's slot. --}}
<div x-data="coreChat(@js([
    'endpoint' => route(config('core-web.chat.name', 'core-web.chat').'.show', ['agent' => $agent], false),
    'subject' => $subject === null || $subject === '' ? null : (string) $subject,
    'cookie' => \Inly\CoreWeb\Chat\ChatSession::cookieName($agent),
]))" {{ $attributes }}>{{ $slot }}</div>
