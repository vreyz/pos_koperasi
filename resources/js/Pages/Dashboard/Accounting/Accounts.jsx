import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconListTree, IconPlus, IconPencil, IconDeviceFloppy, IconLock } from "@tabler/icons-react";
import { Badge, Field, PageHeader, Panel, btn, inputClass, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { PercentInput } from "@/Components/Cooperative/inputs";
import { AccountingTabs } from "@/Components/Accounting/shared";
import { useAuthorization } from "@/Utils/authorization";

const PNL = ["pendapatan", "beban_pokok", "beban_usaha", "pajak"];
const empty = { code: "", name: "", group: "aset", normal_balance: "D", report_line: "", division: "", is_cash: false, is_active: true, description: "" };

function AccountForm({ initial, groups, reportLines, pnlDivisions, onDone }) {
    const { data, setData, post, put, processing, errors } = useForm({ ...empty, ...initial, division: initial?.division || "" });
    const isPnl = PNL.includes(data.group);
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };
        initial?.id ? put(route("accounting.accounts.update", initial.id), opts) : post(route("accounting.accounts.store"), opts);
    };
    return (
        <form onSubmit={submit} className="grid gap-3 border-b border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-800/30">
            <Field label="Kode" error={errors.code}><input className={inputClass} value={data.code} onChange={(e) => setData("code", e.target.value)} required disabled={initial?.lines_count > 0} /></Field>
            <Field label="Nama akun" error={errors.name}><input className={inputClass} value={data.name} onChange={(e) => setData("name", e.target.value)} required /></Field>
            <Field label="Kelompok">
                <select className={inputClass} value={data.group} onChange={(e) => setData("group", e.target.value)}>
                    {Object.entries(groups).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
            </Field>
            <Field label="Saldo normal">
                <select className={inputClass} value={data.normal_balance} onChange={(e) => setData("normal_balance", e.target.value)}>
                    <option value="D">Debit</option><option value="K">Kredit</option>
                </select>
            </Field>
            <Field label="Pos di laporan" error={errors.report_line}>
                <input className={inputClass} list="report-lines" value={data.report_line} onChange={(e) => setData("report_line", e.target.value)} required />
                <datalist id="report-lines">{reportLines.map((l) => <option key={l} value={l} />)}</datalist>
            </Field>
            {isPnl ? (
                <Field label="Divisi (PHU)">
                    <select className={inputClass} value={data.division} onChange={(e) => setData("division", e.target.value)}>
                        {Object.entries(pnlDivisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </Field>
            ) : (
                <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" checked={data.is_cash} onChange={(e) => setData("is_cash", e.target.checked)} /> Akun kas / bank</label>
            )}
            <Field label="Keterangan"><input className={inputClass} value={data.description || ""} onChange={(e) => setData("description", e.target.value)} /></Field>
            <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" checked={data.is_active} onChange={(e) => setData("is_active", e.target.checked)} /> Aktif</label>
            <div className="flex items-end gap-2">
                <button className={btn.primary} disabled={processing}><IconDeviceFloppy size={16} /> Simpan</button>
                <button type="button" className={btn.secondary} onClick={onDone}>Batal</button>
            </div>
        </form>
    );
}

export default function Accounts({ accounts, groups, reportLines, pnlDivisions, kantinShare, autoFrom, warehouses, kantinWarehouses }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const manage = can("accounting-manage");
    const [editing, setEditing] = useState(null);
    const [share, setShare] = useState(kantinShare);
    const [from, setFrom] = useState(autoFrom);
    const [kantin, setKantin] = useState(kantinWarehouses || []);
    const saveSettings = () => router.put(route("accounting.settings"), { kantin_share_percent: share, auto_from: from, kantin_warehouses: kantin }, { preserveScroll: true });
    const [year, setYear] = useState(new Date().getFullYear() - 1);

    const closeYear = () => {
        if (!window.confirm(`Tutup buku tahun ${year}? Semua pendapatan & beban ${year} akan dipindah ke Dana Cadangan Tahun Berjalan.`)) return;
        router.post(route("accounting.close-year"), { year }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="COA & Pengaturan" />
            <PageHeader
                icon={IconListTree}
                title="Bagan Akun (COA) & Pengaturan"
                actions={manage && <button type="button" className={btn.primary} onClick={() => setEditing({})}><IconPlus size={16} /> Akun baru</button>}
            />
            <AccountingTabs />
            {manage && (
                <div className="mb-4 grid gap-4 md:grid-cols-2">
                    <Panel title="Kantin & jurnal otomatis" className="md:row-span-2">
                        <div className="space-y-3 p-4">
                            <Field label="Persen bagi hasil koperasi dari penjualan kantin" hint="Sisanya menjadi hutang ke penjaga kantin.">
                                <PercentInput value={share} onChange={setShare} className="w-40" />
                            </Field>
                            <div>
                                <span className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Gudang/outlet kantin</span>
                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                    {warehouses.map((w) => (
                                        <label key={w.id} className="flex items-center gap-1.5 text-sm">
                                            <input type="checkbox" checked={kantin.includes(w.id)} onChange={(e) => setKantin(e.target.checked ? [...kantin, w.id] : kantin.filter((x) => x !== w.id))} />
                                            {w.name}
                                        </label>
                                    ))}
                                </div>
                                <span className="mt-1 block text-xs text-slate-500">Penjualan kasir di gudang ini dijurnal sebagai uang muka kantin lalu dibagi hasil.</span>
                            </div>
                            <Field label="Jurnal otomatis mulai tanggal" hint="Mulai tanggal ini kasir, pembiayaan, potongan gaji, simpanan, retur, opname, dan SHU dijurnal sendiri. Sebelumnya dicatat lewat Catat Kejadian.">
                                <input type="date" className={`${inputClass} w-44`} value={from} onChange={(e) => setFrom(e.target.value)} />
                            </Field>
                            <button type="button" className={btn.primary} onClick={saveSettings}>Simpan pengaturan</button>
                        </div>
                    </Panel>
                    <Panel title="Tutup buku tahunan">
                        <div className="flex items-end gap-2 p-4">
                            <Field label="Tahun" hint="SHU masuk Dana Cadangan Tahun Berjalan; pembagian SHU & koreksi ditutup ke Dana Cadangan Tahun Lalu.">
                                <input type="number" className={`${inputClass} w-28`} value={year} onChange={(e) => setYear(e.target.value)} />
                            </Field>
                            <button type="button" className={btn.secondary} onClick={closeYear}><IconLock size={16} /> Tutup buku</button>
                        </div>
                    </Panel>
                </div>
            )}
            <Panel>
                {editing && <AccountForm initial={editing} groups={groups} reportLines={reportLines} pnlDivisions={pnlDivisions} onDone={() => setEditing(null)} />}
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-800/50">
                            <tr>
                                <th className={th}>Kode</th><th className={th}>Nama akun</th><th className={th}>Saldo normal</th><th className={th}>Pos di laporan</th>
                                <th className={th}>Divisi</th><th className={th}>Keterangan</th><th className={th} />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {Object.entries(groups).map(([g, label]) => (
                                <React.Fragment key={g}>
                                    <tr className="bg-slate-50/70 dark:bg-slate-800/30"><td colSpan={7} className="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</td></tr>
                                    {accounts.filter((a) => a.group === g).map((a) => (
                                        <tr key={a.id} className={a.is_active ? "" : "opacity-50"}>
                                            <td className={`${td} font-mono text-xs`}>{a.code}</td>
                                            <td className="px-3 py-1.5 text-sm">
                                                {a.name} {a.is_cash && <Badge color="blue">kas</Badge>} {!a.is_active && <Badge>tidak aktif</Badge>}
                                            </td>
                                            <td className={td}>{a.normal_balance === "D" ? "Debit" : "Kredit"}</td>
                                            <td className={td}>{a.report_line}</td>
                                            <td className={td}>{a.division ? pnlDivisions[a.division] : ""}</td>
                                            <td className="px-3 py-1.5 text-xs text-slate-500">{a.description}</td>
                                            <td className={td}>
                                                {manage && <button type="button" title="Ubah" className="text-slate-400 hover:text-primary-600" onClick={() => setEditing(a)}><IconPencil size={16} /></button>}
                                            </td>
                                        </tr>
                                    ))}
                                </React.Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}

Accounts.layout = (page) => <DashboardLayout children={page} />;
