<?php

namespace App\Logging;

use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use RuntimeException;

final class EvaluationLogSanitizer
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof MonologLogger) {
            throw new RuntimeException('Evaluation log channel must use Monolog.');
        }

        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            $sanitizer = app(EvaluationSnapshotSanitizer::class);
            $message = $sanitizer->sanitize(['message' => $record->message])['message'];

            return $record->with(
                message: is_string($message) ? $message : $record->message,
                context: $sanitizer->sanitize($record->context),
                extra: $sanitizer->sanitize($record->extra),
            );
        });
    }
}
