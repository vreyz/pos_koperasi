<?php

namespace App\Console\Commands;

use App\Services\Cooperative\MemberSync;
use Illuminate\Console\Command;

class SyncCooperativeMembers extends Command
{
    protected $signature = 'koperasi:sync-anggota';

    protected $description = 'Tandai pembayar simpanan sebagai anggota dan buat customer dari nama pembeli di data penjualan';

    public function handle(MemberSync $sync): int
    {
        $result = $sync->run();
        $this->info("Anggota ditandai: {$result['members']}, customer pembeli baru: {$result['buyers_created']}, baris penjualan terhubung: {$result['buyers_linked']}.");

        return self::SUCCESS;
    }
}
