<?php

namespace Inly\CoreWeb\Search;

/**
 * Core's text folding for `filter[_search]`, letter for letter: the accent
 * map, the punctuation a word drops at its ends and how a term splits into
 * words. A copy, so the local engine matches what Core matches; Core's search
 * contract (tests/fixtures/core-search-contract.json) pins it.
 */
final class SearchText
{
    /** Each accented letter, in both cases, and the plain lowercase letter it folds to. */
    public const MAP = [
        'a' => 'àáâãäåāăąÀÁÂÃÄÅĀĂĄ',
        'c' => 'çćčĉċÇĆČĈĊ',
        'd' => 'ďđĎĐ',
        'e' => 'èéêëēėęěĕÈÉÊËĒĖĘĚĔ',
        'g' => 'ğĝġģĞĜĠĢ',
        'i' => 'ìíîïīįıĭÌÍÎÏĪĮİĬ',
        'l' => 'łľĺļŁĽĹĻ',
        'n' => 'ñńňņÑŃŇŅ',
        'o' => 'òóôõöøōőŏÒÓÔÕÖØŌŐŎ',
        'r' => 'řŕŗŘŔŖ',
        's' => 'śšşșŝŚŠŞȘŜ',
        't' => 'ťţțŤŢȚ',
        'u' => 'ùúûüūůűųŭÙÚÛÜŪŮŰŲŬ',
        'y' => 'ýÿŷÝŸŶ',
        'z' => 'źžżŹŽŻ',
    ];

    /** Characters a typed word may carry at either end without changing what it means, a phone's curly quotes too. */
    public const EDGE_PUNCTUATION = ".,;:!?\"'()[]“”‘’«»";

    /** Most words a term is matched by; Core cuts a longer term there. */
    public const MAX_WORDS = 8;

    /** Longest term Core searches for; a longer one is cut. */
    public const MAX_TERM_LENGTH = 200;

    /** @var array<string, string>|null */
    private static ?array $pairs = null;

    /** The trimmed, capped term, or null when it holds nothing to search for. */
    public static function term(?string $term): ?string
    {
        $trimmed = mb_substr(trim((string) $term), 0, self::MAX_TERM_LENGTH);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The words of a term as they are matched, split on whitespace with
     * EDGE_PUNCTUATION dropped from either end; a term of punctuation alone is
     * its words as typed.
     *
     * @return list<string>
     */
    public static function words(string $term): array
    {
        $typed = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter(
            array_map(self::trimEdges(...), $typed),
            fn (string $word): bool => $word !== '',
        ));

        return $words !== [] ? $words : $typed;
    }

    /** The word with EDGE_PUNCTUATION dropped from either end, read as letters, not bytes. */
    public static function trimEdges(string $word): string
    {
        $class = '['.preg_quote(self::EDGE_PUNCTUATION, '/').']+';

        return (string) preg_replace("/^{$class}|{$class}$/u", '', $word);
    }

    /** Every accented letter of MAP replaced by its plain letter, then lowercased: folding first, as Core does. */
    public static function fold(string $text): string
    {
        return mb_strtolower(strtr($text, self::pairs()));
    }

    /** A plate as Core compares it: upper case, without spaces or dashes; null when nothing is left. */
    public static function plate(?string $plate): ?string
    {
        if ($plate === null) {
            return null;
        }

        $canonical = mb_strtoupper((string) preg_replace('/[\s\p{Pd}]+/u', '', $plate));

        return $canonical === '' ? null : $canonical;
    }

    /** @return array<string, string> */
    private static function pairs(): array
    {
        if (self::$pairs !== null) {
            return self::$pairs;
        }

        $pairs = [];

        foreach (self::MAP as $plain => $letters) {
            foreach (mb_str_split($letters) as $letter) {
                $pairs[$letter] = $plain;
            }
        }

        return self::$pairs = $pairs;
    }
}
