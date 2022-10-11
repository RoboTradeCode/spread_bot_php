<?php

use Src\Ccxt;
use Src\Configurator;
use Src\Debug;
use Src\Filter;
use Src\SpreadBot\MemcachedData;
use Src\SpreadBot\SpreadBotMarket;
use Src\TimeV2;

require dirname(__DIR__) . '/index.php';

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);

$config = Configurator::getConfigFromFile('spread_bot_market');

$algorithm = $config['algorithm'];
$exchange = $config['exchange'];
$market_discovery_exchange = $config['market_discovery_exchange'];
$expired_orderbook_time = $config['expired_orderbook_time'];
$debug = $config['debug'];
$sleep = $config['sleep'];
$min_deal_amount = $config['min_deal_amount'];
$min_profits = $config['min_profits'];
$fees = $config['fees'];
$keys = $config['keys'][$exchange];
$markets = $config['markets'][$exchange];
$assets = $config['assets'][$exchange];
$common_symbols = array_column($markets, 'common_symbol');
$main_market = $common_symbols[0];
list($base_asset_main_market, $quote_asset_main_market) = explode('/', $main_market);

Debug::switchOn($debug);

$multi_core = new MemcachedData($exchange, $market_discovery_exchange, $markets, $expired_orderbook_time);

$spread_bot_market = new SpreadBotMarket($exchange, $market_discovery_exchange);

$bot = new Ccxt($exchange, $keys[0]['api_key'], $keys[0]['secret_key']);

$balances = $bot->cancelAllOrdersAndGetBalance($assets);
$bot_only_for_balances = new Ccxt($exchange, $keys[1]['api_key'], $keys[1]['secret_key']);

while (true) {
    usleep($sleep);

    $all_data = $multi_core->reformatAndSeparateData($memcached->getMulti($multi_core->keys));

    [$orderbooks, $rates] = [$all_data['orderbooks'], $all_data['rates']];

    if (
        !empty($rates[$base_asset_main_market]['USD']) &&
        !empty($rates[$quote_asset_main_market]['USD'])
    ) {
        if ($balances) {
            $rates = [
                $base_asset_main_market => $rates[$base_asset_main_market]['USD'],
                $quote_asset_main_market => $rates[$quote_asset_main_market]['USD']
            ];
            $min_deal_amounts = Filter::getDealAmountByRate($rates, $min_deal_amount);

            $min_profit = $spread_bot_market->getMinProfit($balances, $min_profits, $rates, $base_asset_main_market, $quote_asset_main_market);

            foreach ($common_symbols as $symbol)
                if (!empty($orderbooks[$symbol][$exchange]) && !empty($orderbooks[$symbol][$market_discovery_exchange])) {
                    $market = $spread_bot_market->getMarket($markets, $symbol);

                    $market_discovery = $spread_bot_market->getBestOrderbook($orderbooks, $symbol, false);

                    $profit = $spread_bot_market->getProfit($market_discovery, $min_profit[$symbol]);

                    $exchange_orderbook = $spread_bot_market->getBestOrderbook($orderbooks, $symbol);

                    list($base_asset, $quote_asset) = explode('/', $symbol);

                    $debug_data = [
                        'symbol' => $symbol,
                        'market_discovery_bid' => $market_discovery['bid'],
                        'market_discovery_ask' => $market_discovery['ask'],
                        'min_profit_bid' => $min_profit[$symbol]['bid'],
                        'min_profit_ask' => $min_profit[$symbol]['ask'],
                        'profit_bid' => $profit['bid'],
                        'profit_ask' => $profit['ask'],
                        'exchange_bid' => $exchange_orderbook['bid'],
                        'exchange_ask' => $exchange_orderbook['ask'],
                        'profit_bid_with_fee' => $profit['bid'] * (1 + $fees[$exchange] / 100),
                        'profit_ask_with_fee' => $profit['ask'] * (1 - $fees[$exchange] / 100)
                    ];

                    if ($exchange_orderbook['bid'] > $profit['bid'] * (1 + $fees[$exchange] / 100)) {
                        $sum = 0;
                        foreach ($orderbooks[$symbol][$exchange]['bids'] as $bid) {
                            if ($bid[0][0] > $profit['bid']) {
                                $sum += $bid[0][1];
                                break;
                            }
                        }

                        if ($balances[$base_asset]['free'] * 0.99 > $min_deal_amounts[$base_asset] && $sum != 0) {
                            $side = 'sell';
                            $price = $spread_bot_market->incrementNumber($exchange_orderbook['ask'], $market['price_increment']);
                            $amount = $spread_bot_market->incrementNumber(min($sum, $balances[$base_asset]['free'] * 0.99), $market['amount_increment']);

                            $create_order = $bot->createOrder(
                                $symbol,
                                'market',
                                $side,
                                $amount,
                                $price
                            );

                            $balances = $bot_only_for_balances->getBalances($assets);

                            Debug::printAll($debug_data, $balances, [], $exchange);
                            Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                        }
                    }

                    if ($exchange_orderbook['ask'] < $profit['ask'] * (1 - $fees[$exchange] / 100)) {
                        $sum = 0;
                        foreach ($orderbooks[$symbol][$exchange]['asks'] as $ask) {
                            if ($ask[0][0] < $profit['ask']) {
                                $sum += $ask[0][1];
                                break;
                            }
                        }

                        if ($balances[$quote_asset]['free'] * 0.99 > $min_deal_amounts[$quote_asset] && $sum != 0) {
                            $side = 'buy';
                            $price = $spread_bot_market->incrementNumber($exchange_orderbook['ask'], $market['price_increment']);
                            $amount = $spread_bot_market->incrementNumber(min($sum, $balances[$quote_asset]['free'] * 0.99 / $price), $market['amount_increment']);

                            $create_order = $bot->createOrder(
                                $symbol,
                                'market',
                                $side,
                                $amount,
                                $price
                            );

                            $balances = $bot_only_for_balances->getBalances($assets);

                            Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                        }
                    }
                } elseif (TimeV2::up(1, 'empty_orderbooks' . $symbol)) {
                    if (empty($orderbooks[$symbol][$exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$exchange]');
                    if (empty($orderbooks[$symbol][$market_discovery_exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$market_discovery_exchange]');
                }
        } elseif (TimeV2::up(1, 'empty_data')) Debug::echo('[WARNING] Empty $balances');
    } elseif (TimeV2::up(1, 'no_rates')) Debug::echo('[WARNING] No rates');

    if (TimeV2::up(5, 'balance'))
        $balances = $bot_only_for_balances->getBalances($assets);
}