import React from "react";
import { Head, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconScale, IconPrinter } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, btn, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { AccountingTabs, PeriodFilter, idr, fmtDate } from "@/Components/Accounting/shared";

export default function TrialBalance({ tb, filters, groups }) {
    useFlashToasts();
    const t = tb.totals;
    const balanced = t.closing_debit === t.closing_credit;
    const rows = tb.rows.filter((r) => r.opening || r.debit || r.credit || r.closing);

    return (
        <>
            <Head title="Neraca Saldo" />
            <PageHeader
                icon={IconScale}
                title="Neraca Saldo"
                subtitle={`${fmtDate(filters.from)} s.d. ${fmtDate(filters.to)}. Saldo awal + mutasi debit - mutasi kredit = saldo akhir.`}
                actions={<button type="button" className={btn.secondary} onClick={() => window.print()}><IconPrinter size={16} /> Cetak</button>}
            />
            <AccountingTabs />
            <PeriodFilter routeName="accounting.trial-balance" filters={filters}>
                <Badge color={balanced ? "green" : "red"}>{balanced ? "Debit = Kredit" : `Tidak seimbang: ${idr(t.closing_debit - t.closing_credit)}`}</Badge>
            </PeriodFilter>
            <Panel className="light-surface print:border-0">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-800/50">
                            <tr>
                                <th className={th}>Kode</th><th className={th}>Akun</th>
                                <th className={`${th} text-right`}>Saldo awal</th><th className={`${th} text-right`}>Mutasi debit</th><th className={`${th} text-right`}>Mutasi kredit</th>
                                <th className={`${th} text-right`}>Saldo akhir debit</th><th className={`${th} text-right`}>Saldo akhir kredit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {Object.entries(groups).map(([g, label]) => {
                                const list = rows.filter((r) => r.group === g);
                                if (!list.length) return null;
                                return (
                                    <React.Fragment key={g}>
                                        <tr className="bg-slate-50/70 dark:bg-slate-800/30"><td colSpan={7} className="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</td></tr>
                                        {list.map((r) => (
                                            <tr key={r.code}>
                                                <td className={`${td} font-mono text-xs`}>{r.code}</td>
                                                <td className="px-3 py-1.5 text-sm">
                                                    <Link className="hover:underline" href={route("accounting.ledger", { account: r.code, from: filters.from, to: filters.to })}>{r.name}</Link>
                                                </td>
                                                <td className={`${td} text-right font-mono`}>{idr(r.opening)}</td>
                                                <td className={`${td} text-right font-mono`}>{r.debit ? idr(r.debit) : ""}</td>
                                                <td className={`${td} text-right font-mono`}>{r.credit ? idr(r.credit) : ""}</td>
                                                <td className={`${td} text-right font-mono`}>{r.closing > 0 ? idr(r.closing) : ""}</td>
                                                <td className={`${td} text-right font-mono`}>{r.closing < 0 ? idr(-r.closing) : ""}</td>
                                            </tr>
                                        ))}
                                    </React.Fragment>
                                );
                            })}
                            <tr className="font-semibold">
                                <td className={td} colSpan={2}>Jumlah</td>
                                <td className={`${td} text-right font-mono`}>{idr(t.opening)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(t.debit)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(t.credit)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(t.closing_debit)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(t.closing_credit)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p className="px-3 py-2 text-xs text-slate-500">Saldo awal positif = debit, dalam kurung = kredit. Klik nama akun untuk membuka buku besar.</p>
            </Panel>
        </>
    );
}

TrialBalance.layout = (page) => <DashboardLayout children={page} />;
