<?php

namespace App\Imports\Financiero;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DiagnosticoImport implements ToArray, WithHeadingRow
{
    public function array(array $array): array
    {
        return $array;
    }
}