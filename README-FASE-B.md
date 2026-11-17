# MykeSystems API — Fase B: autenticación del panel

Autenticación de administradores con Laravel Fortify + Sanctum (modo SPA, sesión por cookie),
2FA TOTP obligatorio, roles `owner` / `admin`, auditoría y pruebas.

## 1. Instalar dependencias nuevas

```bash
cd C:\xampp\htdocs\mykesystems-api
composer require laravel/fortify laravel/sanctum
```

⚠️ **No ejecutes** `php artisan fortify:install` ni `php artisan install:api`: sobrescribirían
`config/fortify.php`, `FortifyServiceProvider`, las acciones y `routes/api.php` de este proyecto,
y crearían el registro público. Toda la configuración ya está incluida.

## 2. Variables de entorno

Añade a tu `.env` (ver `.env.example`):

```dotenv
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_DOMAIN=null
SESSION_COOKIE=myke_admin_session
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
ADMIN_SESSION_ABSOLUTE_HOURS=12
ADMIN_DOMAIN=
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
ADMIN_PANEL_URL=http://localhost:5173
```

## 3. Migraciones, pruebas y primer administrador

```bash
php artisan migrate
php artisan test
php artisan admin:create      # interactivo; el primer usuario es siempre owner
php artisan admin:deactivate correo@dominio   # bloqueo inmediato desde consola
```

## 4. Endpoints (prefijo `/api/admin`, solo en `ADMIN_DOMAIN`)

| Método | Ruta | Notas |
|---|---|---|
| GET | `/sanctum/csrf-cookie` (raíz, fuera del prefijo) | Obtener `XSRF-TOKEN` antes del login |
| POST | `/auth/login` | Devuelve `{two_factor: true|false}`. 5/min por correo+IP |
| POST | `/auth/two-factor-challenge` | `code` o `recovery_code` |
| POST | `/auth/logout` | |
| POST | `/auth/forgot-password`, `/auth/reset-password` | Respuesta idéntica exista o no el correo |
| POST | `/auth/user/confirm-password` | Requisito para gestionar 2FA |
| POST | `/auth/user/two-factor-authentication` | Genera secreto |
| GET | `/auth/user/two-factor-qr-code` · `secret-key` · `recovery-codes` | |
| POST | `/auth/user/confirmed-two-factor-authentication` | Activa el 2FA |
| GET | `/me` | Perfil (disponible antes de configurar 2FA) |
| GET | `/admins` · PATCH `/admins/{id}/deactivate` · `/activate` | Solo owner, 2FA obligatorio |

No existen `/register` ni `DELETE .../two-factor-authentication`.

## 5. Decisiones de seguridad entre subdominios

- Cookie de sesión **host-only** (`SESSION_DOMAIN=null`): nunca se envía a `mykesystems.com`.
- `SameSite=strict`, `HttpOnly`, cifrada; en producción `Secure` y nombre `__Host-myke_admin`.
- CSRF: `PreventRequestForgery` con `allowSameSite: false` → una petición desde
  `mykesystems.com` (same-site) sin token es rechazada (419).
- `SANCTUM_STATEFUL_DOMAINS` solo contiene el panel: el endpoint público `/api/v1/leads`
  sigue sin sesión ni cookies.
- Rutas del panel restringidas al dominio `ADMIN_DOMAIN` (404 en el dominio público).
- Sesión con duración absoluta (12 h) y bloqueo inmediato de cuentas desactivadas.
- Auditoría con IP como HMAC; nunca contraseñas, códigos, tokens ni correos.

## 6. Configuración pendiente (producción, Fase G)

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `ADMIN_DOMAIN=admin.mykesystems.com`,
  `SANCTUM_STATEFUL_DOMAINS=admin.mykesystems.com`, `ADMIN_PANEL_URL=https://admin.mykesystems.com`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_COOKIE=__Host-myke_admin`, `HASH_DRIVER=argon2id`
  (verificar soporte Argon2), SMTP para correos de recuperación.
- El panel React y el API deben servirse desde el mismo host `admin.mykesystems.com`
  (API bajo `/api`), para que `Sec-Fetch-Site` sea `same-origin`.
- `APP_KEY` no debe cambiar: cifra los secretos 2FA y los hashes de auditoría.
