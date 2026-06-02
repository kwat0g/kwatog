<?php

declare(strict_types=1);

namespace App\Common\Support;

final class HashId
{
    public static function encode(int $id): string
    {
        return app('hashids')->encode($id);
    }

    public static function decode(string $hash): ?int
    {
        $d = app('hashids')->decode($hash);
        return $d[0] ?? null;
    }
}
