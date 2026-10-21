@if (\Inly\CoreWeb\Forms\FormRelay::relaysEmbeds() || \Inly\CoreWeb\Forms\FormRelay::relaysSubmissions())
    <div {{ $attributes->merge(['class' => 'core-form-warning core-form-relay-notice', 'role' => 'note']) }}>
        {{ __('Local site: this form sends to production through :host. Submissions are real leads.', ['host' => parse_url((string) config('core-web.content_sync.remote_url'), PHP_URL_HOST)]) }}
    </div>
@endif
