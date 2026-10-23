<?php

namespace Tests\Feature\Evaluation;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalValidateTest extends TestCase
{
    public function test_it_validates_dataset_examples_while_excluding_them_from_distribution(): void
    {
        $this->artisan('eval:validate')
            ->expectsOutputToContain('ejemplos excluidos')
            ->expectsOutputToContain('Validación del dataset completada.')
            ->assertSuccessful();
    }

    public function test_it_includes_examples_only_when_requested(): void
    {
        $this->artisan('eval:validate', ['--include-examples' => true])
            ->expectsOutputToContain('credible')
            ->expectsOutputToContain('misleading')
            ->expectsOutputToContain('inconclusive')
            ->assertSuccessful();
    }

    public function test_minimum_coverage_is_a_warning_unless_strict_mode_is_enabled(): void
    {
        $this->artisan('eval:validate')
            ->expectsOutputToContain('mínimo recomendado')
            ->assertSuccessful();

        $this->artisan('eval:validate', ['--strict' => true])
            ->expectsOutputToContain('mínimo recomendado')
            ->assertFailed();
    }

    public function test_it_accepts_the_pilot_and_warns_about_pending_verification_metadata(): void
    {
        $this->artisan('eval:validate')
            ->expectsOutputToContain('no está verificado')
            ->expectsOutputToContain('tiene URL nula en label_sources')
            ->expectsOutputToContain('no tiene fecha published_at')
            ->expectsOutputToContain('sin asignar')
            ->doesntExpectOutputToContain('debe ser de tipo')
            ->assertSuccessful();
    }

    public function test_it_rejects_duplicate_ids_and_urls(): void
    {
        $source = base_path('evaluation/dataset/cases.jsonl');
        $temporary = tempnam(sys_get_temp_dir(), 'eval-cases-');
        $this->assertNotFalse($temporary);

        try {
            $lines = file($source, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $case = json_decode((string) $lines[0], true, 512, JSON_THROW_ON_ERROR);
            $case['split'] = 'test';
            $case['url'] = 'https://example.org/duplicated';
            $duplicate = $case;
            $duplicate['split'] = 'dev';
            file_put_contents($temporary, json_encode($case, JSON_THROW_ON_ERROR).PHP_EOL.json_encode($duplicate, JSON_THROW_ON_ERROR));

            $this->artisan('eval:validate', ['--dataset' => $temporary, '--include-examples' => true])
                ->expectsOutputToContain('id duplicado')
                ->expectsOutputToContain('URL duplicada')
                ->assertFailed();
        } finally {
            File::delete($temporary);
        }
    }

    public function test_it_rejects_unexpected_fields_and_long_text(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'eval-cases-');
        $this->assertNotFalse($temporary);

        try {
            $case = json_decode((string) file(base_path('evaluation/dataset/cases.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0], true, 512, JSON_THROW_ON_ERROR);
            $case['unrecognized'] = true;
            $case['claim_summary'] = str_repeat('x', 501);
            file_put_contents($temporary, json_encode($case, JSON_THROW_ON_ERROR));

            $this->artisan('eval:validate', ['--dataset' => $temporary, '--include-examples' => true])
                ->expectsOutputToContain('no está permitido por el esquema')
                ->expectsOutputToContain('supera el máximo')
                ->assertFailed();
        } finally {
            File::delete($temporary);
        }
    }
}
