<?php

use Src\Ccxt;
use Src\Configurator;
use Src\DB;

require dirname(__DIR__, 2) . '/index.php';

DB::connect();

$config = Configurator::getConfigFromFile('spread_bot');
$exchange = $config['exchange'];
$keys = $config['keys'][$exchange];

$bot = new Ccxt($exchange, $keys[0]['api_key'], $keys[0]['secret_key']);

print_r($bot->getMyTrades(limit: 1)); echo PHP_EOL; die();
