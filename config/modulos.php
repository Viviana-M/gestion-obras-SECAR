<?php

// Lista maestra de módulos del sistema.
// Cada módulo corresponde a una sección del menú lateral.
// CLAVE (izquierda): nombre interno, NO cambiar una vez creada.
// NOMBRE (derecha): lo que se muestra, este sí se puede cambiar.

return [
    'gestion_financiera' => 'Gestión financiera',
    'operacion'          => 'Operación',
    'contabilidad'       => 'Contabilidad',

    // Departamentos (controlan qué obras ve cada quien en Distribución de costos)
    'dep_mantenimiento'  => 'Depto. Mantenimiento',
    'dep_instalaciones'  => 'Depto. Instalaciones',
];