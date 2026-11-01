<?php

namespace Inly\CoreWeb\Translation;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/** The control panel's "Translate with Core AI" field action: the translated value, for the form to take. */
final class TranslateFieldController
{
    public function __invoke(Request $request, FieldTranslator $translator): JsonResponse
    {
        $validated = $request->validate([
            'entry' => ['required', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:500', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'value' => ['nullable'],
        ]);

        $entry = Entry::find($validated['entry']);
        abort_if($entry === null, 404);
        abort_unless(User::current()?->can('edit', $entry), 403);

        try {
            return response()->json(['value' => $translator->translate($entry, $validated['path'], $validated['value'] ?? null)]);
        } catch (FieldNotTranslatable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
