<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda contra la regresión del hotfix: el "pintado inicial" de Otros costos usa fmt() (un const
 * arrow), así que NO puede ejecutarse en el nivel superior del <script> antes de que fmt exista
 * (temporal dead zone) — eso rompía todo el script y dejaba la barra "Total a distribuir" en $0.
 * Debe ejecutarse después de definir fmt (dentro del arranque en DOMContentLoaded).
 */
class DistribucionBarraGlobalJsTest extends TestCase
{
    #[Test]
    public function el_pintado_inicial_de_otros_costos_va_despues_de_definir_fmt(): void
    {
        $js = file_get_contents(resource_path('views/operativo/distribucion.blade.php'));

        $posFmt     = strpos($js, 'const fmt =');
        $posPintado = strpos($js, 'actualizarOtrosCostosTopCard(c)); // pintado inicial');

        $this->assertNotFalse($posFmt);
        $this->assertNotFalse($posPintado, 'Debe existir el pintado inicial de Otros costos.');
        // El pintado inicial (que llama a fmt) debe ir DESPUÉS de la definición de fmt.
        $this->assertGreaterThan($posFmt, $posPintado,
            'El pintado inicial de Otros costos no puede ejecutarse antes de definir const fmt.');
    }
}
