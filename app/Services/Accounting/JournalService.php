<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu untuk menulis jurnal: memastikan debit = kredit, akun aktif, dan nomor
 * bukti berurutan. Jurnal yang sudah diposting tidak diubah; koreksi lewat jurnal pembalik.
 */
class JournalService
{
    /**
     * @param  array{date:string,description:string,division?:?string,event_code?:?string,source?:?object,is_opening?:bool,reversal_of_id?:?int,created_by?:?int}  $header
     * @param  list<array{account:string,debit?:int|float,credit?:int|float,customer_id?:?int,memo?:?string}>  $lines
     */
    public function post(array $header, array $lines): JournalEntry
    {
        $lines = $this->normalize($lines);
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($header, $lines) {
            $date = Carbon::parse($header['date']);
            $isOpening = (bool) ($header['is_opening'] ?? false);
            $source = $header['source'] ?? null;
            $accounts = Account::whereIn('code', array_column($lines, 'account'))->get()->keyBy('code');

            $entry = JournalEntry::create([
                'number' => $this->nextNumber($date, $isOpening),
                'date' => $date->toDateString(),
                'division' => $header['division'] ?? null,
                'event_code' => $header['event_code'] ?? null,
                'auto_key' => $header['auto_key'] ?? null,
                'fingerprint' => $header['fingerprint'] ?? null,
                'description' => $header['description'],
                'source_type' => $source ? $source->getMorphClass() : null,
                'source_id' => $source?->getKey(),
                'is_opening' => $isOpening,
                'reversal_of_id' => $header['reversal_of_id'] ?? null,
                'created_by' => $header['created_by'] ?? auth()->id(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $accounts[$line['account']]->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'customer_id' => $line['customer_id'],
                    'memo' => $line['memo'],
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /** Jurnal pembalik (debit <-> kredit) untuk membatalkan jurnal yang salah. */
    public function reverse(JournalEntry $entry, ?string $date = null, ?string $reason = null): JournalEntry
    {
        if ($entry->reversedBy()->exists()) {
            throw ValidationException::withMessages(['entry' => "Jurnal {$entry->number} sudah pernah dibalik."]);
        }
        if ($entry->reversal_of_id) {
            throw ValidationException::withMessages(['entry' => 'Jurnal pembalik tidak bisa dibalik lagi.']);
        }

        $lines = $entry->lines()->with('account')->get()->map(fn ($l) => [
            'account' => $l->account->code, 'debit' => $l->credit, 'credit' => $l->debit, 'customer_id' => $l->customer_id, 'memo' => $l->memo,
        ])->all();

        return $this->post([
            'date' => $date ?? $entry->date->toDateString(),
            'description' => 'Pembalik '.$entry->number.($reason ? ': '.$reason : ''),
            'division' => $entry->division,
            'event_code' => $entry->event_code,
            'reversal_of_id' => $entry->id,
            'is_opening' => $entry->is_opening,
        ], $lines);
    }

    /**
     * Rapikan baris: angka bulat rupiah, buang baris nol, satu sisi saja per baris.
     *
     * @return list<array{account:string,debit:int,credit:int,customer_id:?int,memo:?string}>
     */
    public function normalize(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $debit = (int) round((float) ($line['debit'] ?? 0));
            $credit = (int) round((float) ($line['credit'] ?? 0));
            if ($debit < 0) {
                [$debit, $credit] = [0, $credit - $debit];
            }
            if ($credit < 0) {
                [$debit, $credit] = [$debit - $credit, 0];
            }
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            $out[] = [
                'account' => (string) $line['account'],
                'debit' => $debit,
                'credit' => $credit,
                'customer_id' => isset($line['customer_id']) && $line['customer_id'] !== '' ? (int) $line['customer_id'] : null,
                'memo' => isset($line['memo']) && $line['memo'] !== '' ? (string) $line['memo'] : null,
            ];
        }

        return $out;
    }

    public function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'Jurnal minimal berisi dua baris dengan nilai.']);
        }
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));
        if ($debit !== $credit) {
            throw ValidationException::withMessages(['lines' => 'Jurnal tidak seimbang: debit Rp'.number_format($debit, 0, ',', '.').' vs kredit Rp'.number_format($credit, 0, ',', '.').'.']);
        }
        foreach ($lines as $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                throw ValidationException::withMessages(['lines' => "Akun {$line['account']}: satu baris hanya boleh debit atau kredit."]);
            }
        }
        $codes = array_unique(array_column($lines, 'account'));
        $found = Account::whereIn('code', $codes)->where('is_active', true)->pluck('code')->all();
        $missing = array_diff($codes, $found);
        if ($missing) {
            throw ValidationException::withMessages(['lines' => 'Akun tidak ada atau tidak aktif: '.implode(', ', $missing).'.']);
        }
    }

    private function nextNumber(Carbon $date, bool $opening): string
    {
        $prefix = $opening ? 'SA-'.$date->format('Y').'-' : 'JU-'.$date->format('ym').'-';
        $last = JournalEntry::where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
