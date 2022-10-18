<?php

use Src\Configurator;
use Src\Pm2;

require dirname(__DIR__, 2) . '/index.php';

$config = Configurator::getConfigFromFile('several_spread_bot');

$markets = $config['use_markets'];

foreach ($markets as $market) {
    Pm2::start(__DIR__ . '/spread_bot.php', 'SPREAD BOT LIMIT' . $market, 'algorithm', [$market]);
    echo '[' . date('Y-m-d H:i:s') . '] Start: ' . $market . PHP_EOL;
}