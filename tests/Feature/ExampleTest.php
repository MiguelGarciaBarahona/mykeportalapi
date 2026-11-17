<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Reemplaza la prueba de ejemplo del esqueleto: esta aplicación es solo API
 * y no publica páginas web.
 */
class ExampleTest extends TestCase
{
    public function test_no_publica_paginas_web(): void
    {
        $this->get('/')->assertNotFound();
    }

    public function test_el_chequeo_de_salud_responde(): void
    {
        $this->get('/up')->assertOk();
    }
}
