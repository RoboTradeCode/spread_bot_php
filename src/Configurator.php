<?php

namespace Src;

class Configurator
{
    public static function getConfigFromFile(string $file): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/config/' . $file . '.json'), true);
    }
}