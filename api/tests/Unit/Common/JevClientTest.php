<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use App\Common\Exceptions\JevClientException;
use App\Common\Services\JevClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class JevClientTest extends TestCase
{
    public function test_it_sends_a_typed_evaluation_to_the_pinned_jev_model(): void
    {
        config([
            'typesafe.api_key' => 'test-key',
            'typesafe.endpoint' => 'https://api.typesafe.test/v1/systemone',
            'typesafe.model' => 'jev-1.13.0',
            'typesafe.max_attempts' => 1,
        ]);

        Http::fake([
            'https://api.typesafe.test/*' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => [
                    'scenario_is_reachable' => ['type' => 'noul', 'noul' => 0.95],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
            ]),
        ]);

        $result = app(JevClient::class)->evaluate(
            ['scenario' => 'A rejected GRN does not enter available stock.'],
            ['scenario_is_reachable' => [
                'type' => 'noul',
                'instructions' => 'Can this scenario occur?',
            ]],
        );

        $this->assertSame('jev-1.13.0', $result['model']);
        $this->assertSame(0.95, $result['answers']['scenario_is_reachable']['noul']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.typesafe.test/v1/systemone'
                && $request->header('Authorization') === ['Bearer test-key']
                && $request->data()['model'] === 'jev-1.13.0';
        });
    }

    public function test_it_retries_rate_limit_responses(): void
    {
        config([
            'typesafe.api_key' => 'test-key',
            'typesafe.endpoint' => 'https://api.typesafe.test/v1/systemone',
            'typesafe.max_attempts' => 2,
            'typesafe.retry_delays_ms' => [0],
        ]);

        Http::fakeSequence()
            ->pushStatus(529)
            ->push([
                'model' => 'jev-1.13.0',
                'answers' => ['scenario_is_reachable' => ['type' => 'noul', 'noul' => 0.9]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
            ]);

        $result = app(JevClient::class)->evaluate(['scenario' => 'test'], [
            'scenario_is_reachable' => ['type' => 'noul', 'instructions' => 'Can this occur?'],
        ]);

        $this->assertSame('jev-1.13.0', $result['model']);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_malformed_type_safe_response(): void
    {
        config([
            'typesafe.api_key' => 'test-key',
            'typesafe.endpoint' => 'https://api.typesafe.test/v1/systemone',
            'typesafe.max_attempts' => 1,
        ]);

        Http::fake([
            'https://api.typesafe.test/*' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => null,
            ]),
        ]);

        $this->expectException(JevClientException::class);
        $this->expectExceptionMessage('malformed response');

        app(JevClient::class)->evaluate(['scenario' => 'test'], [
            'scenario_is_reachable' => ['type' => 'noul', 'instructions' => 'Can this occur?'],
        ]);
    }

    public function test_it_requires_a_server_side_api_key(): void
    {
        config([
            'typesafe.api_key' => null,
            'typesafe.max_attempts' => 1,
        ]);

        $this->expectException(JevClientException::class);
        $this->expectExceptionMessage('TYPESAFE_API_KEY is not configured');

        app(JevClient::class)->evaluate(['scenario' => 'test'], [
            'scenario_is_reachable' => ['type' => 'noul', 'instructions' => 'Can this occur?'],
        ]);
    }
}
