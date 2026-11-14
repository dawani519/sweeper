<?php

namespace App\Console\Commands;

use App\Models\DepositAddress;
use App\Services\Sweeper\SweepService;
use Illuminate\Console\Command;

class SweeperTick extends Command
{
    protected $signature = 'sweeper:tick {--chain= : Limit to one chain}';

    protected $description = 'Scan deposit addresses, open sweeps and advance in-flight ones';

    public function handle(SweepService $svc): int
    {
        $q = DepositAddress::where('active', true)->orderBy('last_scanned_at');
        if ($c = $this->option('chain')) {
            $q->where('chain', $c);
        }

        $n = 0;
        $q->each(function (DepositAddress $a) use ($svc, &$n) {
            try {
                $n += $svc->scan($a);
            } catch (\Throwable $e) {
                $this->warn("{$a->chain}:{$a->address} {$e->getMessage()}");
            }
        });
        $this->info("tick done, {$n} sweep action(s)".(config('sweeper.dry_run') ? ' [dry-run]' : ''));

        return self::SUCCESS;
    }
}
