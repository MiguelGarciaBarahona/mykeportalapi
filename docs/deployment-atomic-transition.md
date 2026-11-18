# Transición futura a despliegues atómicos

El workflow actual conserva el `document root` existente y publica de forma
controlada sobre `/www/wwwroot/mykeportalapi`. Antes de copiar valida un release
aislado, crea un rollback completo del código y preserva los datos persistentes.

Una segunda fase puede eliminar la breve ventana de copia usando esta estructura:

```text
/www/wwwroot/mykeportalapi-shared/.env
/www/wwwroot/mykeportalapi-shared/storage/
/www/wwwroot/mykeportalapi-releases/<sha>/
/www/wwwroot/mykeportalapi-current -> mykeportalapi-releases/<sha>
```

Nginx apuntaría a `/www/wwwroot/mykeportalapi-current/public`. Cada release
enlazaría `.env` y `storage` desde el directorio persistente. La publicación se
reduciría a cambiar el enlace `mykeportalapi-current` mediante `ln -sfn` y `mv`.

## Condiciones previas

1. Respaldar y validar el vhost de `api.mykesystems.com`.
2. Confirmar que `public/.user.ini` permite el nuevo path completo en
   `open_basedir`.
3. Mantener `public/.well-known` fuera de los releases o enlazarlo desde un
   directorio persistente.
4. Verificar que PHP-FPM 8.4 pueda atravesar todos los directorios del release.
5. Probar el vhost con `nginx -t` antes de cualquier recarga.
6. Preparar el enlace anterior para rollback antes de cambiar `current`.

## Validación de la transición

- Construir un release sin modificar el enlace activo.
- Ejecutar Artisan como `www` dentro del release.
- Probar el release mediante un vhost local o una ruta de prepublicación.
- Cambiar el enlace de forma atómica.
- Confirmar los códigos HTTP 405, 404, 401 y 204 definidos en el workflow.
- Revisar los registros de Nginx, PHP-FPM y Laravel.

## Rollback

Si falla una comprobación posterior, se restaura atómicamente el enlace al
release anterior. `.env`, `storage`, sesiones, logs, cargas, `.user.ini` y
`.well-known` no se reemplazan durante el cambio de versión.

Esta transición requiere una modificación planificada de Nginx y no forma parte
de la corrección actual.
