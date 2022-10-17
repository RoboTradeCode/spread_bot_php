<?php

namespace Src\Exchanges;

use Src\Ccxt;
use Src\Http;
use Throwable;

class Exmo extends Ccxt
{
    private mixed $api_public;
    private mixed $api_secret;

    public function __construct($api_public = '', $api_secret = '', $api_password = '', $api_uid = '', $enableRateLimit = false)
    {
        $this->api_public = $api_public;
        $this->api_secret = $api_secret;

        parent::__construct('exmo', $api_public, $api_secret, $api_password, $api_uid, $enableRateLimit);
    }

    public function createOrder(string $symbol, string $type, string $side, float $amount, float $price = 0, string $exec_type = null): array
    {
        $mt = explode(' ', microtime());

        if ($type == 'limit') {
            $exmo_type = $side;
        } else {
            $exmo_type = 'market_' . $side;
        }

        $post_data = [
            'pair' => str_replace('/', '_', $symbol),
            'quantity' => $amount,
            'price' => $price,
            'type' => $exmo_type
        ];

        if ($exec_type)
            $post_data['exec_type'] = $exec_type;

        $post_data['nonce'] = $mt[1] . substr($mt[0], 2, 6);

        $post_data = http_build_query(
            $post_data,
            '',
            '&'
        );

        try {
            $order = json_decode(Http::post(
                'https://api.exmo.com/v1.1/order_create',
                $post_data,
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Key: ' . $this->api_public,
                    'Sign: ' . hash_hmac('sha512', $post_data, $this->api_secret)
                ]
            ), true);

            $order['symbol'] = $symbol;
            $order['side'] = $side;
            $order['amount'] = $amount;
            $order['price'] = $price;
            $order['status'] = 'open';
        } catch (Throwable $e) {
            echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
        }

        return $order ?? [];
    }
}