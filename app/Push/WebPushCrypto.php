<?php

namespace App\Push;

use InvalidArgumentException;
use RuntimeException;

/**
 * Web Push sin dependencias externas (solo la extensión openssl de PHP):
 *  - VAPID (RFC 8292): firma ES256 del token que identifica al servidor.
 *  - Cifrado del mensaje "aes128gcm" (RFC 8291 + RFC 8188).
 *
 * Las claves se manejan en base64url:
 *  - Clave pública: punto P-256 sin comprimir (65 bytes, empieza con 0x04).
 *  - Clave privada: escalar "d" de 32 bytes.
 */
final class WebPushCrypto
{
    private const RECORD_SIZE = 4096;

    /** Genera un par de claves VAPID nuevo. @return array{publicKey: string, privateKey: string} */
    public static function createVapidKeys(): array
    {
        [$public, $private] = self::newKeyPair();

        return ['publicKey' => self::b64urlEncode($public), 'privateKey' => self::b64urlEncode($private)];
    }

    /**
     * Cifra el contenido para una suscripción del navegador.
     *
     * @param  string  $userPublicKey  clave "p256dh" de la suscripción (base64url)
     * @param  string  $userAuth  secreto "auth" de la suscripción (base64url)
     * @param  array{0:string,1:string}|null  $localKeys  solo para pruebas: [pública 65 bytes, privada 32 bytes]
     * @param  string|null  $salt  solo para pruebas: 16 bytes
     * @return string cuerpo binario listo para enviar con "Content-Encoding: aes128gcm"
     */
    public static function encrypt(string $payload, string $userPublicKey, string $userAuth, ?array $localKeys = null, ?string $salt = null): string
    {
        $uaPublic = self::b64urlDecode($userPublicKey);
        $authSecret = self::b64urlDecode($userAuth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new InvalidArgumentException('Clave p256dh no válida.');
        }
        if (strlen($authSecret) < 16) {
            throw new InvalidArgumentException('Secreto auth no válido.');
        }
        // Un registro único: el contenido debe caber (4096 - 16 de etiqueta - 1 delimitador - 86 de cabecera).
        if (strlen($payload) > 3000) {
            throw new InvalidArgumentException('El contenido de la notificación es demasiado grande.');
        }

        [$asPublic, $asPrivate] = $localKeys ?? self::newKeyPair();
        $salt ??= random_bytes(16);

        $ecdhSecret = self::ecdh($asPrivate, $asPublic, $uaPublic);
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\x00".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo cifrar la notificación.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$ciphertext.$tag;
    }

    /**
     * Cabecera Authorization VAPID para el servicio de push del navegador.
     *
     * @param  string  $endpoint  URL de la suscripción
     * @param  string  $subject  "mailto:…" o URL https del sitio
     */
    public static function vapidAuthorization(string $endpoint, string $subject, string $publicKey, string $privateKey, ?int $expiresAt = null): string
    {
        $parts = parse_url($endpoint);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Endpoint no válido.');
        }
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = self::b64urlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64urlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => $expiresAt ?? time() + 12 * 3600,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header.'.'.$claims;

        $public = self::b64urlDecode($publicKey);
        $key = openssl_pkey_get_private(self::privatePem(self::b64urlDecode($privateKey), $public));
        if ($key === false || ! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el token VAPID. Revisa VAPID_PRIVATE_KEY y VAPID_PUBLIC_KEY.');
        }

        return 'vapid t='.$signingInput.'.'.self::b64urlEncode(self::derToRaw($der)).', k='.$publicKey;
    }

    /* ----------------------------------------------------------- utilidades */

    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Texto base64url no válido.');
        }

        return $decoded;
    }

    /** @return array{0:string,1:string} [pública 65 bytes, privada 32 bytes] */
    public static function newKeyPair(): array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = openssl_pkey_new($options);

        // En Windows (XAMPP) PHP a veces no encuentra openssl.cnf: se reintenta indicándolo.
        if ($key === false) {
            foreach (self::opensslConfigCandidates() as $config) {
                $key = openssl_pkey_new($options + ['config' => $config]);
                if ($key !== false) {
                    break;
                }
            }
        }
        if ($key === false) {
            $errors = [];
            while (($e = openssl_error_string()) !== false) {
                $errors[] = $e;
            }
            throw new RuntimeException('OpenSSL no pudo generar una clave P-256. Define OPENSSL_CONF con la ruta de openssl.cnf. '.implode(' | ', array_slice($errors, 0, 2)));
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        return ["\x04".self::pad32($ec['x']).self::pad32($ec['y']), self::pad32($ec['d'])];
    }

    /** @return list<string> rutas de openssl.cnf existentes (variable de entorno o las que trae PHP/XAMPP) */
    private static function opensslConfigCandidates(): array
    {
        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            $phpDir.'/extras/ssl/openssl.cnf',
            $phpDir.'/extras/openssl/openssl.cnf',
            dirname($phpDir).'/apache/conf/openssl.cnf',
            dirname($phpDir).'/conf/openssl.cnf',
            $phpDir.'/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ];

        return array_values(array_unique(array_filter($candidates, fn ($path) => is_string($path) && is_file($path))));
    }

    /** Secreto compartido ECDH (32 bytes). */
    public static function ecdh(string $privateRaw, string $publicRaw, string $peerPublicRaw): string
    {
        $private = openssl_pkey_get_private(self::privatePem($privateRaw, $publicRaw));
        $peer = openssl_pkey_get_public(self::publicPem($peerPublicRaw));
        $secret = ($private && $peer) ? openssl_pkey_derive($peer, $private, 32) : false;
        if ($secret === false) {
            throw new RuntimeException('No se pudo calcular el secreto ECDH.');
        }

        return $secret;
    }

    public static function publicPem(string $publicRaw): string
    {
        // SubjectPublicKeyInfo: id-ecPublicKey + prime256v1 + BIT STRING(punto)
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$publicRaw;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function privatePem(string $privateRaw, string $publicRaw): string
    {
        // ECPrivateKey (SEC 1): versión 1, d, [0] prime256v1, [1] clave pública
        $der = hex2bin('30770201010420').$privateRaw.hex2bin('a00a06082a8648ce3d030107a144034200').$publicRaw;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** Firma ECDSA en DER → r||s (64 bytes), como exige JWS. */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            $parts[] = str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0].$parts[1];
    }

    /** r||s (64 bytes) → DER (para verificar firmas en pruebas). */
    public static function rawToDer(string $raw): string
    {
        $int = function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '' || ord($v[0]) & 0x80) {
                $v = "\x00".$v;
            }

            return "\x02".chr(strlen($v)).$v;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($seq)).$seq;
    }

    private static function pad32(string $value): string
    {
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }
}
