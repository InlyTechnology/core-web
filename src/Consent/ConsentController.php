<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Statamic\Facades\Site;

/** Records a visitor's choice as proof of consent: their random consent id, never their address or a user. */
class ConsentController
{
    public function __invoke(Request $request): Response
    {
        // A choice is only ever made on the site's own pages.
        $origin = $request->headers->get('Origin');
        abort_if($origin !== null && parse_url($origin, PHP_URL_HOST) !== $request->getHost(), 403);

        $choice = $request->validate([
            'id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(Consent::ACTIONS)],
            'categories' => ['present', 'array', 'max:3'],
            'categories.*' => ['string', Rule::in(array_diff(ConsentVendors::CATEGORIES, ['necessary']))],
            'version' => ['required', 'integer', 'min:1', 'max:100000'],
            'site' => ['required', 'string', Rule::in(Site::all()->map->handle()->values()->all())],
        ]);

        DB::connection(config('core-web.consent.log.connection'))
            ->table((string) config('core-web.consent.log.table', 'core_web_consents'))
            ->insert([
                'consent_id' => strtolower($choice['id']),
                'site' => $choice['site'],
                'action' => $choice['action'],
                'categories' => json_encode(array_values(array_unique($choice['categories']))),
                'policy_version' => $choice['version'],
                'created_at' => now(),
            ]);

        return response()->noContent();
    }
}
