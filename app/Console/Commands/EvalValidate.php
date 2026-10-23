<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use JsonException;

class EvalValidate extends Command
{
    protected $signature = 'eval:validate
        {--strict : Devuelve fallo también cuando no se alcanzan los mínimos}
        {--include-examples : Incluye los casos marcados example=true}
        {--dataset= : Ruta al archivo JSONL (por defecto evaluation/dataset/cases.jsonl)}';

    protected $description = 'Valida el dataset de evaluación y muestra su distribución';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $datasetPath = $this->datasetPath();
        $schemaPath = base_path('evaluation/dataset/schema.json');
        $splitsPath = base_path('evaluation/dataset/splits.json');

        if (! is_file($datasetPath)) {
            $this->error("No existe el dataset: {$datasetPath}");

            return self::FAILURE;
        }

        $schema = $this->readJsonFile($schemaPath);
        $splitPolicy = $this->readJsonFile($splitsPath);
        if ($schema === null || $splitPolicy === null) {
            return self::FAILURE;
        }

        $contents = file_get_contents($datasetPath);
        if ($contents === false) {
            $this->error("No se pudo leer el dataset: {$datasetPath}");

            return self::FAILURE;
        }

        $cases = [];
        $seenIds = [];
        $seenUrls = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $lineIndex => $line) {
            $lineNumber = $lineIndex + 1;
            if (trim($line) === '') {
                continue;
            }

            try {
                $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->errors[] = "Línea {$lineNumber}: JSON inválido ({$exception->getMessage()}).";

                continue;
            }

            if (! is_array($case) || array_is_list($case)) {
                $this->errors[] = "Línea {$lineNumber}: cada registro debe ser un objeto JSON.";

                continue;
            }

            if (! $this->option('include-examples') && ($case['example'] ?? false) === true) {
                continue;
            }

            foreach ($this->validateAgainstSchema($case, $schema, '$') as $message) {
                $this->errors[] = "Línea {$lineNumber}: {$message}";
            }

            if (($case['split'] ?? null) === null && ($case['status'] ?? null) !== 'candidato_sin_verificar') {
                $this->errors[] = "Línea {$lineNumber}: split solo puede ser null si status es candidato_sin_verificar.";
            }

            $id = $case['id'] ?? null;
            if (is_string($id) && $id !== '') {
                if (isset($seenIds[$id])) {
                    $this->errors[] = "Línea {$lineNumber}: id duplicado '{$id}' (primera aparición en línea {$seenIds[$id]}).";
                } else {
                    $seenIds[$id] = $lineNumber;
                }
            }

            $url = $case['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $this->errors[] = "Línea {$lineNumber}: URL de caso inválida.";
                } else {
                    $normalizedUrl = $this->normalizeUrl($url);
                    if (isset($seenUrls[$normalizedUrl])) {
                        $this->errors[] = "Línea {$lineNumber}: URL duplicada (primera aparición en línea {$seenUrls[$normalizedUrl]}).";
                    } else {
                        $seenUrls[$normalizedUrl] = $lineNumber;
                    }
                }
            }

            foreach ($this->caseUrls($case) as $sourceUrl) {
                if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
                    $this->errors[] = "Línea {$lineNumber}: URL de fuente de etiquetado inválida.";
                }
            }

            if ($this->hasLongText($case)) {
                $this->errors[] = "Línea {$lineNumber}: se detectó texto demasiado largo; guarda solo resúmenes breves y rutas locales.";
            }

            $this->warnAboutCaseMetadata($case, $lineNumber);

            $cases[] = $case;
        }

        $distribution = $this->distribution($cases, $splitPolicy);
        $this->warnAboutMinimums($distribution, $splitPolicy);
        $this->renderDistribution($distribution);

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($this->errors as $error) {
            $this->error($error);
        }

        if ($this->errors !== [] || ($this->option('strict') && $this->warnings !== [])) {
            return self::FAILURE;
        }

        $this->info('Validación del dataset completada.');

        return self::SUCCESS;
    }

    private function datasetPath(): string
    {
        $providedPath = trim((string) $this->option('dataset'));
        if ($providedPath === '') {
            return base_path('evaluation/dataset/cases.jsonl');
        }

        return str_starts_with($providedPath, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $providedPath) === 1
            ? $providedPath
            : base_path($providedPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("No existe el archivo requerido: {$path}");

            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->error("No se pudo leer el archivo requerido: {$path}");

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("JSON inválido en {$path}: {$exception->getMessage()}");

            return null;
        }

        if (! is_array($decoded)) {
            $this->error("El archivo JSON debe contener un objeto: {$path}");

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateAgainstSchema(array $value, array $schema, string $path): array
    {
        $errors = [];
        foreach ($schema['required'] ?? [] as $required) {
            if (is_string($required) && ! array_key_exists($required, $value)) {
                $errors[] = "{$path}.{$required} es obligatorio.";
            }
        }

        $properties = $schema['properties'] ?? [];
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_diff(array_keys($value), array_keys($properties)) as $unknownProperty) {
                $errors[] = "{$path}.{$unknownProperty} no está permitido por el esquema.";
            }
        }

        foreach ($properties as $property => $propertySchema) {
            if (! array_key_exists($property, $value) || ! is_array($propertySchema)) {
                continue;
            }

            array_push(
                $errors,
                ...$this->validateValueAgainstSchema($value[$property], $propertySchema, "{$path}.{$property}"),
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateValueAgainstSchema(mixed $value, array $schema, string $path): array
    {
        $errors = [];
        $types = $schema['type'] ?? null;
        $types = is_array($types) ? $types : [$types];
        if ($types !== [null] && ! $this->matchesAnyType($value, $types)) {
            return ["{$path} debe ser de tipo ".implode('|', array_map('strval', $types)).'.'];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} debe ser uno de: ".implode(', ', $schema['enum']).'.';
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < (int) ($schema['minLength'] ?? 0)) {
                $errors[] = "{$path} no puede estar vacío.";
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $errors[] = "{$path} supera el máximo de {$schema['maxLength']} caracteres.";
            }
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\\/', (string) $schema['pattern']).'/', $value) !== 1) {
                $errors[] = "{$path} no cumple el formato requerido.";
            }
            if (($schema['format'] ?? null) === 'date-or-month' && $value !== '' && ! $this->isValidPublishedDate($value)) {
                $errors[] = "{$path} debe tener formato AAAA-MM-DD o AAAA-MM.";
            }
        }

        if (is_array($value) && array_is_list($value)) {
            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                foreach ($value as $index => $item) {
                    if (($itemSchema['type'] ?? null) === 'object' && is_array($item) && ! array_is_list($item)) {
                        array_push($errors, ...$this->validateAgainstSchema($item, $itemSchema, "{$path}[{$index}]"));
                    } else {
                        array_push($errors, ...$this->validateValueAgainstSchema($item, $itemSchema, "{$path}[{$index}]"));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ! array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return strtolower(trim($url));
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower((string) $key), 'utm_')) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$path
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param  array<string, mixed>  $case
     * @return list<string>
     */
    private function caseUrls(array $case): array
    {
        $urls = [];
        foreach ($case['label_sources'] ?? [] as $source) {
            if (is_array($source) && is_string($source['url'] ?? null)) {
                $urls[] = $source['url'];
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function hasLongText(array $case): bool
    {
        foreach (['claim_summary', 'label_rationale', 'notes', 'verify_notes', 'input_text_hint'] as $field) {
            if (isset($case[$field]) && is_string($case[$field]) && mb_strlen($case[$field]) > 500) {
                return true;
            }
        }

        foreach ($this->caseUrls($case) as $url) {
            if (mb_strlen($url) > 2048) {
                return true;
            }
        }

        return false;
    }

    private function isValidPublishedDate(string $value): bool
    {
        $format = preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? '!Y-m' : '!Y-m-d';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(substr($format, 1)) === $value;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function warnAboutCaseMetadata(array $case, int $lineNumber): void
    {
        $id = is_string($case['id'] ?? null) ? $case['id'] : "línea {$lineNumber}";
        if (($case['status'] ?? null) !== 'verificado') {
            $status = $case['status'] ?? 'sin estado';
            $this->warnings[] = "El caso '{$id}' no está verificado (estado: {$status}).";
        }

        foreach ($case['label_sources'] ?? [] as $index => $source) {
            if (is_array($source) && array_key_exists('url', $source) && $source['url'] === null) {
                $this->warnings[] = "El caso '{$id}' tiene URL nula en label_sources[{$index}].";
            }
        }

        if (! array_key_exists('published_at', $case) || $case['published_at'] === null || $case['published_at'] === '') {
            $this->warnings[] = "El caso '{$id}' no tiene fecha published_at.";
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  array<string, mixed>  $splitPolicy
     * @return array<string, array<string, int>>
     */
    private function distribution(array $cases, array $splitPolicy): array
    {
        $classes = is_array($splitPolicy['classes'] ?? null)
            ? $splitPolicy['classes']
            : ['credible', 'misleading', 'false', 'inconclusive'];
        $splits = is_array($splitPolicy['splits'] ?? null) ? $splitPolicy['splits'] : ['dev', 'test'];
        $distribution = [];
        foreach ($splits as $split) {
            if (! is_string($split)) {
                continue;
            }
            $distribution[$split] = array_fill_keys(array_filter($classes, 'is_string'), 0);
        }
        $distribution['sin_split'] = array_fill_keys(array_filter($classes, 'is_string'), 0);

        foreach ($cases as $case) {
            $split = $case['split'] ?? null;
            $verdict = $case['expected_verdict'] ?? null;
            if (is_string($split) && is_string($verdict) && isset($distribution[$split][$verdict])) {
                $distribution[$split][$verdict]++;
            } elseif ($split === null && is_string($verdict) && isset($distribution['sin_split'][$verdict])) {
                $distribution['sin_split'][$verdict]++;
            }
        }

        return $distribution;
    }

    /**
     * @param  array<string, array<string, int>>  $distribution
     * @param  array<string, mixed>  $splitPolicy
     */
    private function warnAboutMinimums(array $distribution, array $splitPolicy): void
    {
        $minimumPerSplit = max(0, (int) ($splitPolicy['minimum_cases_per_split'] ?? 0));
        $minimumPerClass = max(0, (int) ($splitPolicy['minimum_cases_per_class_per_split'] ?? 0));
        foreach ($distribution as $split => $classes) {
            if ($split === 'sin_split') {
                continue;
            }

            $splitCount = array_sum($classes);
            if ($splitCount < $minimumPerSplit) {
                $this->warnings[] = "El split '{$split}' tiene {$splitCount} casos; mínimo recomendado: {$minimumPerSplit}.";
            }

            foreach ($classes as $class => $count) {
                if ($count < $minimumPerClass) {
                    $this->warnings[] = "La clase '{$class}' del split '{$split}' tiene {$count} casos; mínimo recomendado: {$minimumPerClass}.";
                }
            }
        }
    }

    /**
     * @param  array<string, array<string, int>>  $distribution
     */
    private function renderDistribution(array $distribution): void
    {
        $this->newLine();
        $this->line('Distribución de casos (ejemplos excluidos salvo --include-examples):');
        $rows = [];
        foreach ($distribution as $split => $classes) {
            foreach ($classes as $class => $count) {
                $rows[] = [$split === 'sin_split' ? 'sin asignar' : $split, $class, $count];
            }
        }
        $this->table(['Split', 'Clase', 'Casos'], $rows);
    }
}
