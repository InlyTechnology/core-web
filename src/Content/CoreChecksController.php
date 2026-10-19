<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\CoreWebServiceProvider;

/**
 * The site's content checks as Core reads them (Statamic connector API v1.2):
 * each check with its latest run, a check's findings, and a fix Core AI asks for, which
 * lands as a draft or a proposal an editor publishes.
 */
class CoreChecksController
{
    public function index(ContentChecks $checks): JsonResponse
    {
        $rows = [];

        foreach ($checks->all() as $key => $check) {
            $latest = $checks->latest($key);
            $findings = $latest['findings'] ?? [];

            $rows[] = [
                'key' => $key,
                'description' => $check->description(),
                'ran_at' => $latest['ran_at'] ?? null,
                'error' => $latest['error'] ?? null,
                'count' => count($findings),
                'fixable' => CoreWebServiceProvider::enabled('drafts') ? count(array_filter($findings, fn (array $finding) => $finding['fixable'])) : 0,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function findings(string $check, Request $request, ContentChecks $checks): JsonResponse
    {
        if ($checks->get($check) === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_check', ['check' => $check])], 404);
        }

        $request->validate(['site' => ['nullable', 'string', 'max:64'], 'subject' => ['nullable', 'string', 'max:200']]);

        $latest = $checks->latest($check);
        $findings = collect($latest['findings'] ?? [])
            ->when($request->filled('site'), fn ($rows) => $rows->where('site', $request->input('site')))
            ->when($request->filled('subject'), fn ($rows) => $rows->where('subject', $request->input('subject')))
            ->values()
            ->all();

        return response()->json(['data' => $findings, 'ran_at' => $latest['ran_at'] ?? null]);
    }

    public function fix(string $check, Request $request, ContentChecks $checks): JsonResponse
    {
        $request->validate(['finding_id' => ['required', 'string', 'max:64'], ...CoreAiAuthor::rules()]);

        return response()->json(['data' => $checks->fix($check, (string) $request->input('finding_id'), CoreAiAuthor::from($request, 'fix'))], 201);
    }
}
