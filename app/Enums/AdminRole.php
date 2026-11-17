<?php

namespace App\Enums;

/** Roles del panel administrativo (los permisos se aplican en la Fase B). */
enum AdminRole: string
{
    /** Acceso total: gestiona administradores, exporta, elimina y anonimiza. */
    case Owner = 'owner';

    /** Gestiona solicitudes: ver, cambiar estado y agregar notas. */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrador',
        };
    }
}
