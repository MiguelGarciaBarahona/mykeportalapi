# MykeSystems API — Fase A

Base del backend en **Laravel 13** (PHP 8.3+): modelos, migraciones y el endpoint
público que registra las solicitudes del formulario «Hablemos de tu proyecto».

> Esta carpeta contiene **solo los archivos propios de MykeSystems**. Se copian
> encima de un proyecto Laravel 13 recién creado (ver «Instalación local»).

---

## 1. Requisitos en tu PC (Windows)

Para ejecutar las pruebas solo necesitas **PHP 8.3 o superior** (con `pdo_sqlite`) y **Composer**.
Las pruebas usan SQLite en memoria: no necesitas instalar MariaDB para correrlas.

Opciones gratuitas:

- **Laravel Herd** (recomendado por Laravel): instala PHP y Composer. https://herd.laravel.com/windows
- **Laragon**: incluye PHP, MySQL/MariaDB y Composer.

Comprueba la versión con: `php -v` y `composer -V`.

## 2. Instalación local

En una terminal, dentro de la carpeta `MYKE`:

```bat
composer create-project laravel/laravel:^13.0 mykesystems-api
xcopy /E /Y /I mykesystems-api-fase-a mykesystems-api
cd mykesystems-api
php artisan test
```

`xcopy` copia los archivos de esta fase sobre el proyecto (reemplaza `bootstrap/app.php`,
`app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`,
`.env.example`, `phpunit.xml` y `tests/Feature/ExampleTest.php`).

## 3. Probar el endpoint a mano (opcional)

1. Edita `.env`: para pruebas locales puedes usar SQLite:
   ```
   DB_CONNECTION=sqlite
   PUBLIC_ALLOWED_ORIGINS=http://localhost:3000
   ADMIN_NOTIFY_EMAIL=solicitudes@mykesystems.com
   MAIL_MAILER=log
   QUEUE_CONNECTION=sync
   ```
   (borra o comenta las líneas `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
2. `php artisan migrate`
3. `php artisan serve` (queda en http://localhost:8000)
4. En otra terminal **PowerShell**:
   ```powershell
   $body = @{
     first_name = "Ana"; last_name = "Prueba"; phone = "9999-0000"
     email = "ana@ejemplo.com"; business = $null; service = "crm-erp"
     message = "Necesito un sistema para gestionar clientes y ventas."
     whatsapp_consent = $true; consent_version = "whatsapp-v1"
     source = "prueba-manual"; website = ""
     started_at = [DateTimeOffset]::UtcNow.AddSeconds(-30).ToUnixTimeMilliseconds()
   } | ConvertTo-Json
   Invoke-RestMethod -Method Post -Uri http://localhost:8000/api/v1/leads `
     -ContentType "application/json" -Headers @{ Origin = "http://localhost:3000" } -Body $body
   ```
   Respuesta esperada: `ok: True, id: MS-XXXXXX`. El aviso interno queda escrito en
   `storage/logs/laravel.log` (sin datos personales).

---

## 4. Contrato del endpoint

`POST /api/v1/leads` · `Content-Type: application/json` · máximo 10 KB · cabecera `Origin` obligatoria.

| Campo | Tipo | Reglas |
|---|---|---|
| `first_name`, `last_name` | string | Obligatorios, 2–60, solo letras, espacios, `'`, `.` y `-` |
| `phone` | string | Obligatorio. Local de 8 dígitos (asume +504) o internacional con `+`. Se guarda en E.164 |
| `email` | string | Obligatorio, formato RFC estricto, máximo 254. Se guarda en minúsculas |
| `business` | string\|null | Opcional, máximo 100 |
| `service` | string | Obligatorio, uno de `config('mykesystems.services')` |
| `message` | string | Obligatorio, 20–2000 caracteres, máximo 2 enlaces |
| `whatsapp_consent` | bool | Obligatorio (`false` por defecto en el formulario) |
| `consent_version` | string\|null | Obligatorio si hay consentimiento; debe existir en `config('mykesystems.consent_versions')` |
| `source` | string\|null | Botón de origen, p. ej. `servicios:crm-erp` |
| `started_at` | int | Milisegundos desde epoch en que se abrió el formulario |
| `website` | string | Campo trampa: debe llegar vacío |

| Respuesta | Significado |
|---|---|
| `201 {ok:true, id:"MS-7F3K9Q"}` | Solicitud **guardada** (solo entonces el portal muestra éxito) |
| `422 {message, errors:{campo:[...]}}` | Datos inválidos (mensajes en español) |
| `422 {ok:false, code:"rejected"}` | Bloqueada por antispam |
| `403 {code:"forbidden_origin"}` | No viene del portal |
| `413` / `415` / `405` | Demasiado grande / no es JSON / método no permitido |
| `429 {code:"too_many_requests", retry_after}` + `Retry-After` | Límite de solicitudes |

## 5. Medidas implementadas

- **Validación del servidor** completa (`StoreLeadRequest`), con limpieza previa de caracteres
  de control, invisibles y bidireccionales (`TextSanitizer`).
- **Antispam** (`SpamGuard`): campo trampa, tiempo mínimo de llenado (3 s), antigüedad máxima
  del formulario (24 h, tolerancia de reloj de 5 min) y máximo de 2 enlaces.
- **Límites**: 3/minuto y 5/hora por IP + 200/día global. La IP se usa solo como HMAC y
  expira con la caché; **no se guarda en la base de datos**. Los intentos inválidos también cuentan.
- **Origen**: solo `PUBLIC_ALLOWED_ORIGINS`; CORS restringido a esos orígenes (nunca `*`).
- **SQL**: solo Eloquent con asignación masiva controlada (`$fillable`); sin SQL manual.
- **Privacidad**: la respuesta solo devuelve el número de solicitud; el aviso interno no
  incluye nombre, correo, teléfono ni descripción; los rechazos antispam registran solo el motivo.
- **Consentimiento verificable**: se guarda `whatsapp_consent`, la fecha y la versión exacta
  del texto aceptado (`whatsapp-v1`). Sin consentimiento no se guarda fecha ni versión.
- **Número de solicitud** aleatorio `MS-XXXXXX` (no revela el volumen de solicitudes).
- **Historial**: cada solicitud registra su estado inicial con fecha y hora.

## 6. Archivos de esta fase

```
bootstrap/app.php                         rutas API, alias de middleware, errores en JSON
config/mykesystems.php                    servicios, consentimientos, orígenes, antispam, límites, conservación
config/cors.php                           CORS restringido
app/Enums/LeadStatus.php · AdminRole.php
app/Models/Lead.php · LeadStatusChange.php · LeadNote.php · AuditLog.php · User.php
app/Support/TextSanitizer.php · PhoneNormalizer.php · SpamGuard.php · PublicIdGenerator.php
app/Actions/CreateLead.php
app/Notifications/NewLeadReceived.php
app/Http/Requests/StoreLeadRequest.php
app/Http/Middleware/VerifyPublicOrigin.php · EnsureJsonRequest.php
app/Http/Controllers/Public/LeadController.php
app/Providers/AppServiceProvider.php      límites de solicitudes
routes/api.php · routes/web.php
database/migrations/2026_11_15_000100…000500  users (campos admin), leads, lead_status_changes, lead_notes, audit_logs
database/factories/LeadFactory.php        solo para pruebas
tests/Feature/PublicLeadEndpointTest.php  endpoint completo
tests/Feature/ExampleTest.php             reemplaza el ejemplo del esqueleto
tests/Unit/*Test.php                      lógica pura
phpunit.xml · .env.example
```

## 7. Pendiente para fases siguientes

- **B**: autenticación (Sanctum + Fortify), 2FA obligatorio, roles, auditoría, comando `admin:create`.
- **F**: comando programado de conservación/anonimización según la política documentada,
  backups y despliegue. Los plazos de `config/mykesystems.php → retention` son provisionales.
