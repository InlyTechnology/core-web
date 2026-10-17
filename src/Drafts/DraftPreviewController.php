<?php

namespace Inly\CoreWeb\Drafts;

use Illuminate\Http\RedirectResponse;
use Statamic\Exceptions\NotFoundHttpException;

/** Opens a draft in Live Preview behind its signed link (see DraftPreview). */
class DraftPreviewController
{
    public function __invoke(string $id, DraftPreview $preview): RedirectResponse
    {
        $url = $preview->open($id) ?? throw new NotFoundHttpException;

        return redirect()->away($url)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
