<?php

use Src\Ccxt;
use Src\Configurator;
use Src\Debug;
use Src\Filter;
use Src\SpreadBot\MemcachedData;

require dirname(__DIR__, 3) . '/index.php';

$redis = new Redis();
$redis->connect('localhost', 6379);

$config = Configurator::getConfigFromFile('common_spread_bot');

$exchange = $config['exchange'];
$market_discovery_exchange = $config['market_discovery_exchange'];
$expired_orderbook_time = $config['expired_orderbook_time'];
$use_markets = $config['use_markets'];
$sleep = $config['sleep'];
$key = $config['keys'][$exchange]['opposite_create_orders'];
$keys_market_discovery = $config['keys'][$market_discovery_exchange];
$assets = $config['assets'][$exchange];
$min_deal_amount = $config['min_deal_amount'];
$markets_discovery_exchange = $config['markets'][$market_discovery_exchange];

$bot = new Ccxt($exchange, $key['api_key'], $key['secret_key']);
$bot_market_discovery = new Ccxt($market_discovery_exchange, $keys_market_discovery['api_key'], $keys_market_discovery['secret_key']);

$balances_market_discovery = $bot_market_discovery->getBalances($assets);

$multi_core = new MemcachedData($exchange, $market_discovery_exchange, $use_markets, $expired_orderbook_time);

while (true) {
    usleep(0);

    if ($trades = array_reverse($bot->getMyTrades(60 * 1000, $use_markets, 100))) {

	$data_from_shared_memory = $redis->mGet($multi_core->keys);
	
	$formated_data = [];
	
	foreach ($data_from_shared_memory as $key => $value) {
		$formated_data[$multi_core->keys[$key]] = json_decode($value, true);
	}
	
    $all_data = $multi_core->reformatAndSeparateData($formated_data);

        // print_r($all_data); die();

        $rates = $all_data['rates'];

        $my_rates = [];
        foreach ($assets as $asset) {
            $my_rates[$asset] = $rates[$asset]['USD'];
        }

        $min_deal_amounts = Filter::getDealAmountByRate($my_rates, $min_deal_amount);

        $filter_trades = [];
        foreach ($trades as $trade) {
            if (empty($last_id)) {
                $last_id = $trade['id'];
                break;
            }

            if ($trade['id'] == $last_id)
                break;

            $filter_trades[] = $trade;
        }

        if (!empty($filter_trades)) {
            foreach (array_reverse($filter_trades) as $filter_trade) {
                $symbol = $filter_trade['symbol'];

                list($base_asset, $quote_asset) = explode('/', $symbol);

                $sell_or_buy = ($filter_trade['side'] == 'sell') ? 'buy' : 'sell';

                $can_trade = false;

                if ($sell_or_buy == 'sell') {
                    $can_trade = ($balances_market_discovery[$base_asset]['free'] * 0.99 > $filter_trade['amount']) && ($filter_trade['amount'] > $min_deal_amounts[$base_asset]);
                } else {
                    $can_trade = ($balances_market_discovery[$quote_asset]['free'] * 0.99 > $filter_trade['cost']) && ($filter_trade['cost'] > $min_deal_amounts[$quote_asset]);
                }

                if ($can_trade) {
                    Debug::echo('[INFO] BEFORE MUST CREATE: ' . $symbol);

                    $market_discovery_exchange_info = $markets_discovery_exchange[array_search($symbol, array_column($markets_discovery_exchange, 'common_symbol'))];

                    if (
                        $order_market_discovery = $bot_market_discovery->createOrder(
                            $symbol,
                            'market',
                            $sell_or_buy,
                            incrementNumber($filter_trade['amount'], $market_discovery_exchange_info['amount_increment'])
                        )
                    ) {
                        $last_id = $filter_trade['id'];
                        Debug::echo('[INFO] MARKET DISCOVERY Create market order: ' . $symbol . ', ' . $order_market_discovery['side'] . ', ' . $order_market_discovery['side'] . ', ' . $order_market_discovery['price']);
                    } else {
                        Debug::echo('[WARNING] Can not create order!!! ' . $symbol . json_encode($order_market_discovery));
                        usleep(500000);
                    }
                } else {
                    $last_id = $filter_trade['id'];
                }
            }

            $balances_market_discovery = $bot_market_discovery->getBalances($assets);
        }
        Debug::echo('[INFO] Have any trades: ' . count($trades));
    } else {
        Debug::echo('[INFO] No trades for last minute!!!');
    }
}

function incrementNumber(float $number, float $increment): float
{
    return $increment * floor($number / $increment);
}