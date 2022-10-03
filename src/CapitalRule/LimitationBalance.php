<?php

namespace Src\CapitalRule;

class LimitationBalance
{

    public static function get(array $balances, array $assets, array $common_symbols, array $max_deal_amounts, array $amount_limitations): array
    {
        $sell_assets = [];
        foreach ($max_deal_amounts as $asset => $deal_amount)
            $sell_assets[$asset] = intval($balances[$asset]['total'] / $deal_amount);

        $max_count_buy_amount_limitations = [];
        foreach ($amount_limitations as $asset => $amount_limitation)
            $max_count_buy_amount_limitations[$asset] = ($balances[$asset]['total'] <= $amount_limitation) ? intval($amount_limitation / $max_deal_amounts[$asset]) : 0;

        $count_orders = [];
        foreach ($common_symbols as $common_symbol) {
            $count_orders[$common_symbol]['sell'] = 0;
            $count_orders[$common_symbol]['buy'] = 0;
        }

        foreach ($sell_assets as $asset => $count)
            if ($count != 0)
                while (true)
                    foreach ($common_symbols as $common_symbol) {
                        list($base_asset, $quote_asset) = explode('/', $common_symbol);

                        if ($base_asset == $asset) {
                            $count_orders[$common_symbol]['sell']++;
                            $count--;
                        } elseif ($quote_asset == $asset) {
                            $count_orders[$common_symbol]['buy']++;
                            $count--;
                        }

                        if ($count == 0) break 2;
                    }

        $count_buy_orders = [];
        foreach ($assets as $asset)
            $count_buy_orders[$asset] = 0;

        foreach ($count_orders as $symbol => $count_order) {
            list($base_asset, $quote_asset) = explode('/', $symbol);

            $count_buy_orders[$base_asset] += $count_order['buy'];
            $count_buy_orders[$quote_asset] += $count_order['sell'];
        }

        foreach ($assets as $asset)
            if ($count_buy_orders[$asset] > $max_count_buy_amount_limitations[$asset]) {
                $minus = $count_buy_orders[$asset] - $max_count_buy_amount_limitations[$asset];

                while (true) {
                    $potential = [];

                    foreach ($count_orders as $common_symbol => $count_order) {
                        list($base_asset, $quote_asset) = explode('/', $common_symbol);

                        if ($quote_asset == $asset) {
                            $potential[$common_symbol] = $count_order['sell'];
                        } elseif ($base_asset == $asset) {
                            $potential[$common_symbol] = $count_order['buy'];
                        }
                    }

                    if ($potential) {
                        $common_symbol_key = array_keys($potential, max($potential))[0];

                        list($base_asset, $quote_asset) = explode('/', $common_symbol_key);

                        if ($quote_asset == $asset) {
                            $count_orders[$common_symbol_key]['sell']--;
                            $minus--;
                        } elseif ($base_asset == $asset) {
                            $count_orders[$common_symbol_key]['buy']--;
                            $minus--;
                        }
                    }

                    if ($minus == 0) break;
                }
            }

        return $count_orders;
    }

}