<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LunaClient
{
    public function health(): array
    {
        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->get(rtrim($this->baseUrl(), '/').'/health');

        $response->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function decide(array $payload): array
    {
        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $this->apiKey()])
                ->post(rtrim($this->baseUrl(), '/').'/v1/decide', $payload);

            $response->throw();

            return $response->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Luna no respondió correctamente: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function classify(array $payload): array
    {
        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders(['X-API-Key' => $this->apiKey()])
            ->post(rtrim($this->baseUrl(), '/').'/v1/classify', $payload);

        $response->throw();

        return $response->json();
    }

    private function baseUrl(): string
    {
        return (string) config('services.luna.base_url');
    }

    private function apiKey(): string
    {
        return (string) config('services.luna.api_key');
    }

    private function timeout(): float
    {
        return (float) config('services.luna.timeout', 20);
    }
}
