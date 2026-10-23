<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;

class EvalSplit extends Command
{
    protected $signature = 'eval:split
        {--dataset= : Archivo JSONL de entrada y salida}
        {--splits-file= : Archivo JSON de política y manifiesto}
        {--seed= : Semilla determinista (por defecto la de splits.json)}
        {--test-ratio= : Proporción del split test entre 0 y 1}
        {--include-examples : Incluye y reasigna casos example=true}
        {--strict : Falla y no escribe si no se alcanzan los mínimos}';

    protected $description = 'Asigna los splits dev/test de forma estratificada y agrupada';

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $datasetPath = $this->resolvePath($this->option('dataset'), 'evaluation/dataset/cases.jsonl');
        $splitsPath = $this->resolvePath($this->option('splits-file'), 'evaluation/dataset/splits.json');

        if (! is_file($datasetPath)) {
            $this->error("No existe el dataset: {$datasetPath}");

            return self::FAILURE;
        }

        if (! is_file($splitsPath)) {
            $this->error("No existe el archivo de política de splits: {$splitsPath}");

            return self::FAILURE;
        }
        if (strcasecmp((string) realpath($datasetPath), (string) realpath($splitsPath)) === 0) {
            $this->error('El dataset y el manifiesto deben ser archivos distintos.');

            return self::FAILURE;
        }

        $policy = $this->readPolicy($splitsPath);
        if ($policy === null) {
            return self::FAILURE;
        }

        $seed = $this->parseSeed($policy);
        $testRatio = $this->parseTestRatio($policy);
        $classes = $this->classes($policy);
        if ($seed === null || $testRatio === null || $classes === []) {
            return self::FAILURE;
        }

        $contents = file_get_contents($datasetPath);
        if ($contents === false) {
            $this->error("No se pudo leer el dataset: {$datasetPath}");

            return self::FAILURE;
        }

        $records = [];
        $outputLines = [];
        $seenIds = [];
        $groups = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $lineIndex => $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->error('JSON inválido en línea '.($lineIndex + 1).": {$exception->getMessage()}");

                return self::FAILURE;
            }

            if (! is_array($case) || array_is_list($case)) {
                $this->error('Línea '.($lineIndex + 1).': cada registro debe ser un objeto JSON.');

                return self::FAILURE;
            }

            if (($case['example'] ?? false) === true && ! $this->option('include-examples')) {
                $outputLines[] = $line;

                continue;
            }

            $id = $case['id'] ?? null;
            $verdict = $case['expected_verdict'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                $this->error('Línea '.($lineIndex + 1).': falta un id válido.');

                return self::FAILURE;
            }
            if (isset($seenIds[$id])) {
                $this->error('Línea '.($lineIndex + 1).": id duplicado '{$id}'.");

                return self::FAILURE;
            }
            if (! is_string($verdict) || ! in_array($verdict, $classes, true)) {
                $this->error('Línea '.($lineIndex + 1).': expected_verdict no pertenece a las clases configuradas.');

                return self::FAILURE;
            }

            $seenIds[$id] = true;
            $groupName = trim((string) ($case['claim_group'] ?? ''));
            $groupKey = $groupName !== '' ? 'claim:'.$groupName : 'case:'.$id;

            $records[] = [
                'case' => $case,
                'group_key' => $groupKey,
                'id' => $id,
                'output_index' => count($outputLines),
            ];
            $outputLines[] = '';
            $groups[$groupKey]['counts'][$verdict] = ($groups[$groupKey]['counts'][$verdict] ?? 0) + 1;
        }

        if ($records === []) {
            $this->error('No hay casos para dividir (los ejemplos se excluyen por defecto).');

            return self::FAILURE;
        }

        $groupAssignments = $this->assignGroups($groups, $seed, $testRatio);
        $caseAssignments = [];
        $distribution = [
            'dev' => array_fill_keys($classes, 0),
            'test' => array_fill_keys($classes, 0),
        ];

        foreach ($records as &$record) {
            $case = $record['case'];
            $verdict = (string) $case['expected_verdict'];
            $split = $groupAssignments[$record['group_key']];
            $case['split'] = $split;
            $record['case'] = $case;
            $caseAssignments[$record['id']] = $split;
            $distribution[$split][$verdict]++;
            try {
                $outputLines[$record['output_index']] = json_encode(
                    $case,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                $this->error("No se pudo serializar el caso '{$record['id']}': {$exception->getMessage()}");

                return self::FAILURE;
            }
        }
        unset($record);

        $this->warnAboutMinimums($distribution, $policy);
        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        if ($this->option('strict') && $this->warnings !== []) {
            $this->error('No se escribieron archivos porque --strict está activo y hay advertencias.');

            return self::FAILURE;
        }

        $manifest = array_merge($policy, [
            'seed' => $seed,
            'test_ratio' => $testRatio,
            'algorithm' => 'grouped-stratified-sha256-v1',
            'group_assignments' => $groupAssignments,
            'case_assignments' => $caseAssignments,
            'distribution' => $distribution,
        ]);

        try {
            $manifestContents = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            $this->error("No se pudo serializar el manifiesto: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $jsonlContents = implode(PHP_EOL, $outputLines).PHP_EOL;
        if (! $this->writeAtomically($datasetPath, $jsonlContents)
            || ! $this->writeAtomically($splitsPath, $manifestContents)) {
            return self::FAILURE;
        }

        $this->table(
            ['Split', ...$classes],
            [
                ['dev', ...array_values($distribution['dev'])],
                ['test', ...array_values($distribution['test'])],
            ],
        );
        $this->info('Asignación de splits guardada en el dataset y el manifiesto.');

        return self::SUCCESS;
    }

    private function resolvePath(mixed $providedPath, string $defaultPath): string
    {
        $path = is_string($providedPath) ? trim($providedPath) : '';
        if ($path === '') {
            $path = $defaultPath;
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            ? $path
            : base_path($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPolicy(string $path): ?array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->error("No se pudo leer el archivo de política: {$path}");

            return null;
        }

        try {
            $policy = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("JSON inválido en {$path}: {$exception->getMessage()}");

            return null;
        }

        if (! is_array($policy)) {
            $this->error("La política debe ser un objeto JSON: {$path}");

            return null;
        }

        return $policy;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function parseSeed(array $policy): ?int
    {
        $providedSeed = $this->option('seed');
        $seed = $providedSeed === null ? ($policy['seed'] ?? null) : $providedSeed;
        if (filter_var($seed, FILTER_VALIDATE_INT) === false) {
            $this->error('La semilla debe ser un entero.');

            return null;
        }

        return (int) $seed;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function parseTestRatio(array $policy): ?float
    {
        $providedRatio = $this->option('test-ratio');
        $ratio = $providedRatio === null ? ($policy['test_ratio'] ?? null) : $providedRatio;
        if (! is_numeric($ratio) || (float) $ratio <= 0.0 || (float) $ratio >= 1.0) {
            $this->error('La proporción de test debe ser un número mayor que 0 y menor que 1.');

            return null;
        }

        return (float) $ratio;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<string>
     */
    private function classes(array $policy): array
    {
        $classes = $policy['classes'] ?? null;
        if (! is_array($classes) || $classes === [] || array_filter($classes, 'is_string') !== $classes) {
            $this->error('La política debe definir una lista no vacía de clases.');

            return [];
        }

        return array_values($classes);
    }

    /**
     * @param  array<string, array{counts: array<string, int>}>  $groups
     * @return array<string, string>
     */
    private function assignGroups(array $groups, int $seed, float $testRatio): array
    {
        $strata = [];
        foreach ($groups as $key => $group) {
            $counts = $group['counts'];
            ksort($counts);
            $signature = implode('|', array_keys($counts));
            $strata[$signature][$key] = $group;
        }

        $assignments = [];
        foreach ($strata as $signature => $stratumGroups) {
            $keys = array_keys($stratumGroups);
            usort($keys, static fn (string $left, string $right): int => [
                hash('sha256', $seed.'|'.$signature.'|'.$left),
                $left,
            ] <=> [
                hash('sha256', $seed.'|'.$signature.'|'.$right),
                $right,
            ]);

            $groupCount = count($keys);
            $testCount = $groupCount === 1
                ? (hexdec(substr(hash('sha256', $seed.'|'.$signature.'|'.$keys[0]), 0, 8)) / 0xFFFFFFFF < $testRatio ? 1 : 0)
                : max(1, min($groupCount - 1, (int) round($groupCount * $testRatio)));

            foreach ($keys as $index => $key) {
                $assignments[$key] = $index < $testCount ? 'test' : 'dev';
            }
        }

        ksort($assignments);

        return $assignments;
    }

    /**
     * @param  array<string, array<string, int>>  $distribution
     * @param  array<string, mixed>  $policy
     */
    private function warnAboutMinimums(array $distribution, array $policy): void
    {
        $minimumPerSplit = max(0, (int) ($policy['minimum_cases_per_split'] ?? 0));
        $minimumPerClass = max(0, (int) ($policy['minimum_cases_per_class_per_split'] ?? 0));
        foreach ($distribution as $split => $classes) {
            $count = array_sum($classes);
            if ($count < $minimumPerSplit) {
                $this->warnings[] = "El split '{$split}' tiene {$count} casos; mínimo recomendado: {$minimumPerSplit}.";
            }

            foreach ($classes as $class => $classCount) {
                if ($classCount < $minimumPerClass) {
                    $this->warnings[] = "La clase '{$class}' del split '{$split}' tiene {$classCount} casos; mínimo recomendado: {$minimumPerClass}.";
                }
            }
        }
    }

    private function writeAtomically(string $path, string $contents): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            $this->error("No existe el directorio de salida: {$directory}");

            return false;
        }

        $temporaryPath = tempnam($directory, '.eval-split-');
        if ($temporaryPath === false) {
            $this->error("No se pudo crear archivo temporal en {$directory}.");

            return false;
        }

        $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            unlink($temporaryPath);
            $this->error("No se pudo escribir completamente el archivo temporal para {$path}.");

            return false;
        }

        if (is_file($path)) {
            $backupPath = tempnam($directory, '.eval-split-backup-');
            if ($backupPath === false) {
                unlink($temporaryPath);
                $this->error("No se pudo preparar el reemplazo de {$path}.");

                return false;
            }
            unlink($backupPath);
            if (! rename($path, $backupPath)) {
                unlink($temporaryPath);
                $this->error("No se pudo respaldar el archivo existente: {$path}");

                return false;
            }
            if (! rename($temporaryPath, $path)) {
                $restored = rename($backupPath, $path);
                unlink($temporaryPath);
                if (! $restored) {
                    $this->error("No se pudo reemplazar {$path} ni restaurar el respaldo {$backupPath}.");

                    return false;
                }
                $this->error("No se pudo reemplazar el archivo: {$path}");

                return false;
            }
            if (! unlink($backupPath)) {
                $this->error("El archivo se actualizó, pero no se pudo retirar el respaldo: {$backupPath}");

                return false;
            }

            return true;
        }

        if (! rename($temporaryPath, $path)) {
            unlink($temporaryPath);
            $this->error("No se pudo guardar el archivo: {$path}");

            return false;
        }

        return true;
    }
}
