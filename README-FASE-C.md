# MykeSystems API — Fase C: API administrativa de solicitudes

Requiere la Fase B (sesión, 2FA confirmado y cuenta activa). Todas las rutas están en
`admin.mykesystems.com/api/admin` (en local, sin restricción de dominio si `ADMIN_DOMAIN` está vacío).

## Endpoints

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/dashboard` | owner, admin | Totales (total, nuevas, en proceso, aceptadas, cerradas), por estado, recibidas 7/30 días, con consentimiento WhatsApp |
| GET | `/leads` | owner, admin | Listado paginado con búsqueda y filtros |
| GET | `/leads/options` | owner, admin | Estados, grupos, servicios y órdenes válidos para los filtros |
| GET | `/leads/{MS-XXXXXX}` | owner, admin | Detalle, historial, notas y enlace de WhatsApp (auditado: `lead.viewed`) |
| PATCH | `/leads/{MS-XXXXXX}/status` | owner, admin | `{status}` · registra historial y auditoría `lead.status_changed` |
| POST | `/leads/{MS-XXXXXX}/notes` | owner, admin | `{body}` (máx. 2000) · auditoría `lead.note_added` (sin contenido) |

Exportar, eliminar y anonimizar quedan reservados al owner en `LeadPolicy` (Fase F).

### Parámetros de `/leads`

`search` · `status` (`nueva,contactado` o `status[]=`; admite `en_proceso`) · `service` ·
`date_from` / `date_to` (`AAAA-MM-DD`, zona `ADMIN_TIMEZONE`, ambos incluidos) ·
`whatsapp` (`true`/`false`) · `sort` (`-created_at`, `created_at`, `-status_changed_at`) ·
`per_page` (1–100, predeterminado 20) · `page`.

### Formato de respuestas

- Éxito: `{data}` · listado: `{data, meta:{current_page,last_page,per_page,total,from,to}}`.
- Error: `{ok:false, code, message}` — `unauthenticated` 401, `forbidden` 403,
  `two_factor_setup_required` 403, `account_disabled` 403, `not_found` 404,
  `lead_anonymized` 409, `csrf_token_mismatch` 419, `too_many_requests` 429 (+ `Retry-After`).
- Validación: `{message, errors:{campo:[...]}}` 422.
- Fechas en UTC, ISO 8601. Las solicitudes se identifican por su número `MS-XXXXXX`;
  nunca se devuelven IDs internos ni correos de administradores (solo su nombre).

### Minimización

- El listado no incluye correo, teléfono ni mensaje; el detalle sí, y cada consulta queda auditada.
- El enlace `https://wa.me/...` solo aparece con consentimiento válido: aceptado, con fecha,
  con una versión de texto reconocida en `consent_versions`, teléfono E.164 y solicitud no anonimizada.

## Límites

- Lecturas: `ADMIN_API_READS_PER_MINUTE` (120) por administrador.
- Escrituras: `ADMIN_API_WRITES_PER_MINUTE` (30) por administrador.

## Pruebas

```bash
php artisan config:clear
php artisan test --filter=Leads
php artisan test
```

Los datos ficticios existen solo en las fábricas usadas por las pruebas; no hay seeders.
