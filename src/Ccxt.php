<?php

namespace Src;

use Exception;
use Throwable;

class Ccxt
{
    private mixed $exchange;

    public function __construct($exchange_name, $api_public = '', $api_secret = '', $api_password = '', $api_uid = '', $enableRateLimit = false)
    {
        $exchange_class = "\\ccxt\\" . $exchange_name;

        $this->exchange = new $exchange_class ([
            "apiKey" => $api_public,
            "secret" => $api_secret,
            "password" => $api_password,
            "uid" => $api_uid,
            "timeout" => 10000,
            "enableRateLimit" => $enableRateLimit
        ]);
    }

    public function getOpenOrders(): array
    {
        if ($this->exchange->has["fetchOpenOrders"] !== false) {
            try {
                return $this->exchange->fetch_open_orders();
            } catch (Throwable $e) {
                echo "[INFO] fetch_open_orders does not work without a symbol. Error: " . $e->getMessage() . PHP_EOL;
            }
        }

        return [];
    }

    public function getBalances(array $assets): array
    {
        try {
            $all_balances = $this->exchange->fetch_balance();

            foreach ($assets as $asset) {
                if (isset($all_balances[$asset])) $balances[$asset] = $all_balances[$asset];
                else $balances[$asset] = ["free" => 0, "used" => 0, "total" => 0];
            }
        } catch (Throwable $e) {
            echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
        }

        return $balances ?? [];
    }

    public function createOrder(string $symbol, string $type, string $side, float $amount, float $price): array
    {
        try {
            $order = $this->exchange->create_order($symbol, $type, $side, $amount, $price);
        } catch (Throwable $e) {
            echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
        }

        return $order ?? [];
    }

    public function cancelOrder(string $order_id, string $symbol): array
    {
        try {
            return $this->exchange->cancel_order($order_id, $symbol);
        } catch (Exception $e) {
            echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
        }

        return [];
    }

    public function cancelAllOrder(): array
    {
        try {
            if ($open_orders = $this->getOpenOrders())
                foreach ($open_orders as $open_order)
                    $this->exchange->cancel_order($open_order['id'], $open_order['symbol']);
        } catch (Exception $e) {
            echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
        }

        return [];
    }

    public function cancelAllOrdersAndGetBalance(array $assets): array
    {
        do {
            $this->cancelAllOrder();

            if ($balances = $this->getBalances($assets)) {
                $is_balance_used = false;

                foreach ($balances as $balance)
                    if (!FloatRound::compare($balance['used'], 0)) {
                        $is_balance_used = true;
                        break;
                    }

                if (!$is_balance_used) {
                    echo '[' . date('Y-m-d H:i:s') . '] [OK] All orders canceled' . PHP_EOL;

                    return $balances;
                }
            }

            echo '[' . date('Y-m-d H:i:s') . '] [WAIT] Try to close all orders' . PHP_EOL;

            sleep(5);
        } while (true);
    }
}