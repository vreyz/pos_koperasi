import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconToolsKitchen2, IconPrinter } from "@tabler/icons-react";
import { Badge, MONTHS, PageHeader, Panel, Stat, btn, inputBase, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { AccountingTabs, idr, fmtDate } from "@/Components/Accounting/shared";

const pct = (a, b) => (b ? ((100 * a) / b).toLocaleString("id-ID", { maximumFractionDigits: 2 }) + "%" : "-");

/**
 * Rekap kantin: penjualan (uang muka), bagi hasil koperasi, bagian penjaga, transfer ke penjaga, sisa hutang,
 * dan porsi aktual per bulan. Persen bagi hasil bisa diubah di sini.
 */
export default function Kantin({ year, opening, months, payments, kantinShare, kantinWarehouses, autoFrom }) {
    useFlashToasts();
    const [share, setShare] = useState(kantinShare);
    const tot = months.reduce((s, m) => ({ sales: s.sales + m.sales, share: s.share + m.share, keeper: s.keeper + m.keeper, paid: s.paid + m.paid }), { sales: 0, share: 0, keeper: 0, paid: 0 });
    const last = months.length ? months[months.length - 1].balance : opening;
    const goYear = (y) => router.get(route("accounting.kantin"), { year: y }, { preserveScroll: true });
    const saveShare = () => router.put(route("accounting.settings"), { kantin_share_percent: share }, { preserveScroll: true });

    return (
        <>
            <Head title="Rekap Kantin" />
            <PageHeader
                icon={IconToolsKitchen2}
                title={`Rekap Kantin ${year}`}
                subtitle="Penjualan kantin dicatat sebagai uang muka, lalu dibagi: bagi hasil koperasi dan bagian penjaga kantin (hutang). Bagian penjaga ditransfer berkala."
                actions={<button type="button" className={btn.secondary} onClick={() => window.print()}><IconPrinter size={16} /> Cetak</button>}
            />
            <AccountingTabs />
            <div className="mb-3 flex flex-wrap items-center gap-2 print:hidden">
                <button type="button" className={btn.secondary} onClick={() => goYear(year - 1)}>‹ {year - 1}</button>
                <button type="button" className={btn.secondary} onClick={() => goYear(year + 1)}>{year + 1} ›</button>
                <Link className={btn.primary} href={route("accounting.record", { division: "toko", event: "TK-15" })}>Catat penjualan kantin (rekap)</Link>
                <Link className={btn.primary} href={route("accounting.record", { division: "toko", event: "TK-16" })}>Catat transfer ke penjaga</Link>
            </div>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <Stat label="Penjualan kantin" value={idr(tot.sales)} />
                <Stat label="Bagi hasil koperasi" value={idr(tot.share)} helper={`Porsi aktual ${pct(tot.share, tot.sales)}`} />
                <Stat label="Bagian penjaga" value={idr(tot.keeper)} />
                <Stat label="Sudah ditransfer" value={idr(tot.paid)} />
                <Stat label="Sisa hutang ke penjaga" value={idr(last)} helper={opening ? `Saldo awal tahun ${idr(opening)}` : undefined} />
                <Panel title="Persen bagi hasil" className="print:hidden">
                    <div className="flex items-center gap-2 p-3">
                        <input type="number" min="0" max="100" step="0.01" className={`${inputBase} w-24`} value={share} onChange={(e) => setShare(e.target.value)} aria-label="Persen bagi hasil koperasi" />
                        <span className="text-sm">%</span>
                        <button type="button" className={btn.secondary} onClick={saveShare} disabled={String(share) === String(kantinShare)}>Simpan</button>
                    </div>
                    <p className="px-3 pb-3 text-xs text-slate-500">Berlaku untuk jurnal kantin berikutnya. Jurnal lama tidak berubah.</p>
                </Panel>
            </div>

            <Panel className="mb-4 light-surface print:border-0" title="Per bulan">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-800/50">
                            <tr>
                                <th className={th}>Bulan</th>
                                <th className={`${th} text-right`}>Penjualan</th>
                                <th className={`${th} text-right`}>Bagi hasil</th>
                                <th className={`${th} text-right`}>Porsi aktual</th>
                                <th className={`${th} text-right`}>Bagian penjaga</th>
                                <th className={`${th} text-right`}>Ditransfer</th>
                                <th className={`${th} text-right`}>Sisa hutang</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {months.map((m) => {
                                const empty = !m.sales && !m.share && !m.keeper && !m.paid;
                                return (
                                    <tr key={m.month} className={empty ? "text-slate-400" : ""}>
                                        <td className={td}>{MONTHS[m.month - 1]} {year}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.sales)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.share)}</td>
                                        <td className={`${td} text-right font-mono`}>{pct(m.share, m.sales)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.keeper)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.paid)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.balance)}</td>
                                    </tr>
                                );
                            })}
                            <tr className="font-semibold">
                                <td className={td}>Jumlah</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.sales)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.share)}</td>
                                <td className={`${td} text-right font-mono`}>{pct(tot.share, tot.sales)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.keeper)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.paid)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(last)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p className="px-3 py-2 text-xs text-slate-500">
                    Porsi aktual = bagi hasil ÷ penjualan. Sumber: jurnal kantin (TK-15) dan transfer ke penjaga (TK-16).
                </p>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Transfer ke penjaga kantin">
                    {payments.length ? (
                        <table className="w-full">
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.map((p) => (
                                    <tr key={p.id}>
                                        <td className={td}>{fmtDate(p.date)}</td>
                                        <td className={`${td} font-mono text-xs`}>{p.number}</td>
                                        <td className="px-3 py-1.5 text-sm text-slate-600 dark:text-slate-300">{p.description}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(p.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="p-3 text-sm text-slate-500">Belum ada transfer di tahun ini.</p>
                    )}
                </Panel>
                <Panel title="Cara kerja">
                    <ul className="list-disc space-y-1 p-3 pl-7 text-sm text-slate-600 dark:text-slate-300">
                        <li>
                            Outlet kantin:{" "}
                            {kantinWarehouses.length ? kantinWarehouses.map((n) => <Badge key={n} color="green">{n}</Badge>) : <Badge color="amber">belum dipilih</Badge>}{" "}
                            <Link className="text-primary-600 hover:underline" href={route("accounting.accounts")}>ubah</Link>
                        </li>
                        <li>Mulai {fmtDate(autoFrom)}, penjualan kasir di outlet kantin dijurnal otomatis tiap hari (uang muka, lalu bagi hasil {Number(kantinShare).toLocaleString("id-ID")}% dan hutang penjaga).</li>
                        <li>Sebelum tanggal itu, atau bila kantin mencatat di Excel, isi rekap mingguan lewat tombol "Catat penjualan kantin (rekap)".</li>
                        <li>Saat bagian penjaga ditransfer (dari BSI atau kas), catat dengan tombol "Catat transfer ke penjaga" supaya sisa hutang berkurang.</li>
                    </ul>
                </Panel>
            </div>
        </>
    );
}

Kantin.layout = (page) => <DashboardLayout children={page} />;
