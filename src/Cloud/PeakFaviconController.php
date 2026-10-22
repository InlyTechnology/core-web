<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Peak Tools links its generated icons at /favicons/..., which on Cloud are on the bucket, not in public/. */
final class PeakFaviconController
{
    public function __invoke(string $path): RedirectResponse
    {
        if (str_contains($path, '..')) {
            throw new NotFoundHttpException;
        }

        return redirect()->away(Storage::disk('favicons')->url($path))->setCache(['public' => true, 'max_age' => 3600]);
    }
}
