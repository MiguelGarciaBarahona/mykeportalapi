<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Registro de auditoría del panel.
 *
 * Qué se guarda: quién (id del administrador), qué acción, sobre qué registro
 * (tipo + identificador público) y un hash irreversible de la IP.
 * Qué NO se guarda nunca: contraseñas, tokens, códigos 2FA, correos ni datos de solicitudes.
 */
final class AuditLogger
{
    public static function record(string $action, ?User $user = null, ?Model $subject = null, ?string $subjectId = null): void
    {
        try {
            AuditLog::create([
                'user_id' => $user?->getKey(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subjectId ?? self::subjectIdentifier($subject),
                'ip_hash' => AuditLog::hashIp(request()?->ip()),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe impedir la operación; el fallo queda en el log del servidor.
            report($e);
        }
    }

    private static function subjectIdentifier(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        // Las solicitudes se identifican por su número público (MS-XXXXXX), nunca por datos personales.
        return (string) ($subject->getAttribute('public_id') ?? $subject->getKey());
    }
}
