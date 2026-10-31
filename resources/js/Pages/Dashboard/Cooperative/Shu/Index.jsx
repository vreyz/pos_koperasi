import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconCoins, IconPlus } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, PageHeader, Panel, btn, inputBase, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

export default function Index({ periods, suggestedYear }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [year, setYear] = useState(suggestedYear);
    const [error, setError] = useState(null);

    const create = (e) => {
        e.preventDefault();
        router.post(route("shu.store"), { year }, { onError: (err) => setError(err.year) });
    };

    return (
        <>
            <Head title="SHU" />
            <PageHeader
                icon={IconCoins}
                title="Sisa Hasil Usaha (SHU)"
                subtitle="SHU dihitung per tahun buku dan dibagikan di tahun berikutnya. Pilih tahun buku untuk mengatur dan menghitung."
            />

            {can("shu-manage") && (
                <Panel title="Buat perhitungan SHU" className="mb-6">
                    <form onSubmit={create} className="flex flex-wrap items-end gap-3 p-4">
                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Tahun buku</span>
                            <input type="number" className={inputBase + " w-40"} value={year} onChange={(e) => setYear(e.target.value)} />
                        </label>
                        <button type="submit" className={btn.primary}><IconPlus size={18} /> Buat SHU {year}</button>
                        <span className="text-sm text-slate-500">Dibagikan tahun {Number(year) + 1}. Pengaturan disalin dari tahun sebelumnya.</span>
                        {error && <p className="w-full text-sm text-danger-600">{error}</p>}
                    </form>
                </Panel>
            )}

            <Panel title="Daftar tahun buku">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th className={th}>Tahun buku</th>
                                <th className={th}>Dibagikan</th>
                                <th className={th + " text-right"}>SHU dibagi</th>
                                <th className={th}>Sumber penjualan</th>
                                <th className={th + " text-right"}>Penerima</th>
                                <th className={th + " text-right"}>Total dibulatkan</th>
                                <th className={th}>Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {periods.map((p) => (
                                <tr key={p.id}>
                                    <td className={td}><Link href={route("shu.show", p.id)} className="font-semibold text-primary-600 hover:underline">SHU {p.year}</Link></td>
                                    <td className={td}>{p.year + 1}</td>
                                    <td className={td + " text-right"}>{rupiah(p.total_shu)}</td>
                                    <td className={td}>{p.sales_source === "excel" ? "Excel" : "POS"}</td>
                                    <td className={td + " text-right"}>{p.allocations_count}</td>
                                    <td className={td + " text-right"}>{rupiah(p.allocations_sum_total_rounded)}</td>
                                    <td className={td}>{p.status === "final" ? <Badge color="green">Final</Badge> : <Badge color="amber">Draft</Badge>}</td>
                                </tr>
                            ))}
                            {periods.length === 0 && <tr><td colSpan={7} className="p-10 text-center text-slate-500">Belum ada perhitungan SHU.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
