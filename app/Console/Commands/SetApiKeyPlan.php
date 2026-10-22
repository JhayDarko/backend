<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class SetApiKeyPlan extends Command
{
    protected $signature = 'apikeys:plan {id : API key id} {plan : free|pro}';

    protected $description = 'Change the plan of an API key';

    public function handle(): int
    {
        $plan = (string) $this->argument('plan');

        if (! array_key_exists($plan, config('plans'))) {
            $this->error('Plan inválido. Usa: '.implode(', ', array_keys(config('plans'))));

            return self::FAILURE;
        }

        $key = ApiKey::find($this->argument('id'));
        if (! $key) {
            $this->error('API key no encontrada.');

            return self::FAILURE;
        }

        $key->update(['plan' => $plan]);
        $this->info("API key #{$key->id} ahora tiene el plan {$plan}.");

        return self::SUCCESS;
    }
}
