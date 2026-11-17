<?php

namespace Tests\Unit;

use App\Support\SpamGuard;
use PHPUnit\Framework\TestCase;

class SpamGuardTest extends TestCase
{
    private const NOW = 1_800_000_000_000; // ms
    private const MESSAGE = 'Necesito un sistema para gestionar mis ventas.';

    private function guard(): SpamGuard
    {
        return new SpamGuard(minFillSeconds: 3, maxFormAgeHours: 24, maxLinks: 2);
    }

    public function test_acepta_un_envio_normal(): void
    {
        $this->assertNull($this->guard()->check('', self::NOW - 45_000, self::MESSAGE, self::NOW));
        $this->assertNull($this->guard()->check(null, self::NOW - 45_000, self::MESSAGE, self::NOW));
    }

    public function test_detecta_el_campo_trampa(): void
    {
        $this->assertSame(SpamGuard::HONEYPOT, $this->guard()->check('http://spam', self::NOW - 45_000, self::MESSAGE, self::NOW));
    }

    public function test_detecta_envios_demasiado_rapidos(): void
    {
        $this->assertSame(SpamGuard::TOO_FAST, $this->guard()->check('', self::NOW - 1_500, self::MESSAGE, self::NOW));
        $this->assertNull($this->guard()->check('', self::NOW - 3_000, self::MESSAGE, self::NOW));
    }

    public function test_tolera_pequeñas_diferencias_de_reloj(): void
    {
        // El reloj del navegador va 2 minutos adelantado.
        $this->assertNull($this->guard()->check('', self::NOW + 120_000, self::MESSAGE, self::NOW));
    }

    public function test_rechaza_marcas_de_tiempo_absurdas(): void
    {
        $this->assertSame(SpamGuard::STALE_OR_INVALID_TIME, $this->guard()->check('', null, self::MESSAGE, self::NOW));
        $this->assertSame(SpamGuard::STALE_OR_INVALID_TIME, $this->guard()->check('', 0, self::MESSAGE, self::NOW));
        $this->assertSame(SpamGuard::STALE_OR_INVALID_TIME, $this->guard()->check('', self::NOW - 25 * 3_600_000, self::MESSAGE, self::NOW));
        $this->assertSame(SpamGuard::STALE_OR_INVALID_TIME, $this->guard()->check('', self::NOW + 600_000, self::MESSAGE, self::NOW));
    }

    public function test_limita_la_cantidad_de_enlaces(): void
    {
        $twoLinks = 'Mi sitio es https://a.example y la tienda www.b.example, ¿pueden mejorarlo?';
        $threeLinks = $twoLinks.' También http://c.example';

        $this->assertNull($this->guard()->check('', self::NOW - 45_000, $twoLinks, self::NOW));
        $this->assertSame(SpamGuard::TOO_MANY_LINKS, $this->guard()->check('', self::NOW - 45_000, $threeLinks, self::NOW));
        $this->assertSame(3, SpamGuard::countLinks($threeLinks));
    }
}
