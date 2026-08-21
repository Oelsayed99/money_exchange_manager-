<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/**
 * Credentials for the mysql command-line tools, written to a temporary options file.
 *
 * Not passed as `--password=…`. Command lines are world-readable on a running system:
 * anybody who can run `ps` can read the database password out of the argument list of
 * a backup that happens to be in progress, and on a shared machine that is everybody.
 *
 * The file is created readable only by its owner and deleted when this object goes
 * out of scope, so the window is the length of one command rather than forever.
 */
final class MysqlCredentials
{
    private readonly string $path;

    /** @param array<string, mixed> $connection */
    public function __construct(array $connection)
    {
        $path = tempnam(sys_get_temp_dir(), 'db-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the database credentials.');
        }

        $this->path = $path;

        // Written before the permissions are narrowed would leave a readable moment;
        // chmod first, then write.
        chmod($this->path, 0600);

        file_put_contents($this->path, $this->contents($connection));
    }

    public function __destruct()
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** The argument that points mysql or mysqldump at these credentials. */
    public function argument(): string
    {
        // Must be the first argument the tools see, hence "extra-file" rather than
        // appending to the default search path.
        return '--defaults-extra-file='.$this->path;
    }

    /** @param array<string, mixed> $connection */
    private function contents(array $connection): string
    {
        $lines = ['[client]'];

        foreach (['host', 'port', 'username' => 'user', 'password'] as $key => $option) {
            $key = is_int($key) ? $option : $key;
            $value = $connection[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // Quoted: a password containing a hash would otherwise be read as the start
            // of a comment, and the connection would fail for a reason nobody could see.
            $lines[] = $option.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        }

        return implode("\n", $lines)."\n";
    }
}
