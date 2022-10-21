<?php

use Src\Ccxt;
use Src\Configurator;
use Src\Debug;
use Src\Exchanges\Exmo;
use Src\Filter;
use Src\SpreadBot\MemcachedData;
use Src\SpreadBot\SpreadBot;
use Src\SpreadBot\SpreadBotMarket;
use Src\TimeV2;

require dirname(__DIR__, 2) . '/index.php';

if (!isset($argv[1]))
    die('Set parameter: symbol');

$symbol = $argv[1];

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);
$memcached->flush();

$config = Configurator::getConfigFromFile('common_spread_bot');

$exchange = $config['exchange'];
$market_discovery_exchange = $config['market_discovery_exchange'];
$expired_orderbook_time = $config['expired_orderbook_time'];
$debug = $config['debug'];
$sleep = $config['sleep'];
$aggressive = $config['aggressive'];
$min_deal_amount = $config['min_deal_amount'];
$max_deal_amount = $config['max_deal_amount'];
$balance_limitation_in_usd = $config['balance_limitation_in_usd'];
$balance_limitation = $config['balance_limitation'];
$fees = $config['fees'];
$max_orders = $config['max_orders'];
$order_profits = $config['order_profits'];
$min_profits = $config['min_profits'];
$keys = $config['keys'][$exchange][$symbol];
$markets = $config['markets'][$exchange];

$assets = explode('/', $symbol);
$market = $markets[array_search($symbol, array_column($markets, 'common_symbol'))];
list($base_asset, $quote_asset) = explode('/', $symbol);

Debug::switchOn($debug);

$multi_core = new MemcachedData($exchange, $market_discovery_exchange, $markets, $expired_orderbook_time);

$spread_bot = new SpreadBot($exchange, $market_discovery_exchange);
$spread_bot_market = new SpreadBotMarket($exchange, $market_discovery_exchange);

$bot_create_only = new Exmo($keys[0]['api_key'], $keys[0]['secret_key']);
$bot = new Ccxt($exchange, $keys[1]['api_key'], $keys[1]['secret_key']);
$bot_only_for_balances_and_open_orders = new Ccxt($exchange, $keys[2]['api_key'], $keys[2]['secret_key']);

$bot_only_for_balances_and_open_orders->cancelAllOrder();
$balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

$real_orders = [];

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
            $max_deal_amounts = Filter::getDealAmountByRate($my_rates, $max_deal_amount);
            $balance_limitations = Filter::getDealAmountByRate($my_rates, $balance_limitation_in_usd);

            $min_profit = $spread_bot->getMinProfit($balances, $min_profits, $my_rates, $base_asset, $quote_asset);

            if (!empty($orderbooks[$symbol][$exchange]) && !empty($orderbooks[$symbol][$market_discovery_exchange])) {
                $exchange_orderbook = $spread_bot->getBestOrderbook($orderbooks, $symbol);
                $market_discovery = $spread_bot->getBestOrderbook($orderbooks, $symbol, false);

                // SPREAD BOT LIMIT
                $profit = $spread_bot->getProfit($market_discovery, $min_profit);
                $real_orders_for_symbol = $spread_bot->filterOrdersBySideAndSymbol($real_orders, $symbol);

                $debug_data = [
                    'symbol' => $symbol,
                    'exchange_bid' => $exchange_orderbook['bid'],
                    'exchange_ask' => $exchange_orderbook['ask'],
                    'market_discovery_bid' => $market_discovery['bid'],
                    'market_discovery_ask' => $market_discovery['ask'],
                    'profit_bid' => $profit['bid'],
                    'profit_ask' => $profit['ask'],
                    'min_profit_bid' => $min_profit['bid'],
                    'min_profit_ask' => $min_profit['ask'],
                    'real_orders_for_symbol_sell' => count($real_orders_for_symbol['sell']),
                    'real_orders_for_symbol_buy' => count($real_orders_for_symbol['buy']),
                    'must_order_sell' => $max_orders['sell'],
                    'must_order_buy' => $max_orders['buy']
                ];

                $price = $spread_bot->incrementNumber($profit['bid'] * (1 - $order_profits['bid']['start'] / 100), $market['price_increment']);

                if (
                    $spread_bot->isCreateBuyOrder($balances, $quote_asset, $max_deal_amounts, $real_orders_for_symbol, $max_orders, $price) &&
                    ($balances[$quote_asset]['free'] > ($balance_limitation[$quote_asset] * $balance_limitations[$quote_asset]) || $balances[$base_asset]['free'] * 0.99 < ($balance_limitation[$base_asset] * $balance_limitations[$base_asset]))
                ) {
                    $side = 'buy';
                    $amount = $spread_bot->incrementNumber($max_deal_amounts[$base_asset], $market['amount_increment']);

                    if (
                        $create_order = $bot_create_only->createOrder(
                            $symbol,
                            'limit',
                            $side,
                            $amount,
                            $price,
                            'post_only'
                        )
                    ) {
                        $real_orders[$create_order['id']] = [
                            'id' => $create_order['id'],
                            'symbol' => $create_order['symbol'],
                            'side' => $create_order['side'],
                            'amount' => $create_order['amount'],
                            'price' => $create_order['price'],
                            'status' => $create_order['status'],
                        ];
                    }

                    $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

                    Debug::printAll($debug_data, $balances, $real_orders, $exchange);
                    Debug::echo('[INFO] Create: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                }

                $price = $spread_bot->incrementNumber($profit['ask'] * (1 + $order_profits['ask']['start'] / 100), $market['price_increment']);

                if (
                    $spread_bot->isCreateSellOrder($balances, $base_asset, $max_deal_amounts, $real_orders_for_symbol, $max_orders, $price) &&
                    ($balances[$base_asset]['free'] > ($balance_limitation[$base_asset] * $balance_limitations[$base_asset]) || $balances[$quote_asset]['free'] * 0.99 < ($balance_limitation[$quote_asset] * $balance_limitations[$quote_asset]))
                ) {
                    $side = 'sell';
                    $amount = $spread_bot->incrementNumber($max_deal_amounts[$base_asset], $market['amount_increment']);

                    if (
                        $create_order = $bot_create_only->createOrder(
                            $symbol,
                            'limit',
                            $side,
                            $amount,
                            $price,
                            'post_only'
                        )
                    ) {
                        $real_orders[$create_order['id']] = [
                            'id' => $create_order['id'],
                            'symbol' => $create_order['symbol'],
                            'side' => $create_order['side'],
                            'amount' => $create_order['amount'],
                            'price' => $create_order['price'],
                            'status' => $create_order['status'],
                        ];
                    }

                    $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

                    Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                    Debug::echo('[INFO] Create: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                }

                $need_get_balance = false;

                foreach ($real_orders_for_symbol['sell'] as $real_orders_for_symbol_sell)
                    if (
                        !(($real_orders_for_symbol_sell['price'] * (1 - $order_profits['ask']['end'] / 100)) < $profit['ask'] && ($real_orders_for_symbol_sell['price'] * (1 + $order_profits['ask']['start'])) > $profit['ask']) &&
                        TimeV2::up(5, $real_orders_for_symbol_sell['id'], true)
                    ) {
                        $bot->cancelOrder($real_orders_for_symbol_sell['id'], $real_orders_for_symbol_sell['symbol']);
                        unset($real_orders[$real_orders_for_symbol_sell['id']]);

                        $need_get_balance = true;
                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $real_orders_for_symbol_sell['id'] . ', ' . $real_orders_for_symbol_sell['symbol'] . ', ' . $real_orders_for_symbol_sell['side'] . ', ' . $real_orders_for_symbol_sell['amount'] . ', ' . $real_orders_for_symbol_sell['price']);
                    }

                foreach ($real_orders_for_symbol['buy'] as $real_orders_for_symbol_buy)
                    if (
                        !(($real_orders_for_symbol_buy['price'] * (1 - $order_profits['bid']['start'] / 100)) < $profit['bid'] && ($real_orders_for_symbol_buy['price'] * (1 + $order_profits['bid']['end'])) > $profit['bid']) &&
                        TimeV2::up(5, $real_orders_for_symbol_buy['id'], true)
                    ) {
                        $bot->cancelOrder($real_orders_for_symbol_buy['id'], $real_orders_for_symbol_buy['symbol']);
                        unset($real_orders[$real_orders_for_symbol_buy['id']]);

                        $need_get_balance = true;
                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['buy'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $real_orders_for_symbol_buy['id'] . ', ' . $real_orders_for_symbol_buy['symbol'] . ', ' . $real_orders_for_symbol_buy['side'] . ', ' . $real_orders_for_symbol_buy['amount'] . ', ' . $real_orders_for_symbol_buy['price']);
                    }
                // SPREAD BOT LIMIT


                // SPREAD BOT MARKET
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
                        $balances[$quote_asset]['free'] * 0.99 < (($balance_limitation[$quote_asset] * $balance_limitations[$quote_asset]) - $min_deal_amounts[$quote_asset])
                    ) {
                        $side = 'sell';
                        $price = $spread_bot_market->incrementNumber($profit_bid_with_fee, $market['price_increment']);
                        $amount = $spread_bot_market->incrementNumber(
                            min(
                                $sum,
                                $balances[$base_asset]['free'] * 0.99,
                                (($balance_limitation[$quote_asset] * $balance_limitations[$quote_asset]) - $balances[$quote_asset]['free'] * 0.99) / $price
                            ),
                            $market['amount_increment']
                        );

                        if ($amount >= $min_deal_amounts[$base_asset]) {
                            $create_order = $bot_create_only->createOrder(
                                $symbol,
                                'limit',
                                $side,
                                $amount,
                                $price,
                                'ioc'
                            );

                            $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

                            Debug::printAll($debug_data, $balances, [], $exchange);
                            Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                        }
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
                        $balances[$base_asset]['free'] * 0.99 < (($balance_limitation[$base_asset] * $balance_limitations[$base_asset]) - $min_deal_amounts[$base_asset])
                    ) {
                        $side = 'buy';
                        $price = $spread_bot_market->incrementNumber($profit_ask_with_fee, $market['price_increment']);
                        $amount = $spread_bot_market->incrementNumber(
                            min(
                                $sum,
                                $balances[$quote_asset]['free'] * 0.99 / $price,
                                (($balance_limitation[$base_asset] * $balance_limitations[$base_asset]) - $balances[$base_asset]['free'] * 0.99) / $price
                            ),
                            $market['amount_increment']
                        );

                        if ($amount >= $min_deal_amounts[$base_asset]) {
                            $create_order = $bot_create_only->createOrder(
                                $symbol,
                                'limit',
                                $side,
                                $amount,
                                $price,
                                'ioc'
                            );

                            $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

                            Debug::printAll($debug_data, $balances, [], $exchange);
                            Debug::echo('[INFO] Create Market: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                        }
                    }
                }
                // SPREAD BOT MARKET

                if ($need_get_balance)
                    $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);
            } elseif (TimeV2::up(1, 'empty_orderbooks' . $symbol)) {
                if (empty($orderbooks[$symbol][$exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$exchange]');
                if (empty($orderbooks[$symbol][$market_discovery_exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$market_discovery_exchange]');
            }
        } elseif (TimeV2::up(1, 'empty_data')) Debug::echo('[WARNING] Empty $balances');
    } elseif (TimeV2::up(1, 'no_rates')) Debug::echo('[WARNING] No rates');

    if (TimeV2::up(5, 'balance'))
        $balances = $bot_only_for_balances_and_open_orders->getBalances($assets);

    if (TimeV2::up(5, 'get_open_orders')) {
        $or = $bot_only_for_balances_and_open_orders->getOpenOrders();

        $real_orders = [];

        foreach ($or as $item) {
            $real_orders[$item['id']] = [
                'id' => $item['id'],
                'symbol' => $item['symbol'],
                'side' => $item['side'],
                'amount' => $item['amount'],
                'price' => $item['price'],
                'status' => $item['status'],
            ];
        }
    }

    if (TimeV2::up(60, 'algo_info'))
        Debug::printAll($debug_data ?? [], $balances, $real_orders, $exchange);
}
