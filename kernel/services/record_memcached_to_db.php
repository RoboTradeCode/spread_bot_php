<?php

use Src\DB;

require dirname(__DIR__, 2) . '/index.php';

DB::connect();

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);

$use_exchanges = ['hitbtc', 'exmo'];
$use_markets = [
    'ETH/BTC',
    'ETH/USDT',
    'XRP/BTC',
    'BTC/USDT',
    'ADA/BTC',
    'XRP/USDT',
    'WAVES/BTC',
    'LTC/BTC',
    'XRP/ETH',
    'ALGO/USDT'
];

$keys = [];

foreach ($use_exchanges as $use_exchange) {
    $keys[] = $use_exchange . '_config';

    foreach ($use_markets as $use_market) {
        $keys[] = $use_exchange . '_' . $use_market . '_spreadBotLimitCalculations';
        $keys[] = $use_exchange . '_' . $use_market . '_spreadBotMarketCalculations';
    }
}

while (true) {
    sleep(1);

    if ($data = $memcached->getMulti($keys)) {
        foreach ($data as $key => $datum) {
            if (str_contains($key, '_config')) {
                list($exchange, $algo) = explode('/', $key);

                DB::replaceMemcachedConfigToDB($algo, $exchange, $datum);
            } elseif(str_contains($key, '_spreadBotLimitCalculations')) {
                list($exchange, $algo, $symbol) = explode('/', $key);

                foreach ($datum as $k => $item) {
                    DB::replaceMemcachedAlgoInnerCalculateToDB($algo .'_limit', $exchange, $symbol, $k, $item);
                }
            } elseif(str_contains($key, '_spreadBotMarketCalculations')) {
                list($exchange, $algo, $symbol) = explode('/', $key);

                foreach ($datum as $k => $item) {
                    DB::replaceMemcachedAlgoInnerCalculateToDB($algo .'_market', $exchange, $symbol, $k, $item);
                }
            }
        }
    }
}
