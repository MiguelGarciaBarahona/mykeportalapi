<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Finanzas personales (solo propietario)

Módulo del panel para registrar ingresos y gastos y sacar el reporte mensual.

- **Acceso:** permiso `manage-finances` (`AppServiceProvider`): cuenta activa con rol `owner`, además de la sesión con 2FA. Un `admin` recibe 403.
- **Tablas:** `finance_categories` (con categorías por defecto) y `finance_transactions` (montos en centavos enteros, moneda `HNL` o `USD`, borrado lógico). Las migraciones solo crean tablas nuevas.
- **Comprobantes:** disco privado `finance` (`storage/app/private/finance`), nunca público. JPG, PNG, WEBP o PDF de hasta 5 MB. Se descargan únicamente por `GET /api/admin/finance/transactions/{id}/receipt` con la sesión del propietario.
- **Reporte:** `GET /api/admin/finance/report?month=AAAA-MM` y `/report/export` (CSV UTF-8 con BOM para Excel, protegido contra fórmulas). Lempiras y dólares se suman por separado; no se convierten.
- **Auditoría:** `finance.transaction.*`, `finance.receipt.*`, `finance.category.*`, `finance.report.exported` (sin montos ni descripciones).

**Límites de subida (producción):** PHP trae `upload_max_filesize = 2M` por defecto. Para comprobantes de 5 MB:

```ini
upload_max_filesize = 6M
post_max_size = 8M
```

y en nginx `client_max_body_size 8m;` en el sitio del panel. XAMPP en local ya trae límites mayores.

## Libros de finanzas y notificaciones push

- **Libros:** `finance_books` (Personal y Empresa se crean solos; lo registrado antes queda en Personal). Categorías y movimientos pertenecen a un libro; todas las rutas son `/api/admin/finance/books/{book}/…` y un movimiento de otro libro responde 404.
- **Push:** Web Push propio (`app/Push`, sin dependencias: solo openssl), verificado con el vector oficial del RFC 8291.
  - Claves: `php artisan push:vapid --write` (solo agrega si faltan; cambiarlas invalida las suscripciones).
  - Avisos: nueva solicitud (al instante), solicitudes sin atender (`leads:notify-stale`, cada hora), cierre de mes (`finance:notify-month-end`, 17:00, solo propietario).
  - Producción: cron `* * * * * cd /ruta/api && php artisan schedule:run >> /dev/null 2>&1`. El servidor debe poder salir por HTTPS a los servicios de push (fcm.googleapis.com, *.push.services.mozilla.com, *.notify.windows.com, *.push.apple.com).
  - Privacidad: las notificaciones solo llevan el número de solicitud y el servicio; nunca nombres, correos, teléfonos ni montos.
