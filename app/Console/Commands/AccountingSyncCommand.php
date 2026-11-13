<?php

namespace App\Console\Commands;

use App\Services\Accounting\AutoJournalService;
use Illuminate\Console\Command;

/** Sinkronkan jurnal otomatis (kasir, pembiayaan, potongan, simpanan, dll.) dengan data asalnya. */
class AccountingSyncCommand extends Command
{
    protected $signature = 'accounting:sync {--from= : Tanggal awal (bawaan: tanggal mulai otomatis)} {--to= : Tanggal akhir (bawaan: hari ini)}';

    protected $description = 'Buat/perbarui jurnal otomatis dari data aplikasi';

    public function handle(AutoJournalService $auto): int
    {
        $result = $auto->syncAll($this->option('from'), $this->option('to'));
        foreach ($result['stats'] as $k => $v) {
            $this->line(str_pad($k, 10).$v);
        }
        foreach (array_slice($result['messages'], 0, 20) as $m) {
            $this->warn($m);
        }

        return self::SUCCESS;
    }
}
