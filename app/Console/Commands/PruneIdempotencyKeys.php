<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'slotlock:prune-idempotency';

    protected $description = 'Borra claves de idempotencia vencidas';

    public function handle(): int
    {
        $count = DB::table('idempotency_keys')
            ->whereRaw('expires_at <= now()')
            ->delete();

        $this->components->info("Claves eliminadas: {$count}");

        return self::SUCCESS;
    }
}
