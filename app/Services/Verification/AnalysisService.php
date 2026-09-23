<?php

namespace App\Services\Verification;

use App\Models\Analysis;

final class AnalysisService
{
    public function analyze(Analysis $analysis): void
    {
        // Este método será el orquestador
        // del pipeline de verificación.

        // Más adelante:
        //
        // 1. Extraer claims
        // 2. Buscar evidencias
        // 3. Verificar claims
        // 4. Calcular score
    }
}