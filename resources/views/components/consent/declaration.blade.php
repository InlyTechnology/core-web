@php
    $titles = [
        'necessary' => __('Necessary'),
        'preferences' => __('Preferences'),
        'statistics' => __('Statistics'),
        'marketing' => __('Marketing'),
    ];
@endphp
{{-- The cookie declaration, read from the same list as the banner so the two never disagree. --}}
<div class="core-consent-declaration" @if ($style = \Inly\CoreWeb\Consent\Consent::brandingStyle()) style="{{ $style }}" @endif {{ $attributes }}>
    <p class="core-consent-declaration__status">
        {{ __('Your current choice:') }} <span data-consent-status data-none="{{ __('none yet') }}" data-labels='@json(array_map('strval', $titles))'>{{ __('none yet') }}</span>
        <button type="button" class="core-consent__button core-consent__button--outline" data-consent-open>{{ __('Change cookie settings') }}</button>
    </p>
    <p class="core-consent__id" data-consent-id-line hidden>{{ __('Your consent id:') }} <code data-consent-id></code></p>
    @foreach (\Inly\CoreWeb\Consent\ConsentVendors::byCategory() as $category => $vendors)
        <h3>{{ $titles[$category] }}</h3>
        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('Cookie') }}</th>
                    <th scope="col">{{ __('Provider') }}</th>
                    <th scope="col">{{ __('Purpose') }}</th>
                    <th scope="col">{{ __('Expiry') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vendors as $vendor)
                    @foreach ($vendor['cookies'] as $cookie)
                        <tr>
                            <td><code>{{ $cookie['name'] }}</code></td>
                            <td>
                                @if (! empty($vendor['policy_url']))
                                    <a href="{{ $vendor['policy_url'] }}" target="_blank" rel="noopener">{{ $vendor['name'] }}</a>
                                @else
                                    {{ $vendor['name'] }}
                                @endif
                            </td>
                            <td>{{ __($cookie['purpose']) }}</td>
                            <td>{{ \Inly\CoreWeb\Consent\ConsentVendors::lifetime($cookie) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endforeach
</div>
