<?php

namespace App\Push;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía notificaciones push a los navegadores suscritos.
 * - Suscripciones vencidas (404/410) se eliminan; tras 10 fallas seguidas también.
 * - Un error de envío nunca interrumpe la acción que lo originó.
 */
class PushNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('mykesystems.push.public_key')) && filled(config('mykesystems.push.private_key'));
    }

    /**
     * @param  Builder<PushSubscription>  $subscriptions
     * @return array{sent: int, failed: int, removed: int}
     */
    public function send(Builder $subscriptions, PushMessage $message): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        if (! $this->isConfigured()) {
            return $result;
        }

        $subscriptions->ofActiveUsers()->orderBy('id')->each(function (PushSubscription $subscription) use ($message, &$result) {
            $outcome = $this->deliver($subscription, $message);
            $result[$outcome]++;
        });

        return $result;
    }

    /** @return 'sent'|'failed'|'removed' */
    public function deliver(PushSubscription $subscription, PushMessage $message): string
    {
        try {
            if (! self::isAllowedEndpoint($subscription->endpoint)) {
                $subscription->delete();

                return 'removed';
            }

            $body = WebPushCrypto::encrypt($message->toJson(), $subscription->public_key, $subscription->auth_token);
            $authorization = WebPushCrypto::vapidAuthorization(
                $subscription->endpoint,
                (string) config('mykesystems.push.subject'),
                (string) config('mykesystems.push.public_key'),
                (string) config('mykesystems.push.private_key'),
            );

            $http = Http::timeout(8);
            if ($tls = self::tlsOptions()) {
                $http = $http->withOptions($tls); // siempre verifica el certificado del servicio
            }
            $response = $http
                ->withHeaders([
                    'Authorization' => $authorization,
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) config('mykesystems.push.ttl_seconds', 86400),
                    'Urgency' => 'high',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);

            if ($response->successful()) {
                $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

                return 'sent';
            }

            if (in_array($response->status(), [404, 410], true)) {
                $subscription->delete();

                return 'removed';
            }

            Log::warning('Push rechazado por el servicio del navegador.', ['subscription_id' => $subscription->id, 'status' => $response->status()]);
        } catch (Throwable $e) {
            // Motivo sin URLs (el endpoint identifica al dispositivo).
            $reason = mb_substr((string) preg_replace('#https?://\S+#', '[url]', $e->getMessage()), 0, 240);
            Log::warning('No se pudo enviar una notificación push.', ['subscription_id' => $subscription->id, 'error' => class_basename($e), 'reason' => $reason, 'tls' => self::caBundle() ? 'archivo' : 'php']);
        }

        $subscription->increment('failures');
        if ($subscription->failures >= 10) {
            $subscription->delete();

            return 'removed';
        }

        return 'failed';
    }

    /**
     * Opciones TLS para PHP en Windows sin certificados configurados (XAMPP, WinGet).
     * Nunca desactiva la verificación: solo indica qué archivo de autoridades usar.
     *
     * @return array<string, mixed>|null
     */
    public static function tlsOptions(): ?array
    {
        return ($bundle = self::caBundle()) ? ['verify' => $bundle] : null;
    }

    /**
     * Archivo de autoridades de certificación: PUSH_CA_BUNDLE, o en Windows sin configuración de PHP,
     * storage/certs/cacert.pem (lista de Mozilla publicada por curl.se) o el que trae XAMPP.
     */
    public static function caBundle(): ?string
    {
        $configured = config('mykesystems.push.ca_bundle');
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }
        if (ini_get('curl.cainfo') || ini_get('openssl.cafile') || PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $phpDir = dirname(PHP_BINARY);
        foreach ([storage_path('certs/cacert.pem'), $phpDir.'/extras/ssl/cacert.pem', dirname($phpDir).'/apache/bin/curl-ca-bundle.crt', $phpDir.'/cacert.pem'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Solo https y servicios de push conocidos (o sus subdominios). */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ((array) config('mykesystems.push.allowed_hosts', []) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
