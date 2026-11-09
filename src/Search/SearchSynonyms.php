<?php

namespace Inly\CoreWeb\Search;

/**
 * The words of a search term that mean a filter rather than text, as Core
 * reads them from the object's `search.synonyms`: "elbil volvo" is the filter
 * `fuel = El` and the text "volvo". A synonym is a whole word or phrase,
 * matched case-insensitively (and accent-insensitively where the object
 * ignores accents), longest first; values one term names for the same key
 * merge into an any-of list.
 */
final class SearchSynonyms
{
    /**
     * @param  array<string, array<string, mixed>>  $synonyms  word or phrase => [filter key => value or list of values]
     */
    public function __construct(
        private readonly array $synonyms,
        private readonly bool $ignoreAccents = false,
    ) {}

    /**
     * The term split into the text left to search and the filter values its synonyms name.
     *
     * @return array{text: string, filters: array<string, list<mixed>>}
     */
    public function split(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($this->synonyms === [] || $words === []) {
            return ['text' => trim($term), 'filters' => []];
        }

        $normalized = array_map($this->normalize(...), $words);
        $phrases = $this->phrases();
        $kept = [];
        $filters = [];

        for ($i = 0, $count = count($words); $i < $count;) {
            $match = $this->matchAt($normalized, $i, $phrases);

            if ($match === null) {
                $kept[] = $words[$i];
                $i++;

                continue;
            }

            foreach ((array) $this->synonyms[$match['synonym']] as $key => $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (! in_array($item, $filters[$key] ?? [], true)) {
                        $filters[$key][] = $item;
                    }
                }
            }

            $i += $match['length'];
        }

        return ['text' => implode(' ', $kept), 'filters' => $filters];
    }

    /** A word or phrase as it is compared: edge punctuation dropped, accents folded where asked, lowercased. */
    public function normalize(string $word): string
    {
        $word = SearchText::trimEdges($word);

        return $this->ignoreAccents ? SearchText::fold($word) : mb_strtolower($word);
    }

    /**
     * Each synonym's normalized words, longest phrase first so "plug in hybrid" wins over "hybrid".
     *
     * @return list<array{synonym: string, words: list<string>}>
     */
    private function phrases(): array
    {
        $phrases = [];

        foreach (array_keys($this->synonyms) as $synonym) {
            $words = array_values(array_filter(
                array_map($this->normalize(...), preg_split('/\s+/u', trim((string) $synonym), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                fn (string $word): bool => $word !== '',
            ));

            if ($words !== []) {
                $phrases[] = ['synonym' => (string) $synonym, 'words' => $words];
            }
        }

        // Stable, so phrases of one length keep the order Core declared them in.
        usort($phrases, fn (array $a, array $b): int => count($b['words']) <=> count($a['words']));

        return $phrases;
    }

    /**
     * @param  list<string>  $normalized
     * @param  list<array{synonym: string, words: list<string>}>  $phrases
     * @return array{synonym: string, length: int}|null
     */
    private function matchAt(array $normalized, int $offset, array $phrases): ?array
    {
        foreach ($phrases as $phrase) {
            $length = count($phrase['words']);

            if (array_slice($normalized, $offset, $length) === $phrase['words']) {
                return ['synonym' => $phrase['synonym'], 'length' => $length];
            }
        }

        return null;
    }
}
