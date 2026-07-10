<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EvolutionClient
{
    public function health(): array
    {
        try {
            $response = $this->http()->get('/');

            return [
                'status' => $response->successful() ? 'ok' : 'down',
                'http_status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInstance(array $payload): array
    {
        return $this->request('post', '/instance/create', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(string $instance): array
    {
        return $this->request('get', '/instance/connect/'.$instance);
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionState(string $instance): array
    {
        return $this->request('get', '/instance/connectionState/'.$instance);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchInstances(?string $instance = null): array
    {
        $query = $instance ? ['instanceName' => $instance] : [];

        return $this->request('get', '/instance/fetchInstances', $query);
    }

    /**
     * @param  array<string, mixed>  $webhook
     * @return array<string, mixed>
     */
    public function setWebhook(string $instance, array $webhook): array
    {
        return $this->request('post', '/webhook/set/'.$instance, [
            'webhook' => $webhook,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $instance, string $number, string $text): array
    {
        return $this->request('post', '/message/sendText/'.$instance, [
            'number' => $this->normalizeNumber($number),
            'text' => $text,
        ]);
    }

    /**
     * @param  list<array{id:string,label:string}>  $buttons
     * @return array<string, mixed>
     */
    public function sendButtons(
        string $instance,
        string $number,
        string $title,
        string $description,
        array $buttons,
        ?string $footer = null
    ): array {
        $mapped = [];
        foreach ($buttons as $button) {
            $mapped[] = [
                'type' => 'reply',
                'displayText' => $button['label'] ?? $button['displayText'] ?? 'Opción',
                'id' => $button['id'] ?? uniqid('btn_', true),
            ];
        }

        return $this->request('post', '/message/sendButtons/'.$instance, [
            'number' => $this->normalizeNumber($number),
            'title' => $title,
            'description' => $description,
            'footer' => $footer ?? config('app.name', 'MarketLuna'),
            'buttons' => $mapped,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMedia(
        string $instance,
        string $number,
        string $mediaUrl,
        string $mediatype = 'image',
        ?string $caption = null,
        ?string $fileName = null
    ): array {
        return $this->request('post', '/message/sendMedia/'.$instance, array_filter([
            'number' => $this->normalizeNumber($number),
            'mediatype' => $mediatype,
            'media' => $mediaUrl,
            'caption' => $caption,
            'fileName' => $fileName,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function getBase64FromMediaMessage(string $instance, array $payload): array
    {
        return $this->request('post', '/chat/getBase64FromMediaMessage/'.$instance, $payload);
    }

    public function logout(string $instance): array
    {
        return $this->request('delete', '/instance/logout/'.$instance);
    }

    /**
     * @param  array<string, mixed>  $queryOrBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $queryOrBody = []): array
    {
        $http = $this->http();
        $url = rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');

        $response = match (strtolower($method)) {
            'get' => $http->get($url, $queryOrBody),
            'delete' => $http->delete($url, $queryOrBody),
            default => $http->post($url, $queryOrBody),
        };

        if ($response->failed()) {
            throw new RuntimeException(
                'Evolution error '.$response->status().': '.$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders([
                'apikey' => $this->apiKey(),
            ]);
    }

    private function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return $digits;
    }

    private function baseUrl(): string
    {
        return (string) config('services.evolution.base_url');
    }

    private function apiKey(): string
    {
        return (string) config('services.evolution.api_key');
    }

    private function timeout(): float
    {
        return (float) config('services.evolution.timeout', 30);
    }
}
