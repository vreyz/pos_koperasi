import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconFlag, IconDeviceFloppy } from "@tabler/icons-react";
import { Badge, Field, PageHeader, Panel, btn, inputClass, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { NumberInput } from "@/Components/Cooperative/inputs";
import { AccountingTabs, idr, fmtDate } from "@/Components/Accounting/shared";
import { useAuthorization } from "@/Utils/authorization";

/** Isian ditulis menurut saldo normal (positif = normal); disimpan sebagai debit positif. */
export default function Opening({ accounts, entry, values, groups }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const manage = can("accounting-manage");
    const toInput = (a) => (values[a.code] ? (a.normal_balance === "K" ? -values[a.code] : values[a.code]) : "");
    const [date, setDate] = useState(entry?.date || `${new Date().getFullYear() - 1}-12-31`);
    const [input, setInput] = useState(Object.fromEntries(accounts.map((a) => [a.code, toInput(a)])));
    const signed = (a) => (a.normal_balance === "K" ? -Number(input[a.code] || 0) : Number(input[a.code] || 0));
    const debit = accounts.reduce((s, a) => s + Math.max(signed(a), 0), 0);
    const credit = accounts.reduce((s, a) => s + Math.max(-signed(a), 0), 0);
    const diff = debit - credit;

    const save = () => {
        if (entry && !window.confirm(`Ganti saldo awal ${entry.number}?`)) return;
        router.post(route("accounting.opening.store"), { date, values: Object.fromEntries(accounts.map((a) => [a.code, signed(a)])) }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Saldo Awal" />
            <PageHeader icon={IconFlag} title="Saldo Awal" subtitle="Saldo neraca saat mulai memakai buku besar di aplikasi (mis. dari LK 31 Desember 2025)." />
            <AccountingTabs />
            <Panel>
                <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
                    <Field label="Per tanggal"><input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} disabled={!manage} /></Field>
                    {entry && <span className="pb-2 text-sm text-slate-500">Tersimpan sebagai {entry.number} ({fmtDate(entry.date)})</span>}
                    <div className="ml-auto flex items-center gap-2 pb-1">
                        <Badge color={diff === 0 ? "green" : "red"}>{diff === 0 ? "Debit = Kredit" : `Selisih ${idr(diff)}`}</Badge>
                        {manage && <button type="button" className={btn.primary} disabled={diff !== 0 || debit === 0} onClick={save}><IconDeviceFloppy size={16} /> Simpan saldo awal</button>}
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-800/50">
                            <tr><th className={th}>Kode</th><th className={th}>Akun</th><th className={th}>Pos</th><th className={`${th} text-right`}>Saldo (sesuai saldo normal)</th></tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {["aset", "kewajiban", "ekuitas"].map((g) => (
                                <React.Fragment key={g}>
                                    <tr className="bg-slate-50/70 dark:bg-slate-800/30"><td colSpan={4} className="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{groups[g]}</td></tr>
                                    {accounts.filter((a) => a.group === g).map((a) => (
                                        <tr key={a.code}>
                                            <td className={`${td} font-mono text-xs`}>{a.code}</td>
                                            <td className="px-3 py-1 text-sm">{a.name}{a.normal_balance === "K" && g === "aset" ? <span className="text-xs text-slate-500"> (kontra)</span> : null}</td>
                                            <td className="px-3 py-1 text-xs text-slate-500">{a.report_line}</td>
                                            <td className="w-56 px-3 py-1">
                                                <NumberInput prefix="Rp" value={input[a.code]} onChange={(v) => setInput((o) => ({ ...o, [a.code]: v }))} disabled={!manage} />
                                            </td>
                                        </tr>
                                    ))}
                                </React.Fragment>
                            ))}
                            <tr className="font-semibold">
                                <td className={td} colSpan={3}>Jumlah debit / kredit</td>
                                <td className={`${td} text-right font-mono`}>{idr(debit)} / {idr(credit)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}

Opening.layout = (page) => <DashboardLayout children={page} />;
