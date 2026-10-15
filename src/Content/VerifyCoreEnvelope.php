<?php

namespace Inly\CoreWeb\Content;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for a site endpoint Core calls, naming the purpose the
 * endpoint takes (`core-web.envelope:website.read`). A refusal is a JSON 403;
 * an opened request reaches the controller with its claims in the request's
 * `core_envelope` attribute. No answer is kept by the static cache or a proxy.
 */
class VerifyCoreEnvelope
{
    public const ALIAS = 'core-web.envelope';

    public const ATTRIBUTE = 'core_envelope';

    public function __construct(private CoreEnvelope $envelope) {}

    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        // Core is a machine: whatever the controller refuses is JSON, never a redirect back.
        $request->headers->set('Accept', 'application/json');

        try {
            $request->attributes->set(self::ATTRIBUTE, $this->envelope->openRequest($request, $purpose));
        } catch (EnvelopeRefused $e) {
            return self::uncached(response()->json([
                'error' => 'envelope_refused',
                ...($e->signatureVerified() ? ['reason' => $e->reason] : []),
            ], 403));
        } catch (WrongEnvironment $e) {
            return self::uncached(response()->json(['error' => 'wrong_environment', 'message' => $e->getMessage()], 403));
        }

        return self::uncached($next($request));
    }

    /**
     * The opened claims of the request this middleware let through.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(Request $request): ?array
    {
        $claims = $request->attributes->get(self::ATTRIBUTE);

        return is_array($claims) ? $claims : null;
    }

    private static function uncached(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Statamic-Uncacheable', 'true');

        return $response;
    }
}
