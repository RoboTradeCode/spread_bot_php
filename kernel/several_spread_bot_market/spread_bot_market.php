<?php

use Src\Configurator;
use Src\Debug;
use Src\Exchanges\Exmo;
use Src\Filter;
use Src\SpreadBot\MemcachedData;
use Src\SpreadBot\SpreadBotMarket;
use Src\TimeV2;

require dirname(__DIR__, 2) . '/index.php';

if (!isset($argv[1]))
    die('Set parameter: symbol');

$symbol = $argv[1];

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);

$config = Configurator::getConfigFromFile('several_spread_bot_market');

$exchange = $config['exchange'];
$market_discovery_exchange = $config['market_discovery_exchange'];
$expired_orderbook_time = $config['expired_orderbook_time'];
$debug = $config['debug'];
$sleep = $config['sleep'];
$min_deal_amount = $config['min_deal_amount'];
$balance_limitation_in_usd = $config['balance_limitation_in_usd'];
$fees = $config['fees'];
$min_profits = $config['min_profits'];
$keys = $config['keys'][$exchange][$symbol];
$markets = $config['markets'][$exchange];

$assets = explode('/', $symbol);
$market = $markets[array_search($symbol, array_column($markets, 'common_symbol'))];
list($base_asset, $quote_asset) = explode('/', $symbol);

Debug::switchOn($debug);

$multi_core = new MemcachedData($exchange, $market_discovery_exchange, $markets, $expired_orderbook_time);

$spread_bot_market = new SpreadBotMarket($exchange, $market_discovery_exchange);

$bot = new Exmo($keys[0]['api_key'], $keys[0]['secret_key']);
$bot_only_for_balances = new Exmo($keys[1]['api_key'], $keys[1]['secret_key']);

$balances = $bot_only_for_balances->getBalances($assets);

while (true) {
    usleep($sleep);

    $all_data = $multi_core->reformatAndSeparateData($memcached->getMulti($multi_core->keys));

    [$orderbooks, $rates] = [$all_data['orderbooks'], $all_data['rates']];

    if (
        !empty($rates[$base_asset]['USD']) &&
        !empty($rates[$quote_asset]['USD'])
    ) {
        if ($balances) {
            $my_rates = [
                $base_asset => $rates[$base_asset]['USD'],
                $quote_asset => $rates[$quote_asset]['USD']
            ];
            $min_deal_amounts = Filter::getDealAmountByRate($my_rates, $min_deal_amount);
            $balance_limitations = Filter::getDealAmountByRate($my_rates, $balance_limitation_in_usd);

            $min_profit = $spread_bot_market->getMinProfit($balances, $min_profits, $my_rates, $base_asset, $quote_asset);

            if (!empty($orderbooks[$symbol][$exchange]) && !empty($orderbooks[$symbol][$market_discovery_exchange])) {
                $exchange_orderbook = $spread_bot_market->getBestOrderbook($orderbooks, $symbol);
                $market_discovery = $spread_bot_market->getBestOrderbook($orderbooks, $symbol, false);

                $profit = $spread_bot_market->getProfit($market_discovery, $min_profit);

                $debug_data = [
                    'symbol' => $symbol,
                    'market_discovery_bid' => $market_discovery['bid'],
                    'market_discovery_ask' => $market_discovery['ask'],
                    'min_profit_bid' => $min_profit['bid'],
                    'min_profit_ask' => $min_profit['ask'],
                    'profit_bid' => $profit['bid'],
                    'profit_ask' => $profit['ask'],
                    'exchange_bid' => $exchange_orderbook['bid'],
                    'exchange_ask' => $exchange_orderbook['ask'],
                    'profit_bid_with_fee' => $profit['bid'] * (1 + $fees[$exchange] / 100),
                    'profit_ask_with_fee' => $profit['ask'] * (1 - $fees[$exchange] / 100)
                ];

                $profit_bid_with_fee = $profit['bid'] * (1 + $fees[$exchange] / 100);
                if ($exchange_orderbook['bid'] > $profit_bid_with_fee) {
                    $sum = 0;
                    foreach ($orderbooks[$symbol][$exchange]['bids'] as $bid) {
                        if ($bid[0] > $profit_bid_with_fee) {
                            $sum += $bid[1];
                            break;
                        }
                    }

                    if (
                        $balances[$base_asset]['free'] * 0.99 > $min_deal_amounts[$base_asset] &&
                        $sum != 0 &&
                        $balances[$quote_asset]['free'] * 0.99 < ($balance_limitations[$quote_asset] - $min_deal_amounts[$quote_asset])
                    ) {
                        $side = 'sell';
                        $price = $spread_bot_market->incrementNumber($profit_bid_with_fee, $market['price_increment']);
                        $amount = $spread_bot_market->incrementNumber(
                            min(
                                $sum,
                                $balances[$base_asset]['free'] * 0.99,
                                ($balance_limitations[$quote_asset] - $balances[$quote_asset]['free'] * 0.99) / $price
                            ),
                            $market['amount_increment']
                        );

                        $create_order = $bot->createOrder(
                            $symbol,
                            'limit',
                            $side,
                            $amount,
                            $price,
                            'ioc'
                        );

                        $balances = $bot_only_for_balances->getBalances($assets);

                        Debug::printAll($debug_data, $balances, [], $exchange);
                        Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                    }
                }

                $profit_ask_with_fee = $profit['ask'] * (1 - $fees[$exchange] / 100);
                if ($exchange_orderbook['ask'] < $profit_ask_with_fee) {
                    $sum = 0;
                    foreach ($orderbooks[$symbol][$exchange]['asks'] as $ask) {
                        if ($ask[0] < $profit_ask_with_fee) {
                            $sum += $ask[1];
                            break;
                        }
                    }

                    if (
                        $balances[$quote_asset]['free'] * 0.99 > $min_deal_amounts[$quote_asset] &&
                        $sum != 0 &&
                        $balances[$base_asset]['free'] * 0.99 < ($balance_limitations[$base_asset] - $min_deal_amounts[$base_asset])
                    ) {
                        $side = 'buy';
                        $price = $spread_bot_market->incrementNumber($profit_ask_with_fee, $market['price_increment']);
                        $amount = $spread_bot_market->incrementNumber(
                            min(
                                $sum,
                                $balances[$quote_asset]['free'] * 0.99 / $price,
                                ($balance_limitations[$base_asset] - $balances[$base_asset]['free'] * 0.99) / $price
                            ),
                            $market['amount_increment']
                        );

                        $create_order = $bot->createOrder(
                            $symbol,
                            'limit',
                            $side,
                            $amount,
                            $price,
                            'ioc'
                        );

                        $balances = $bot_only_for_balances->getBalances($assets);


                        Debug::printAll($debug_data, $balances, [], $exchange);
                        Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                    }
                }

                if (TimeV2::up(60, 'algo_info' . $symbol, true))
                    Debug::printAll($debug_data ?? [], $balances, [], $exchange);
            } elseif (TimeV2::up(1, 'no_rates', true)) Debug::echo('[WARNING] No rates for ' . $base_asset . ' and ' . $quote_asset);
        } elseif (TimeV2::up(1, 'empty_orderbooks' . $symbol)) {
            if (empty($orderbooks[$symbol][$exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$exchange]');
            if (empty($orderbooks[$symbol][$market_discovery_exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$market_discovery_exchange]');
        }
    } elseif (TimeV2::up(1, 'empty_data', true)) Debug::echo('[WARNING] Empty $balances');

    if (TimeV2::up(5, 'balance', true))
        $balances = $bot_only_for_balances->getBalances($assets);
}