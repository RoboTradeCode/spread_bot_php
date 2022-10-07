<?php

use Src\DB;

require dirname(__DIR__, 2) . '/index.php';

DB::connect();

$exmo_key = 'exmo_orderbook_BTC/USDT';
$binance_key = 'binance_orderbook_BTC/USDT';

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);

while (true) {
    sleep(1);

    $data = $memcached->getMulti([$exmo_key, $binance_key]);

    if (!empty($data[$exmo_key]) && !empty($data[$binance_key])) {
        $exmo = [
            'ask' => $data[$exmo_key]['asks'][0][0],
            'bid' => $data[$exmo_key]['bids'][0][0]
        ];
        $binance = [
            'ask' => $data[$binance_key]['asks'][0][0],
            'bid' => $data[$binance_key]['bids'][0][0]
        ];

        DB::insertOrderbookSpreadExmoAndBinance(
            $exmo['ask'],
            $exmo['bid'],
            $binance['ask'],
            $binance['bid']
        );

        echo '[' . date('Y-m-d H:i:s') . '] Exmo ask: ' . $exmo['ask'] . '. Exmo bid: ' . $exmo['bid'] . '. Binance ask: ' . $binance['ask'] . '. Binance bid: ' . $binance['ask'] . PHP_EOL;
    } else
        echo '[' . date('Y-m-d H:i:s') . '] Can not get data' . PHP_EOL;
}