import React, { useMemo } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconBooks } from "@tabler/icons-react";
import { PageHeader, Panel, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";
import { AccountingTabs, AccountSelect, PeriodFilter, idr, fmtDate } from "@/Components/Accounting/shared";

/** Saldo ditampilkan sesuai saldo normal akun (positif = normal). */
const normal = (v, nb) => (nb === "K" ? -v : v);

export default function Ledger({ accounts, employees, filters, ledger }) {
    useFlashToasts();
    const people = useMemo(() => employeeOptions(employees), [employees]);
    const go = (params) => router.get(route("accounting.ledger"), { ...filters, ...params }, { preserveState: true });
    const nb = ledger?.account.normal_balance;

    return (
        <>
            <Head title="Buku Besar" />
            <PageHeader icon={IconBooks} title="Buku Besar" subtitle="Mutasi satu akun dengan saldo berjalan. Bisa dipersempit per pegawai." />
            <AccountingTabs />
            <div className="mb-2 grid gap-2 sm:grid-cols-2 lg:max-w-4xl">
                <AccountSelect accounts={accounts} value={filters.account} onChange={(v) => v && go({ account: v, customer: undefined })} />
                <SearchSelect options={people} value={filters.customer || ""} onChange={(v) => go({ customer: v || undefined })} placeholder="Semua pegawai" clearable />
            </div>
            <PeriodFilter routeName="accounting.ledger" filters={filters} />
            {ledger && (
                <div className="grid gap-4 xl:grid-cols-[1fr_22rem]">
                    <Panel title={`${ledger.account.code} ${ledger.account.name}`} actions={<span className="text-xs text-slate-500">Saldo normal: {nb === "D" ? "Debit" : "Kredit"}</span>}>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-slate-50 dark:bg-slate-800/50">
                                    <tr>
                                        <th className={th}>Tanggal</th><th className={th}>Nomor</th><th className={th}>Uraian</th>
                                        <th className={`${th} text-right`}>Debit</th><th className={`${th} text-right`}>Kredit</th><th className={`${th} text-right`}>Saldo</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr className="bg-slate-50/50 dark:bg-slate-800/20">
                                        <td className={td} colSpan={5}>Saldo awal {fmtDate(filters.from)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(normal(ledger.opening, nb))}</td>
                                    </tr>
                                    {ledger.rows.map((r, i) => (
                                        <tr key={i}>
                                            <td className={td}>{fmtDate(r.date)}</td>
                                            <td className={`${td} font-mono text-xs`}>{r.number}</td>
                                            <td className="px-3 py-1.5 text-sm text-slate-700 dark:text-slate-300">
                                                {r.description}
                                                {(r.customer || r.memo) && <span className="block text-xs text-slate-500">{[r.customer, r.memo].filter(Boolean).join(" · ")}</span>}
                                            </td>
                                            <td className={`${td} text-right font-mono`}>{r.debit ? idr(r.debit) : ""}</td>
                                            <td className={`${td} text-right font-mono`}>{r.credit ? idr(r.credit) : ""}</td>
                                            <td className={`${td} text-right font-mono`}>{idr(normal(r.balance, nb))}</td>
                                        </tr>
                                    ))}
                                    <tr className="font-semibold">
                                        <td className={td} colSpan={3}>Saldo akhir {fmtDate(filters.to)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(ledger.rows.reduce((s, r) => s + r.debit, 0))}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(ledger.rows.reduce((s, r) => s + r.credit, 0))}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(normal(ledger.closing, nb))}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Panel>
                    {ledger.per_customer.length > 0 && (
                        <Panel title="Saldo per pegawai">
                            <ul className="max-h-[70vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                                {ledger.per_customer.map((p) => (
                                    <li key={p.customer_id}>
                                        <button type="button" onClick={() => go({ customer: p.customer_id })} className="flex w-full justify-between px-3 py-1.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <span>{p.name}</span><span className="font-mono">{idr(normal(p.balance, nb))}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>
            )}
        </>
    );
}

Ledger.layout = (page) => <DashboardLayout children={page} />;
