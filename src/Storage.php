<?php

namespace Src;

class Storage
{
    public static function recordLog(string $message, array $variables = []): void
    {
        $time = date('Y-m-d H:i:s');

        $record = '[' . $time . '] ' . $message . ' [START]----------------------------------------------------------------------------------' . "\n";
        if ($variables) $record .= print_r($variables, true) . "\n";
        $record .= '[' . $time . '] ' . $message . ' [END]------------------------------------------------------------------------------------' . "\n";

        file_put_contents(
            dirname(__DIR__) . '/storage/all.log',
            date('Y-m-d H:i:s') . "\n" . $record,
            FILE_APPEND
        );
    }
}