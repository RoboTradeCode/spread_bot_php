<?php

namespace Src\SpreadBot;

class SpreadBotMarket
{
    private string $exchange;
    private string $market_discovery_exchange;

    public function __construct(string $exchange, string $market_discovery_exchange)
    {
        $this->exchange = $exchange;
        $this->market_discovery_exchange = $market_discovery_exchange;
    }

    public function getMinProfit(array $balances, array $min_profits, array $rates, string $base_asset_main_market, string $quote_asset_main_market): array
    {
        $base_in_usd = $balances[$base_asset_main_market]['total'] * $rates[$base_asset_main_market];
        $quote_in_usd = $balances[$quote_asset_main_market]['total'] * $rates[$quote_asset_main_market];

        foreach ($min_profits as $K_btc_value => $profit_bid_and_ask)
            if ($K_btc_value >= round($base_in_usd / ($base_in_usd + $quote_in_usd), 4))
                return [
                    'bid' => $profit_bid_and_ask['profit_bid'],
                    'ask' => $profit_bid_and_ask['profit_ask']
                ];

        return $format_min_profit ?? [];
    }

    public function getBestOrderbook(array $orderbooks, string $symbol, bool $is_exchange = true): array
    {
        $exchange = $is_exchange ? $this->exchange : $this->market_discovery_exchange;

        return [
            'bid' => $orderbooks[$symbol][$exchange]['bids'][0][0],
            'ask' => $orderbooks[$symbol][$exchange]['asks'][0][0],
        ];
    }

    public function getProfit(array $market_discovery, array $min_profit): array
    {
        return [
            'bid' => $market_discovery['bid'] + ($market_discovery['bid'] * $min_profit['bid'] / 100),
            'ask' => $market_discovery['ask'] - ($market_discovery['ask'] * $min_profit['ask'] / 100),
        ];
    }

    public function incrementNumber(float $number, float $increment): float
    {
        return $increment * floor($number / $increment);
    }

    public function getMarket(array $markets, string $symbol)
    {
        return $markets[array_search($symbol, array_column($markets, 'common_symbol'))] ?? [];
    }
}