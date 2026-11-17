<?php

/*
|--------------------------------------------------------------------------
| MykeSystems — configuración de negocio
|--------------------------------------------------------------------------
| Valores propios del portal y del registro de solicitudes. Los datos
| sensibles (correos internos, orígenes, URL del panel) vienen del .env.
*/

$csv = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    /*
    | Dominio del panel administrativo. Las rutas de autenticación y del API
    | del panel solo responden en este dominio (null = sin restricción, solo local).
    */
    'admin_domain' => env('ADMIN_DOMAIN'),

    /*
    | Sesiones del panel: además de la expiración por inactividad (SESSION_LIFETIME),
    | toda sesión caduca a las N horas de iniciada, aunque haya actividad.
    */
    'admin_session' => [
        'absolute_lifetime_hours' => (int) env('ADMIN_SESSION_ABSOLUTE_HOURS', 12),
    ],

    /*
    | Servicios aceptados en el formulario público.
    | ⚠️ Debe coincidir con las opciones del portal
    |    (mykesystems-web/src/content/services.ts y src/content/contact.ts).
    */
    'services' => [
        // Desarrollo web y software
        'software-a-medida' => 'Desarrollo de software a medida',
        'desarrollo-web' => 'Desarrollo web',
        'desarrollo' => 'Otra solución de desarrollo web y software',
        // Sistemas empresariales
        'inventario-facturacion' => 'Inventario y facturación',
        'administracion-empresarial' => 'Sistemas de administración empresarial',
        'crm-erp' => 'CRM y ERP personalizados',
        'sistemas-empresariales' => 'Otra solución de sistemas empresariales',
        // Transformación digital
        'automatizacion' => 'Automatización de procesos',
        'modernizacion-mantenimiento' => 'Modernización y mantenimiento de software',
        'transformacion-digital' => 'Otra solución de transformación digital',
        // Otros
        'otro' => 'Otro / aún no estoy seguro',
    ],


    /*
    | Orígenes desde los que se aceptan solicitudes públicas (cabecera Origin).
    | Producción: https://mykesystems.com,https://www.mykesystems.com
    | Local:      http://localhost:3000
    */
    'allowed_origins' => $csv(env('PUBLIC_ALLOWED_ORIGINS', 'https://mykesystems.com,https://www.mykesystems.com')),

    /*
    | Protección antispam del formulario público.
    */
    'antispam' => [
        'honeypot_field' => 'website',
        'min_fill_seconds' => (int) env('ANTISPAM_MIN_FILL_SECONDS', 3),
        'max_form_age_hours' => 24,
        'max_links' => 2,
        'max_body_bytes' => 10_240,
    ],

    /*
    | Límites de solicitudes del endpoint público.
    | La IP nunca se guarda en claro: la clave del límite es un HMAC.
    */
    'rate_limits' => [
        'per_ip_per_minute' => (int) env('LEADS_LIMIT_PER_MINUTE', 3),
        'per_ip_per_hour' => (int) env('LEADS_LIMIT_PER_HOUR', 5),
        'global_per_day' => (int) env('LEADS_LIMIT_GLOBAL_PER_DAY', 200),
    ],

    /*
    | Notificación interna de nuevas solicitudes (sin datos personales).
    | El correo interno nunca se expone al visitante.
    */
    'notifications' => [
        'admin_email' => env('ADMIN_NOTIFY_EMAIL'),
        'admin_panel_url' => rtrim((string) env('ADMIN_PANEL_URL', 'https://admin.mykesystems.com'), '/'),
    ],

    /*
    | Política de conservación (se aplicará en la Fase F con un comando programado).
    | Valores provisionales, pendientes de la política documentada y revisión legal.
    */
    'retention' => [
        'anonymize_closed_after_months' => 12,
        'anonymize_uncontacted_after_months' => 6,
        'purge_soft_deleted_after_days' => 30,
    ],
    /*
    |--------------------------------------------------------------------------
    | API administrativa de solicitudes (Fase C)
    |--------------------------------------------------------------------------
    | - timezone: zona en la que el panel interpreta los filtros de fecha
    |   (Y-m-d). Las fechas se guardan y se devuelven en UTC (ISO 8601).
    */
    /*
     | Notificaciones push del panel (Web Push con VAPID). Las claves se generan con
     | `php artisan push:vapid --write`. Sin claves, el panel muestra las notificaciones como no disponibles.
     | Solo se envían a servicios de push conocidos de los navegadores (evita usar el servidor como proxy).
     */
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('ADMIN_PANEL_URL', 'https://admin.mykesystems.com')),
        'allowed_hosts' => $csv(env('PUSH_ALLOWED_HOSTS', 'fcm.googleapis.com,push.services.mozilla.com,notify.windows.com,push.apple.com')),
        'ttl_seconds' => 24 * 3600,
        'stale_lead_hours' => (int) env('PUSH_STALE_LEAD_HOURS', 24),
        // Archivo de certificados de confianza para HTTPS (solo si PHP no tiene uno configurado, p. ej. XAMPP).
        'ca_bundle' => env('PUSH_CA_BUNDLE'),
    ],

    'admin_api' => [
        'timezone' => env('ADMIN_TIMEZONE', 'America/Tegucigalpa'),
        'per_page_default' => 20,
        'per_page_max' => 100,
        'search_max_terms' => 5,
        'reads_per_minute' => (int) env('ADMIN_API_READS_PER_MINUTE', 120),
        'writes_per_minute' => (int) env('ADMIN_API_WRITES_PER_MINUTE', 30),
        'note_max_length' => 2000,
    ],

];
