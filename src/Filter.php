<?php

namespace Src;

class Filter
{
    public static function getDealAmountByRate(array $rates, float $deal_amount): array
    {
        foreach ($rates as $asset => $rate)
            $max_deal_amounts[$asset] = round($deal_amount / $rate, 8);

        return $max_deal_amounts ?? [];
    }
}