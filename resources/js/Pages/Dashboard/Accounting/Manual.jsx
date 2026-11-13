import React, { useMemo } from "react";
import { Head, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconPencilPlus, IconPlus, IconTrash, IconDeviceFloppy } from "@tabler/icons-react";
import { Field, PageHeader, Panel, btn, inputClass, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";
import { AccountingTabs, AccountSelect, idr } from "@/Components/Accounting/shared";

const blank = () => ({ account: "", debit: "", credit: "", customer_id: "", memo: "" });
const pad = (n) => String(n).padStart(2, "0");
const today = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };

export default function Manual({ accounts, employees, divisions }) {
    useFlashToasts();
    const people = useMemo(() => employeeOptions(employees), [employees]);
    const { data, setData, post, processing, errors } = useForm({ date: today(), description: "", division: "umum", lines: [blank(), blank()] });
    const setLine = (i, k, v) => setData("lines", data.lines.map((l, j) => (j === i ? { ...l, [k]: v, ...(k === "debit" && v ? { credit: "" } : {}), ...(k === "credit" && v ? { debit: "" } : {}) } : l)));
    const debit = data.lines.reduce((s, l) => s + Number(l.debit || 0), 0);
    const credit = data.lines.reduce((s, l) => s + Number(l.credit || 0), 0);
    const balanced = debit > 0 && debit === credit;

    return (
        <>
            <Head title="Jurnal Umum" />
            <PageHeader icon={IconPencilPlus} title="Jurnal Umum" subtitle="Untuk koreksi atau kejadian yang belum ada di daftar Catat Kejadian. Debit harus sama dengan kredit." />
            <AccountingTabs />
            <form onSubmit={(e) => { e.preventDefault(); post(route("accounting.manual.store")); }}>
                <Panel>
                    <div className="grid gap-3 p-4 sm:grid-cols-[10rem_1fr_14rem]">
                        <Field label="Tanggal" error={errors.date}><input type="date" className={inputClass} value={data.date} onChange={(e) => setData("date", e.target.value)} required /></Field>
                        <Field label="Uraian" error={errors.description}><input className={inputClass} value={data.description} onChange={(e) => setData("description", e.target.value)} required maxLength={200} /></Field>
                        <Field label="Divisi">
                            <select className={inputClass} value={data.division} onChange={(e) => setData("division", e.target.value)}>
                                {Object.entries(divisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </Field>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[56rem]">
                            <thead>
                                <tr className="text-xs text-slate-500">
                                    <th className="px-3 py-1 text-left font-medium">Akun</th>
                                    <th className="w-44 px-2 py-1 text-right font-medium">Debit</th>
                                    <th className="w-44 px-2 py-1 text-right font-medium">Kredit</th>
                                    <th className="w-56 px-2 py-1 text-left font-medium">Pegawai (opsional)</th>
                                    <th className="w-48 px-2 py-1 text-left font-medium">Catatan</th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((l, i) => (
                                    <tr key={i} className="align-top">
                                        <td className="px-3 py-1"><AccountSelect accounts={accounts} filter={(a) => a.is_active} value={l.account} onChange={(v) => setLine(i, "account", v)} /></td>
                                        <td className="px-2 py-1"><MoneyInput value={l.debit} onChange={(v) => setLine(i, "debit", v)} /></td>
                                        <td className="px-2 py-1"><MoneyInput value={l.credit} onChange={(v) => setLine(i, "credit", v)} /></td>
                                        <td className="px-2 py-1"><SearchSelect options={people} value={l.customer_id} onChange={(v) => setLine(i, "customer_id", v)} placeholder="—" clearable /></td>
                                        <td className="px-2 py-1"><input className={inputClass} value={l.memo} onChange={(e) => setLine(i, "memo", e.target.value)} maxLength={150} /></td>
                                        <td className="px-2 py-2">
                                            {data.lines.length > 2 && (
                                                <button type="button" title="Hapus baris" className="text-slate-400 hover:text-danger-600" onClick={() => setData("lines", data.lines.filter((_, j) => j !== i))}><IconTrash size={16} /></button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                <tr className="border-t border-slate-200 text-sm font-semibold dark:border-slate-700">
                                    <td className="px-3 py-2">
                                        <button type="button" className={btn.secondary} onClick={() => setData("lines", [...data.lines, blank()])}><IconPlus size={14} /> Tambah baris</button>
                                    </td>
                                    <td className="px-4 py-2 text-right font-mono">{idr(debit)}</td>
                                    <td className="px-4 py-2 text-right font-mono">{idr(credit)}</td>
                                    <td colSpan={3} className={`px-2 py-2 text-sm ${balanced ? "text-success-600" : "text-danger-600"}`}>
                                        {balanced ? "Seimbang" : `Selisih ${idr(debit - credit)}`}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    {errors.lines && <p className="px-4 pb-2 text-sm text-danger-600">{errors.lines}</p>}
                    <div className="flex justify-end border-t border-slate-200 p-3 dark:border-slate-800">
                        <button className={btn.primary} disabled={!balanced || processing}><IconDeviceFloppy size={16} /> Simpan jurnal</button>
                    </div>
                </Panel>
            </form>
        </>
    );
}

Manual.layout = (page) => <DashboardLayout children={page} />;
