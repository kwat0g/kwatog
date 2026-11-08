<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CacheSafetyPolicyTest extends TestCase
{
    public function test_application_code_does_not_use_global_flush_or_unbounded_cache_entries(): void
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = implode('', array_map(
                static fn (array|string $token): string => is_array($token)
                    ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                    : $token,
                token_get_all($file->getContents()),
            ));

            $patterns = [
                '/\bCache::\s*(?:forever|rememberForever)\s*\(/i',
                '/\bCache::\s*(?:store|driver|tags)\s*\([^;]*?\)\s*->\s*(?:forever|rememberForever)\s*\(/i',
                '/\bcache\s*\([^;]*?\)\s*->\s*(?:forever|rememberForever)\s*\(/i',
                '/\bCache::\s*flush\s*\(/i',
                '/\bCache::\s*(?:store|driver)\s*\([^;]*?\)\s*->\s*flush\s*\(/i',
                '/\bcache\s*\([^;]*?\)\s*->\s*flush\s*\(/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $file->getRelativePathname().': global flush and unbounded cache entries are prohibited';
                    break;
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
