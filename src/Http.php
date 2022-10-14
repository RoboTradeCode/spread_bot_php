<?php

namespace Src;

class Http
{
    public static function post(string $url, string $post_data = '', array $headers = []): bool|string
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3000);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10000);

        $result = curl_exec($curl);

        curl_close($curl);

        return $result;
    }
}