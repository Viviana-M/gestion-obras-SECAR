<?php
\App\Models\ItemDistribucion::where('tipo_inventario', 'MATERIALES Y REPUESTOS')
    ->update(['tipo_inventario' => 'INV145525']);

foreach (\App\Models\LlaveItemCuenta::where('codigo_movimiento', '!=', '')->whereNotNull('codigo_movimiento')->get() as $l) {
    \App\Models\ItemDistribucion::where('tipo_inventario', $l->tipo_inventario)
        ->where('codigo_movimiento', $l->codigo_movimiento)
        ->update(['cuenta' => $l->cuenta, 'naturaleza' => $l->naturaleza]);
}

echo 'Items con cuenta 14200105: ' . \App\Models\ItemDistribucion::where('cuenta', '14200105')->count() . PHP_EOL;