<?php

namespace App\Console\Commands;

use App\Services\HoldExpiryService;
use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'slotlock:expire-holds';

    protected $description = 'Marca como expired los holds vencidos (higiene; la corrección la da la ruta de escritura)';

    public function handle(HoldExpiryService $expiry): int
    {
        $count = $expiry->all();

        $this->components->info("Holds expirados: {$count}");

        return self::SUCCESS;
    }
}
