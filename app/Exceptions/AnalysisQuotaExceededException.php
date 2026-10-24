<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class AnalysisQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $dailyLimit,
        public readonly int $activeAnalyses,
        public readonly int $concurrentLimit,
        public readonly string $resetsAt,
    ) {
        parent::__construct('The user analysis quota has been exceeded.');
    }

    public function response(): JsonResponse
    {
        $message = $this->reason === 'daily'
            ? "Alcanzaste el límite diario de {$this->dailyLimit} análisis. El cupo se restablece el {$this->resetsAt}."
            : "Ya tienes {$this->activeAnalyses} análisis en curso (máximo {$this->concurrentLimit}). El cupo diario se restablece el {$this->resetsAt}.";

        return response()->json([
            'code' => 'daily_quota_exceeded',
            'message' => $message,
            'reason' => $this->reason,
            'resets_at' => $this->resetsAt,
        ], 429);
    }
}
