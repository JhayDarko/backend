<?php

namespace Tests\Feature\Evaluation;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalSplitTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_it_keeps_claim_groups_together_and_stratifies_classes(): void
    {
        [$dataset, $splitsFile] = $this->createRunFiles();

        $this->artisan('eval:split', [
            '--dataset' => $dataset,
            '--splits-file' => $splitsFile,
            '--test-ratio' => '0.5',
        ])->assertSuccessful();

        $cases = $this->readCases($dataset);
        $this->assertSame($cases['credible-a1']['split'], $cases['credible-a2']['split']);
        $this->assertSame('dev', $cases['example-preserved']['split']);

        $manifest = json_decode((string) file_get_contents($splitsFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('example-preserved', $manifest['case_assignments']);
        foreach (['credible', 'misleading', 'false', 'inconclusive'] as $verdict) {
            $this->assertGreaterThan(0, $manifest['distribution']['dev'][$verdict]);
            $this->assertGreaterThan(0, $manifest['distribution']['test'][$verdict]);
        }
    }

    public function test_assignments_are_reproducible_for_the_same_seed(): void
    {
        [$firstDataset, $firstManifest] = $this->createRunFiles();
        [$secondDataset, $secondManifest] = $this->createRunFiles();

        foreach ([[$firstDataset, $firstManifest], [$secondDataset, $secondManifest]] as [$dataset, $splitsFile]) {
            $this->artisan('eval:split', [
                '--dataset' => $dataset,
                '--splits-file' => $splitsFile,
                '--seed' => '20261022',
                '--test-ratio' => '0.5',
            ])->assertSuccessful();
        }

        $first = json_decode((string) file_get_contents($firstManifest), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode((string) file_get_contents($secondManifest), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($first['case_assignments'], $second['case_assignments']);
        $this->assertSame($first['distribution'], $second['distribution']);
    }

    public function test_it_warns_about_minimums_and_strict_mode_does_not_write(): void
    {
        [$dataset, $splitsFile] = $this->createRunFiles();
        $datasetBefore = file_get_contents($dataset);
        $manifestBefore = file_get_contents($splitsFile);

        $this->artisan('eval:split', [
            '--dataset' => $dataset,
            '--splits-file' => $splitsFile,
            '--strict' => true,
        ])
            ->expectsOutputToContain('mínimo recomendado')
            ->assertFailed();

        $this->assertSame($datasetBefore, file_get_contents($dataset));
        $this->assertSame($manifestBefore, file_get_contents($splitsFile));
    }

    /**
     * @return array{string, string}
     */
    private function createRunFiles(): array
    {
        $suffix = bin2hex(random_bytes(6));
        $dataset = sys_get_temp_dir().DIRECTORY_SEPARATOR."eval-split-{$suffix}.jsonl";
        $splitsFile = sys_get_temp_dir().DIRECTORY_SEPARATOR."eval-splits-{$suffix}.json";
        $this->temporaryPaths[] = $dataset;
        $this->temporaryPaths[] = $splitsFile;

        File::copy(base_path('tests/Fixtures/Evaluation/split_cases.jsonl'), $dataset);
        File::copy(base_path('evaluation/dataset/splits.json'), $splitsFile);

        return [$dataset, $splitsFile];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readCases(string $path): array
    {
        $cases = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $cases[$case['id']] = $case;
        }

        return $cases;
    }
}
