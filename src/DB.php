<?php

namespace Src;

use PDO;
use PDOException;

class DB
{

    private static PDO $connect;

    public static function connect(): void
    {
        $db = require_once CONFIG . '/db.config.php';

        try {
            $dbh = new PDO(
                'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['db'],
                $db['user'],
                $db['password'],
                [PDO::ATTR_PERSISTENT => true]
            );

            $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo '[' . date('Y-m-d H:i:s') . '] [ERROR] Can not connect to db. Message: ' . $e->getMessage() . PHP_EOL;

            die();
        }

        self::$connect = $dbh;
    }

    public static function insertOrderbookSpreadExmoAndBinance(float $exmo_ask, float $exmo_bid, float $binance_ask, float $binance_bid): void
    {
        self::insert(
            'orderbook_spread_exmo_and_binance',
            [
                'exmo_ask' => $exmo_ask,
                'exmo_bid' => $exmo_bid,
                'binance_ask' => $binance_ask,
                'binance_bid' => $binance_bid
            ]
        );
    }

    private static function insert(string $table, array $columns_and_values): void
    {
        $columns = array_keys($columns_and_values);

        $sth = self::$connect->prepare(
            sprintf(
            /** @lang sql */ 'INSERT INTO `%s` (`%s`) VALUES (:%s)',
                $table,
                implode('`, `', $columns),
                implode(', :', $columns)
            )
        );

        $sth->execute($columns_and_values);
    }
}