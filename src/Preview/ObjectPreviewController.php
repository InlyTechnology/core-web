<?php

namespace Inly\CoreWeb\Preview;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Facades\Site;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Core's preview frame of a record's page (Statamic connector API v1.6): the
 * record read from Core with the person's preview state applied, rendered
 * through the site's own route for the page, for this request only. Without
 * an applied state only a page the site serves publicly renders. See
 * docs/object-preview.md.
 */
class ObjectPreviewController
{
    public function __invoke(Request $request, ObjectPages $pages, SnapshotStore $store, PreviewContext $context, PreviewFrame $frame): SymfonyResponse
    {
        // An unsealed frame route answers a malformed address as a missing page, never a redirect back to a Referer.
        $validator = Validator::make($request->query(), [
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:128'],
            'site' => ['required', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
        ]);
        $core = PreviewFrame::coreOrigin();

        abort_if($validator->fails() || $core === null || Site::get((string) $request->query('site')) === null, 404);

        $input = $validator->validated();

        foreach ($pages->snapshotsFor($input['type']) as $snapshot) {
            $saved = $pages->document($snapshot, $input['site'], $input['id']);
            $previewed = blank($input['state'] ?? null) ? null : $this->previewed($snapshot, $input['site'], $input['id'], (string) $input['state'], $context);

            if (($previewed ?? $saved) !== null) {
                [$documentId, $document] = $previewed ?? $saved;
                $partition = $snapshot->partitionFor($input['site']) ?: null;
                $hidden = $previewed === null ? [] : array_values(array_diff($pages->storedIds($snapshot, $partition, $input['id']), [$documentId]));

                return PreviewFrame::answer($this->render($request, $snapshot, $input['site'], $partition, $documentId, $document, $hidden, $store, $frame), $core);
            }
        }

        abort(404);
    }

    /**
     * The record as Core reads it with the state applied, null when Core did not apply it (no live state, or one the
     * site's client may not read) or the record then has no page on the site.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function previewed(Snapshot $snapshot, string $site, string $id, string $state, PreviewContext $context): ?array
    {
        $partition = $snapshot->partitionFor($site);

        if ($partition === false || ! $snapshot->pullsOne()) {
            return null;
        }

        try {
            [$documents, $applied] = $context->reading($state, fn (): array => $snapshot->pullDocumentWithDetails($id));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $applied) {
            return null;
        }

        foreach ($snapshot->documentsIn($documents, $partition) as $documentId => $document) {
            if ($snapshot->pagePath($document, $site) !== null) {
                return [(string) $documentId, $document];
            }
        }

        return null;
    }

    /**
     * The page as the site's own route renders it, with the document in place of the stored one for every read of the
     * snapshot during the render.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $hidden
     */
    private function render(Request $request, Snapshot $snapshot, string $site, ?string $partition, string $documentId, array $document, array $hidden, SnapshotStore $store, PreviewFrame $frame): SymfonyResponse
    {
        $url = rtrim(Site::get($site)->absoluteUrl(), '/').ObjectPages::encodePath((string) $snapshot->pagePath($document, $site));

        return $frame->render($request, $url, fn (Closure $handle): SymfonyResponse => $store->previewing($snapshot, $partition, [$documentId => $document], $handle, $hidden));
    }

    /** @deprecated Use PreviewFrame::coreOrigin() instead. */
    public static function coreOrigin(): ?string
    {
        return PreviewFrame::coreOrigin();
    }
}
