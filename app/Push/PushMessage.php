<?php

namespace App\Push;

/**
 * Contenido de una notificación. Privacidad: nunca nombres, correos, teléfonos ni montos;
 * se ve en la pantalla bloqueada del dispositivo.
 */
final class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/',
        public readonly ?string $tag = null,
    ) {}

    public function toJson(): string
    {
        return (string) json_encode(array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'url' => str_starts_with($this->url, '/') && ! str_starts_with($this->url, '//') ? $this->url : '/',
            'tag' => $this->tag,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
