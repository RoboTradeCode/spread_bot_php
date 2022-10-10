<?php

namespace Src\SpreadBot;

class MemcachedData
{

    private string $main_exchange;
    private string $market_discovery_exchange;
    private array $markets;
    private int $expired_orderbook_time;
    public array $keys;

    /**
     * @param string $main_exchange
     * @param string $market_discovery_exchange
     * @param array $markets
     * @param int $expired_orderbook_time
     */
    public function __construct(string $main_exchange, string $market_discovery_exchange, array $markets, int $expired_orderbook_time)
    {
        $this->main_exchange = $main_exchange;
        $this->market_discovery_exchange = $market_discovery_exchange;
        $this->markets = $markets;
        $this->expired_orderbook_time = $expired_orderbook_time;
        $this->keys = $this->getAllMemcachedKeys();
    }

    /**
     * Возвращает данные из memcached в определенном формате и отделенные по ордербукам, балансам и т. д.
     *
     * @param array $memcached_data Сырые данные, взятые напрямую из memcached
     * @return array[]
     */
    public function reformatAndSeparateData(array $memcached_data): array
    {
        $microtime = microtime(true);

        foreach ($memcached_data as $key => $data)
            if (isset($data)) {
                if ($key == 'rates') {
                    $rates = $data;
                } else {
                    $parts = explode('_', $key);

                    $exchange = $parts[0];
                    $action = $parts[1];
                    $value = $parts[2] ?? null;

                    if ($action == 'balances') {
                        $balances[$exchange] = $data;
                    } elseif ($action == 'orderbook' && $value) {
                        if (($microtime - $data['core_timestamp']) <= $this->expired_orderbook_time / 1000000) {
                            $orderbooks[$value][$exchange] = $data;
                        }
                    } elseif ($action == 'orders') {
                        if ($exchange == $this->main_exchange) $orders[$exchange] = $data;
                    } else
                        $undefined[$key] = $data;
                }
            }

        return [
            'balances' => $balances ?? [],
            'orderbooks' => $orderbooks ?? [],
            'orders' => $orders ?? [],
            'undefined' => $undefined ?? [],
            'rates' => $rates ?? []
        ];
    }

    /**
     * Формирует массив всех ключей для memcached
     *
     * @return array Возвращает все ключи для memcached
     */
    private function getAllMemcachedKeys(): array
    {
        $common_symbols = array_column($this->markets, 'common_symbol');

        return array_merge(
            preg_filter(
                '/^/',
                $this->main_exchange . '_orderbook_',
                $common_symbols
            ),
            preg_filter(
                '/^/',
                $this->market_discovery_exchange . '_orderbook_',
                $common_symbols
            ),
            [$this->main_exchange . '_balances'],
            [$this->main_exchange . '_orders'],
            ['rates']
        );
    }

}