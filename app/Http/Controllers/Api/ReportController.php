<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Services\Report\AnalysisReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class ReportController extends Controller
{
    public function __construct(private readonly AnalysisReportBuilder $builder) {}

    public function json(Analysis $analysis): JsonResponse|Response
    {
        $this->authorize('view', $analysis);

        if ($blocked = $this->notReady($analysis)) {
            return $blocked;
        }

        return response()->json(['data' => $this->builder->build($analysis)])
            ->header('Content-Disposition', "attachment; filename=\"informe-{$analysis->id}.json\"");
    }

    public function pdf(Analysis $analysis): JsonResponse|Response
    {
        $this->authorize('view', $analysis);

        if ($blocked = $this->notReady($analysis)) {
            return $blocked;
        }

        return Pdf::loadView('reports.analysis', ['report' => $this->builder->build($analysis)])
            ->setPaper('a4')
            ->download("informe-{$analysis->id}.pdf");
    }

    private function notReady(Analysis $analysis): ?JsonResponse
    {
        return $analysis->status === 'completed' && $analysis->score()->exists()
            ? null
            : response()->json(['message' => 'El informe estará disponible cuando termine el análisis.'], 409);
    }
}
