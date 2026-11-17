<?php

/*
| CORS — restringido a los orígenes del portal.
| En producción el formulario y el API comparten dominio (mykesystems.com/api/v1),
| así que CORS solo es necesario en desarrollo local (localhost:3000 → localhost:8000).
| Nunca usar '*' : permitiría que cualquier sitio envíe solicitudes desde el navegador.
*/

return [
    'paths' => ['api/v1/*'],
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('PUBLIC_ALLOWED_ORIGINS', 'https://mykesystems.com,https://www.mykesystems.com'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept'],
    'exposed_headers' => ['Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
