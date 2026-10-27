<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The CP's consent log: a visitor's evidence by consent id, the totals per banner, and the evidence as a download. */
class ConsentUtilityController
{
    /** @return array<string, mixed> */
    public static function data(Request $request): array
    {
        $id = self::id($request);

        return [
            'id' => $id,
            'action' => cp_route('utilities.consent'),
            'export' => $id ? cp_route('utilities.consent.export', ['id' => $id]) : null,
            'evidence' => $id ? ConsentEvidence::forId($id) : null,
            'totals' => ConsentEvidence::totals(),
            'actions' => [
                'accept_all' => __('Accepted all'),
                'reject_all' => __('Rejected all'),
                'custom' => __('Chose'),
            ],
        ];
    }

    public function export(Request $request): Response
    {
        $id = self::id($request);
        abort_if($id === null, 404);

        $evidence = ConsentEvidence::forId($id);
        abort_if($evidence['choices'] === [], 404);

        return response()->json($evidence, 200, [
            'Content-Disposition' => 'attachment; filename="consent-'.$id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function id(Request $request): ?string
    {
        $id = strtolower(trim((string) $request->query('id', '')));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1 ? $id : null;
    }
}
