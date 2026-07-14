<?php

namespace Tests\Unit;

use App\Services\WhatsApp\IncomingMessageNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IncomingMessageNormalizerLidTest extends TestCase
{
    public function test_prefers_remote_jid_alt_over_lid(): void
    {
        $normalizer = new IncomingMessageNormalizer;
        $method = new ReflectionMethod($normalizer, 'mapMessage');
        $method->setAccessible(true);

        $mapped = $method->invoke($normalizer, [
            'key' => [
                'remoteJid' => '123456789012345@lid',
                'remoteJidAlt' => '51929212335@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'ABC',
            ],
            'pushName' => 'Cliente',
            'message' => ['conversation' => 'hola'],
        ]);

        $this->assertSame('+51929212335', $mapped['phone_e164']);
        $this->assertSame('51929212335', $mapped['reply_to']);
    }

    public function test_falls_back_to_lid_jid_for_reply(): void
    {
        $normalizer = new IncomingMessageNormalizer;
        $method = new ReflectionMethod($normalizer, 'mapMessage');
        $method->setAccessible(true);

        $mapped = $method->invoke($normalizer, [
            'key' => [
                'remoteJid' => '999888777666555@lid',
                'fromMe' => false,
                'id' => 'DEF',
            ],
            'message' => ['conversation' => 'hola'],
        ]);

        $this->assertSame('+999888777666555', $mapped['phone_e164']);
        $this->assertSame('999888777666555@lid', $mapped['reply_to']);
    }
}
