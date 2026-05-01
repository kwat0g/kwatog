<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class HasHashIdTest extends TestCase
{
    public function test_round_trip_encode_decode(): void
    {
        $model = new class extends Model {
            use HasHashId;
            protected $table = 'fakes';
            public $exists = true;
            public function getKey() { return 42; }
        };

        $hash = $model->hash_id;
        $this->assertNotEmpty($hash);
        $this->assertNotSame('42', $hash);
        $this->assertSame(42, $model::decodeHash($hash));
    }
}
