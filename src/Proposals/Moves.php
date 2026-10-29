<?php

namespace Inly\CoreWeb\Proposals;

/** Which items of a reordered list changed places, as opposed to shifting because others came or went. */
final class Moves
{
    /**
     * The rows that changed places: those outside the longest run of kept rows still in their old order.
     *
     * @param  list<string|int>  $old
     * @param  list<string|int>  $new
     * @return list<string>
     */
    public static function moved(array $old, array $new): array
    {
        $position = array_flip(array_map('strval', $old));
        $kept = array_values(array_filter(array_map('strval', $new), fn (string $key) => isset($position[$key])));
        $tails = [];
        $previous = [];

        foreach ($kept as $i => $key) {
            $low = 0;
            $high = count($tails);

            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                if ($position[$kept[$tails[$mid]]] < $position[$key]) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            $previous[$i] = $low > 0 ? $tails[$low - 1] : null;
            $tails[$low] = $i;
        }

        $inOrder = [];

        for ($i = $tails === [] ? null : $tails[count($tails) - 1]; $i !== null; $i = $previous[$i]) {
            $inOrder[$kept[$i]] = true;
        }

        return array_values(array_filter($kept, fn (string $key) => ! isset($inOrder[$key])));
    }
}
