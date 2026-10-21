@if (\Inly\CoreWeb\Forms\FormRelay::relaysEmbeds() || \Inly\CoreWeb\Forms\FormRelay::relaysSubmissions())
    <div {{ $attributes->merge(['class' => 'core-form-warning core-form-relay-notice', 'role' => 'note']) }}>
        Local site: this form sends to production through {{ parse_url((string) config('core-web.content_sync.remote_url'), PHP_URL_HOST) }}. Submissions are real leads.
    </div>
@endif
