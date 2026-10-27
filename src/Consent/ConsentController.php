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
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'lang' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/'],
        ]);

        $row = [
            'consent_id' => strtolower($choice['id']),
            'site' => $choice['site'],
            'action' => $choice['action'],
            'categories' => json_encode(array_values(array_unique($choice['categories']))),
            'policy_version' => $choice['version'],
            'created_at' => now(),
        ];
        $table = DB::connection(config('core-web.consent.log.connection'))->table((string) config('core-web.consent.log.table', 'core_web_consents'));

        // A site that has not run the snapshots migration yet still keeps the choice itself.
        $table->insert(ConsentSnapshot::ready()
            ? array_merge($row, $this->shown($choice['site'], $choice['fingerprint'] ?? null, $choice['lang'] ?? null))
            : $row);

        return response()->noContent();
    }

    /**
     * The fingerprint and locale of the banner the visitor chose on. The page may be cached from before the banner
     * changed, so only a fingerprint matching today's banner is stored as its snapshot; an older one was kept by
     * the choices made while it was current. A page cached before fingerprints existed sends none, and the row
     * claims none. The banner is rebuilt in the language the page sent, which is the site's own on Statamic's routes.
     *
     * @return array{fingerprint: string|null, locale: string}
     */
    private function shown(string $handle, ?string $fingerprint, ?string $lang): array
    {
        $site = Site::current();
        $locale = app()->getLocale();
        Site::setCurrent($handle);
        app()->setLocale($lang ?? Site::current()->lang());

        try {
            $content = ConsentSnapshot::content();
            $current = ConsentSnapshot::fingerprint($content);

            if ($fingerprint === $current) {
                ConsentSnapshot::store($content);
            }

            return ['fingerprint' => $fingerprint, 'locale' => $content['locale']];
        } finally {
            Site::setCurrent($site->handle());
            app()->setLocale($locale);
        }
    }
}
