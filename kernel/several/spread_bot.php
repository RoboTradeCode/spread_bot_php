<?php

use Src\Ccxt;
use Src\Configurator;
use Src\Debug;
use Src\Exchanges\Exmo;
use Src\Filter;
use Src\SpreadBot\MemcachedData;
use Src\SpreadBot\SpreadBot;
use Src\TimeV2;

require dirname(__DIR__, 2) . '/index.php';

if (!isset($argv[1]))
    die('Set parameter: symbol');

$symbol = $argv[1];

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);
$memcached->flush();

$config = Configurator::getConfigFromFile('several_spread_bot');

$exchange = $config['exchange'];
$market_discovery_exchange = $config['market_discovery_exchange'];
$expired_orderbook_time = $config['expired_orderbook_time'];
$debug = $config['debug'];
$sleep = $config['sleep'];
$max_deal_amount = $config['max_deal_amount'];
$fees = $config['fees'];
$max_orders = $config['max_orders'];
$min_profits = $config['min_profits'];
$keys = $config['keys'][$exchange][$symbol];
$markets = $config['markets'][$exchange];

$assets = explode('/', $symbol);
$market = $markets[array_search($symbol, array_column($markets, 'common_symbol'))];
list($base_asset, $quote_asset) = explode('/', $symbol);

Debug::switchOn($debug);

$multi_core = new MemcachedData($exchange, $market_discovery_exchange, $markets, $expired_orderbook_time);

$spread_bot = new SpreadBot($exchange, $market_discovery_exchange);

$bot = new Exmo($exchange, $keys[0]['api_key'], $keys[0]['secret_key']);
$bot_only_for_balances = new Ccxt($exchange, $keys[1]['api_key'], $keys[1]['secret_key']);
$bot_only_for_get_open_orders = new Ccxt($exchange, $keys[2]['api_key'], $keys[2]['secret_key']);

$balances = $bot_only_for_balances->getBalances($assets);

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
            $max_deal_amounts = Filter::getDealAmountByRate($my_rates, $max_deal_amount);

            $min_profit = $spread_bot->getMinProfit($balances, $min_profits, $my_rates, $base_asset, $quote_asset);

            if (!empty($orderbooks[$symbol][$exchange]) && !empty($orderbooks[$symbol][$market_discovery_exchange])) {
                $exchange_orderbook = $spread_bot->getBestOrderbook($orderbooks, $symbol);
                $market_discovery = $spread_bot->getBestOrderbook($orderbooks, $symbol, false);

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

                $price = $spread_bot->incrementNumber($exchange_orderbook['bid'], $market['price_increment']);

                if (
                    $spread_bot->isCreateBuyOrder(
                        $exchange_orderbook, $profit, $balances, $quote_asset,
                        $max_deal_amounts, $real_orders_for_symbol, $max_orders, $price
                    )
                ) {
                    $side = 'buy';
                    $amount = $spread_bot->incrementNumber($max_deal_amounts[$base_asset], $market['amount_increment']);

                    if (
                        $create_order = $bot->createOrder(
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

                    $balances = $bot_only_for_balances->getBalances($assets);

                    Debug::printAll($debug_data, $balances, $real_orders, $exchange);
                    Debug::echo('[INFO] Create: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                }

                $price = $spread_bot->incrementNumber($exchange_orderbook['ask'], $market['price_increment']);

                if (
                    $spread_bot->isCreateSellOrder(
                        $exchange_orderbook, $profit, $balances, $base_asset,
                        $max_deal_amounts, $real_orders_for_symbol, $max_orders, $price
                    )
                ) {
                    $side = 'sell';
                    $amount = $spread_bot->incrementNumber($max_deal_amounts[$base_asset], $market['amount_increment']);

                    if (
                        $create_order = $bot->createOrder(
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

                    $balances = $bot_only_for_balances->getBalances($assets);

                    Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                    Debug::echo('[INFO] Create: ' . $symbol . ', ' . $side . ', ' . $amount . ', ' . $price);
                }

                $count_real_orders_for_symbol_sell = count($real_orders_for_symbol['sell']);

                if (
                    ($count_real_orders_for_symbol_sell > 0) &&
                    (($count_real_orders_for_symbol_sell >= $max_orders['sell']) || ($balances[$base_asset]['free'] <= $max_deal_amounts[$base_asset]))
                ) {
                    $cancel_the_farthest_sell_order = $spread_bot->cancelTheFarthestSellOrder($real_orders_for_symbol['sell']);

                    if (TimeV2::up(5, $cancel_the_farthest_sell_order['id'], true)) {
                        $bot->cancelOrder($cancel_the_farthest_sell_order['id'], $cancel_the_farthest_sell_order['symbol']);
                        unset($real_orders[$cancel_the_farthest_sell_order['id']]);

                        $balances = $bot_only_for_balances->getBalances($assets);

                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $cancel_the_farthest_sell_order['id'] . ', ' . $cancel_the_farthest_sell_order['symbol'] . ', ' . $cancel_the_farthest_sell_order['side'] . ', ' . $cancel_the_farthest_sell_order['amount'] . ', ' . $cancel_the_farthest_sell_order['price']);
                    }
                }

                $need_get_balance = false;

                foreach ($real_orders_for_symbol['sell'] as $real_orders_for_symbol_sell)
                    if (
                        ($real_orders_for_symbol_sell['price'] < $profit['ask']) &&
                        TimeV2::up(5, $real_orders_for_symbol_sell['id'], true)
                    ) {
                        $bot->cancelOrder($real_orders_for_symbol_sell['id'], $real_orders_for_symbol_sell['symbol']);
                        unset($real_orders[$real_orders_for_symbol_sell['id']]);

                        $need_get_balance = true;
                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $real_orders_for_symbol_sell['id'] . ', ' . $real_orders_for_symbol_sell['symbol'] . ', ' . $real_orders_for_symbol_sell['side'] . ', ' . $real_orders_for_symbol_sell['amount'] . ', ' . $real_orders_for_symbol_sell['price']);
                    }

                if ($need_get_balance)
                    $balances = $bot_only_for_balances->getBalances($assets);

                $count_real_orders_for_symbol_buy = count($real_orders_for_symbol['buy']);

                if (
                    ($count_real_orders_for_symbol_buy > 0) &&
                    (($count_real_orders_for_symbol_buy >= $max_orders['buy']) || ($balances[$quote_asset]['free'] <= $max_deal_amounts[$quote_asset]))
                ) {
                    $cancel_the_farthest_buy_order = $spread_bot->cancelTheFarthestBuyOrder($real_orders_for_symbol['buy']);

                    if (TimeV2::up(5, $cancel_the_farthest_buy_order['id'], true)) {
                        $bot->cancelOrder($cancel_the_farthest_buy_order['id'], $cancel_the_farthest_buy_order['symbol']);
                        unset($real_orders[$cancel_the_farthest_buy_order['id']]);

                        $balances = $bot_only_for_balances->getBalances($assets);

                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['sell'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $cancel_the_farthest_buy_order['id'] . ', ' . $cancel_the_farthest_buy_order['symbol'] . ', ' . $cancel_the_farthest_buy_order['side'] . ', ' . $cancel_the_farthest_buy_order['amount'] . ', ' . $cancel_the_farthest_buy_order['price']);
                    }
                }

                $need_get_balance = false;

                foreach ($real_orders_for_symbol['buy'] as $real_orders_for_symbol_buy)
                    if (
                        ($real_orders_for_symbol_buy['price'] > $profit['bid']) &&
                        TimeV2::up(5, $real_orders_for_symbol_buy['id'], true)
                    ) {
                        $bot->cancelOrder($real_orders_for_symbol_buy['id'], $real_orders_for_symbol_buy['symbol']);
                        unset($real_orders[$real_orders_for_symbol_buy['id']]);

                        $need_get_balance = true;
                        Debug::printAll($debug_data, $balances, $real_orders_for_symbol['buy'], $exchange);
                        Debug::echo('[INFO] Cancel: ' . $real_orders_for_symbol_buy['id'] . ', ' . $real_orders_for_symbol_buy['symbol'] . ', ' . $real_orders_for_symbol_buy['side'] . ', ' . $real_orders_for_symbol_buy['amount'] . ', ' . $real_orders_for_symbol_buy['price']);
                    }

                if ($need_get_balance)
                    $balances = $bot_only_for_balances->getBalances($assets);
            } elseif (TimeV2::up(1, 'empty_orderbooks' . $symbol)) {
                if (empty($orderbooks[$symbol][$exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$exchange]');
                if (empty($orderbooks[$symbol][$market_discovery_exchange])) Debug::echo('[WARNING] Empty $orderbooks[$symbol][$market_discovery_exchange]');
            }
        } elseif (TimeV2::up(1, 'empty_data')) Debug::echo('[WARNING] Empty $balances');
    } elseif (TimeV2::up(1, 'no_rates')) Debug::echo('[WARNING] No rates');

    if (TimeV2::up(5, 'balance'))
        $balances = $bot_only_for_balances->getBalances($assets);

    if (TimeV2::up(5, 'get_open_orders')) {
        $or = $bot_only_for_balances->getOpenOrders();

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
