<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Core's notice that a person was deleted or lost CMS access, sealed with its
 * own key to this request (`cms_revoke`, this site and the person in the JSON
 * body). Nothing a content purpose opens can delete an account. Spent on
 * first use.
 */
class RevokeController
{
    public function __invoke(Request $request, CmsHandoff $handoff): JsonResponse
    {
        $claims = $handoff->openRevoke($request);

        // Core's ids are integers in a JSON body; the job keys on the string.
        if (is_int($claims['user_id'] ?? null)) {
            $claims['user_id'] = (string) $claims['user_id'];
        }

        $data = Validator::make($claims, [
            'user_id' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
        ])->validate();

        ForgetCmsUserJob::dispatch($data['user_id'], $data['email']);

        return response()->json(['status' => 'accepted'], 202);
    }
}
