<?php

namespace Src;

class FloatRound
{
    public static function compare(float $a, float $b, int $decimals = 8): bool
    {
        $int_a = intval($a);
        $int_b = intval($b);

        if (
            $int_a == $int_b &&
            bccomp(
                number_format($a - $int_a, $decimals),
                number_format($b - $int_b, $decimals),
                $decimals
            ) == 0
        ) return true;

        return false;
    }
}