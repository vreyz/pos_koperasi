<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\CustomerAlias;
use Illuminate\Support\Collection;

/**
 * Mencocokkan nama dari Excel (penjualan/simpanan) ke data pegawai.
 * Urutan: alias tersimpan → nama persis (setelah dinormalisasi) → saran nama mirip.
 */
class NameMatcher
{
    /** @var array<string, int> */
    private array $exact = [];

    /** @var Collection<int, array{id: int, name: string, nip: ?string, key: string}> */
    private Collection $employees;

    public function __construct()
    {
        $this->employees = Customer::employees()
            ->orderBy('name')
            ->get(['id', 'name', 'nip'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'nip' => $c->nip, 'key' => self::normalize($c->name)]);

        foreach ($this->employees as $employee) {
            $this->exact[$employee['key']] ??= $employee['id'];
        }
        foreach (CustomerAlias::query()->get(['customer_id', 'alias']) as $alias) {
            $this->exact[self::normalize($alias->alias)] = $alias->customer_id;
        }
    }

    public static function normalize(?string $name): string
    {
        $name = strtoupper((string) $name);
        $name = preg_replace('/[^A-Z ]/', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    public function match(?string $name): ?int
    {
        return $this->exact[self::normalize($name)] ?? null;
    }

    /**
     * @return array<int, array{id: int, name: string, nip: ?string, score: int}>
     */
    public function suggestions(?string $name, int $limit = 3, int $minScore = 60): array
    {
        $key = self::normalize($name);
        if ($key === '') {
            return [];
        }

        return $this->employees
            ->map(function (array $employee) use ($key) {
                similar_text($key, $employee['key'], $percent);
                // "AGUS Y" ⊂ "AGUS YOGA PRATAMA": nama singkatan di awal nama lengkap
                if (str_starts_with($employee['key'], $key) || str_starts_with($key, $employee['key'])) {
                    $percent = max($percent, 80);
                }

                return ['id' => $employee['id'], 'name' => $employee['name'], 'nip' => $employee['nip'], 'score' => (int) round($percent)];
            })
            ->filter(fn ($s) => $s['score'] >= $minScore)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Simpan alias agar import berikutnya otomatis cocok.
     */
    public static function remember(string $rawName, int $customerId): void
    {
        $key = self::normalize($rawName);
        if ($key === '') {
            return;
        }
        $customer = Customer::find($customerId, ['id', 'name']);
        if (! $customer || self::normalize($customer->name) === $key) {
            return;
        }

        CustomerAlias::updateOrCreate(['alias' => $key], ['customer_id' => $customerId]);
    }

    /**
     * @return Collection<int, array{id: int, name: string, nip: ?string, key: string}>
     */
    public function employees(): Collection
    {
        return $this->employees;
    }
}
