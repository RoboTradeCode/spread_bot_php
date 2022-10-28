<?php

use Src\Configurator;
use Src\Pm2;

require dirname(__DIR__, 3) . '/index.php';

$config = Configurator::getConfigFromFile('common_spread_bot');

$exchange = $config['exchange'];
$markets = $config['use_markets'];

foreach ($markets as $market) {
    Pm2::start(__DIR__ . '/spread_bot.php', 'SPREAD BOT ' . $market . ' ' . $exchange, 'algorithm', [$market]);
    echo '[' . date('Y-m-d H:i:s') . '] Start: ' . $market . PHP_EOL;
}