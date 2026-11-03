<?php

namespace Inly\CoreWeb\Preview;

use Closure;

/**
 * What a request knows about a Core preview: the preview state token its
 * reads of Core carry (only while ObjectPreviewController pulls the
 * record), whether Core applied it, and whether a page is being rendered for
 * Core's preview frame, which is when the field and block markers print.
 */
final class PreviewContext
{
    private ?string $token = null;

    private bool $applied = false;

    private bool $rendering = false;

    public static function current(): self
    {
        return app(self::class);
    }

    /** The preview state token while a read of Core should carry it. */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * Runs `$read` with every read of Core carrying the state, and answers
     * its result and whether Core said it applied the state.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return array{0: T, 1: bool}
     */
    public function reading(string $token, Closure $read): array
    {
        $this->token = $token;
        $this->applied = false;

        try {
            $result = $read();

            return [$result, $this->applied];
        } finally {
            $this->token = null;
        }
    }

    /** Core's answer to a read that carried the state. */
    public function answered(?string $applied): void
    {
        if ($this->token !== null && strtolower((string) $applied) === 'true') {
            $this->applied = true;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    public function rendering(Closure $render): mixed
    {
        $this->rendering = true;

        try {
            return $render();
        } finally {
            $this->rendering = false;
        }
    }

    public function isRendering(): bool
    {
        return $this->rendering;
    }
}
