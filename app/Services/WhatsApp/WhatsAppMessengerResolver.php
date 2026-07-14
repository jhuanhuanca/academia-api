<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappInstance;
use App\Services\WhatsApp\Contracts\WhatsAppMessenger;

class WhatsAppMessengerResolver
{
    public function __construct(
        private readonly EvolutionMessenger $evolution,
        private readonly MetaCloudMessenger $metaCloud,
    ) {
    }

    public function for(WhatsappInstance $instance): WhatsAppMessenger
    {
        return match ($instance->integration) {
            'meta_cloud' => $this->metaCloud,
            default => $this->evolution,
        };
    }

    public function isMetaCloud(WhatsappInstance $instance): bool
    {
        return $instance->integration === 'meta_cloud';
    }
}
