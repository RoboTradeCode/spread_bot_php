<?php

namespace Src;

class Pm2
{

    public static function start(string $file, string $name, string $namespace, array $arguments = [], bool $force = false, bool $is_output = false, bool $is_error = false): void
    {
        $pm2_command = 'pm2 start ' . $file . ' --name "[' . $name . ']"  --namespace "' . $namespace . '"';

        if (!$is_output)
            $pm2_command .= ' -o /dev/null';

        if (!$is_error)
            $pm2_command .= ' -e /dev/null';

        $pm2_command .= ' -m';

        if ($force)
            $pm2_command .= ' -f';

        if ($arguments)
            $pm2_command .= ' -- "' . implode('" "', $arguments) . '"';

        exec($pm2_command);
    }

}