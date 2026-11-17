<?php

namespace App\Console\Commands;

use App\Push\WebPushCrypto;
use Illuminate\Console\Command;

/**
 * Genera las claves VAPID de las notificaciones push.
 * Con --write las agrega al archivo .env del entorno SOLO si aún no existen (nunca reemplaza:
 * cambiar las claves invalidaría las suscripciones de todos los dispositivos).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid {--write : Agregar las claves al .env si aún no existen}';

    protected $description = 'Genera las claves VAPID para las notificaciones push del panel';

    public function handle(): int
    {
        $file = $this->laravel->environmentFilePath();
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $hasKeys = preg_match('/^VAPID_PUBLIC_KEY=\S+/m', $current) && preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $current);

        if ($this->option('write') && $hasKeys) {
            $this->info('Las claves VAPID ya existen en '.basename($file).'; no se cambian.');

            return self::SUCCESS;
        }

        $keys = WebPushCrypto::createVapidKeys();

        if (! $this->option('write')) {
            $this->line('Agrega estas líneas al .env (guárdalas como cualquier otro secreto):');
            $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

            return self::SUCCESS;
        }

        if (! is_file($file) || ! is_writable($file)) {
            $this->error('No se puede escribir en '.basename($file).'.');

            return self::FAILURE;
        }

        // Quita líneas vacías de claves (VAPID_PUBLIC_KEY= sin valor) y agrega las nuevas.
        $clean = preg_replace('/^VAPID_(PUBLIC|PRIVATE)_KEY=\s*$/m', '', $current) ?? $current;
        $append = "\n# Notificaciones push del panel (no cambiar: invalidaría las suscripciones)\n"
            .'VAPID_PUBLIC_KEY='.$keys['publicKey']."\n"
            .'VAPID_PRIVATE_KEY='.$keys['privateKey']."\n";
        file_put_contents($file, rtrim($clean)."\n".$append);

        $this->info('Claves VAPID agregadas a '.basename($file).'. Ejecuta "php artisan config:clear" si usas caché de configuración.');

        return self::SUCCESS;
    }
}
