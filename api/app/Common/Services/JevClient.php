<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\JevClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class JevClient
{
    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $questions
     * @return array{model: string, answers: array<string, array<string, mixed>>, usage: array<string, mixed>}
     */
    public function evaluate(array $state, array $questions): array
    {
        $apiKey = config('typesafe.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new JevClientException('TYPESAFE_API_KEY is not configured.');
        }

        $endpoint = config('typesafe.endpoint');
        if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://')) {
            throw new JevClientException('TypeSafe endpoint must use HTTPS.');
        }

        if (trim((string) config('typesafe.model', '')) === '') {
            throw new JevClientException('TYPESAFE_MODEL is not configured.');
        }

        if ($questions === []) {
            throw new JevClientException('TypeSafe evaluation requires at least one question.');
        }

        $response = $this->post($apiKey, $endpoint, [
            'model' => (string) config('typesafe.model', 'jev-1.13.0'),
            'state' => $state,
            'questions' => $questions,
        ]);

        if ($response->failed()) {
            throw new JevClientException('TypeSafe request failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)
            || ! is_string($payload['model'] ?? null)
            || ! is_array($payload['answers'] ?? null)
            || ! is_array($payload['usage'] ?? null)) {
            throw new JevClientException('TypeSafe returned a malformed response.');
        }

        foreach ($payload['answers'] as $questionId => $answer) {
            if (! is_string($questionId)
                || ! is_array($answer)
                || ! is_string($answer['type'] ?? null)) {
                throw new JevClientException('TypeSafe returned a malformed answer.');
            }
        }

        /** @var array<string, array<string, mixed>> $answers */
        $answers = $payload['answers'];

        return [
            'model' => $payload['model'],
            'answers' => $answers,
            'usage' => $payload['usage'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $apiKey, string $endpoint, array $payload): Response
    {
        $maxAttempts = max(1, (int) config('typesafe.max_attempts', 3));
        $delays = config('typesafe.retry_delays_ms', [250, 1000]);
        $delays = is_array($delays) ? array_values($delays) : [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withToken($apiKey)
                    ->connectTimeout(max(1, (int) config('typesafe.connect_timeout_seconds', 10)))
                    ->timeout(max(1, (int) config('typesafe.timeout_seconds', 30)))
                    ->post($endpoint, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt === $maxAttempts) {
                    throw new JevClientException('TypeSafe connection failed.', 0, $exception);
                }

                $this->delay($delays, $attempt);

                continue;
            }

            if (! in_array($response->status(), [429, 529], true) || $attempt === $maxAttempts) {
                return $response;
            }

            $this->delay($delays, $attempt);
        }

        throw new JevClientException('TypeSafe request did not produce a response.');
    }

    /** @param list<int|float|string> $delays */
    private function delay(array $delays, int $attempt): void
    {
        $delay = (int) ($delays[$attempt - 1] ?? 0);
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }
}
