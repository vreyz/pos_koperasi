import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconCalculator, IconCoins, IconDownload, IconLock, IconLockOpen, IconTrash, IconUpload, IconX } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputBase, inputClass, percent, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

const SETTING_KEYS = ["total_shu", "base_per_person", "portion_savings", "portion_purchases", "portion_loans", "savings_types", "savings_period", "sales_source", "rounding", "excluded_customer_ids"];

export default function Show({ period, allocations, rules, stats, unmatched, employees, typeLabels }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const locked = period.status === "final";
    const editable = can("shu-manage") && !locked;
    const [form, setForm] = useState(() => Object.fromEntries(SETTING_KEYS.map((k) => [k, period[k] ?? (k.endsWith("ids") ? [] : "")])));
    const [errors, setErrors] = useState({});
    const [search, setSearch] = useState("");
    const [excludeId, setExcludeId] = useState("");
    const salesUpload = useForm({ file: null });
    const [mapChoice, setMapChoice] = useState({});

    const portionTotal = Number(form.portion_savings || 0) + Number(form.portion_purchases || 0) + Number(form.portion_loans || 0);
    const remainder = Number(form.total_shu || 0) - stats.payers * Number(form.base_per_person || 0);
    const employeeById = useMemo(() => Object.fromEntries(employees.map((e) => [e.id, e])), [employees]);
    const dirty = SETTING_KEYS.some((k) => JSON.stringify(form[k]) !== JSON.stringify(period[k] ?? (k.endsWith("ids") ? [] : "")));

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return q ? allocations.filter((a) => `${a.customer?.name} ${a.customer?.nip}`.toLowerCase().includes(q)) : allocations;
    }, [allocations, search]);

    const save = (e) => {
        e.preventDefault();
        router.put(route("shu.update", period.id), form, { preserveScroll: true, onError: setErrors, onSuccess: () => setErrors({}) });
    };
    const post = (name, confirmText) => () => {
        if (confirmText && !confirm(confirmText)) return;
        router.post(route(name, period.id), {}, { preserveScroll: true });
    };
    const toggleType = (type) =>
        setForm({ ...form, savings_types: form.savings_types.includes(type) ? form.savings_types.filter((t) => t !== type) : [...form.savings_types, type] });

    return (
        <>
            <Head title={`SHU ${period.year}`} />
            <PageHeader
                icon={IconCoins}
                title={<>SHU Tahun Buku {period.year} {locked ? <Badge color="green">Final</Badge> : <Badge color="amber">Draft</Badge>}</>}
                subtitle={`Dibagikan pada tahun ${period.year + 1}.${period.calculated_at ? " Terakhir dihitung " + new Date(period.calculated_at).toLocaleString("id-ID") + "." : " Belum dihitung."}`}
                actions={
                    <>
                        <Link href={route("shu.index")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>
                        {allocations.length > 0 && <a href={route("shu.export", period.id)} className={btn.secondary}><IconDownload size={18} /> Export Excel</a>}
                        {editable && <button onClick={post("shu.calculate")} disabled={dirty} title={dirty ? "Simpan pengaturan dulu" : ""} className={btn.primary}><IconCalculator size={18} /> Hitung SHU</button>}
                        {can("shu-finalize") && !locked && period.calculated_at && <button onClick={post("shu.finalize", "Kunci SHU ini? Setelah final, pengaturan & hasil tidak bisa diubah sampai dibuka lagi.")} className={btn.secondary}><IconLock size={18} /> Kunci (final)</button>}
                        {can("shu-finalize") && locked && <button onClick={post("shu.reopen", "Buka kunci SHU ini?")} className={btn.secondary}><IconLockOpen size={18} /> Buka kunci</button>}
                        {editable && <button onClick={() => confirm(`Hapus perhitungan SHU ${period.year}?`) && router.delete(route("shu.destroy", period.id))} className={btn.secondary}><IconTrash size={18} /></button>}
                    </>
                }
            />

            <div className="mb-6 grid gap-6 lg:grid-cols-3">
                <Panel title="Pengaturan" className="lg:col-span-2">
                    <form onSubmit={save} className="space-y-4 p-4">
                        <fieldset disabled={!editable} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Jumlah SHU yang dibagi (Rp)" error={errors.total_shu}>
                                    <input type="number" min="0" className={inputClass} value={form.total_shu} onChange={(e) => setForm({ ...form, total_shu: e.target.value })} />
                                </Field>
                                <Field label="Base SHU per orang (Rp)" error={errors.base_per_person} hint={`${stats.payers} orang membayar simpanan di ${period.year} → total base ${rupiah(stats.payers * Number(form.base_per_person || 0))}`}>
                                    <input type="number" min="0" className={inputClass} value={form.base_per_person} onChange={(e) => setForm({ ...form, base_per_person: e.target.value })} />
                                </Field>
                            </div>
                            <div>
                                <p className="mb-1 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Porsi sisa SHU ({rupiah(Math.max(remainder, 0))}) — total{" "}
                                    <span className={Math.abs(portionTotal - 100) > 0.001 ? "text-danger-600" : "text-success-700"}>{portionTotal}%</span>
                                </p>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field label="Jasa simpanan (%)" error={errors.portion_savings}>
                                        <input type="number" step="0.01" min="0" max="100" className={inputClass} value={form.portion_savings} onChange={(e) => setForm({ ...form, portion_savings: e.target.value })} />
                                    </Field>
                                    <Field label="Jasa pembelian (%)" error={errors.portion_purchases}>
                                        <input type="number" step="0.01" min="0" max="100" className={inputClass} value={form.portion_purchases} onChange={(e) => setForm({ ...form, portion_purchases: e.target.value })} />
                                    </Field>
                                    <Field label="Jasa pinjaman (%)" error={errors.portion_loans}>
                                        <input type="number" step="0.01" min="0" max="100" className={inputClass} value={form.portion_loans} onChange={(e) => setForm({ ...form, portion_loans: e.target.value })} />
                                    </Field>
                                </div>
                                {remainder < 0 && <p className="mt-1 text-sm text-danger-600">Total base SHU melebihi SHU yang dibagi.</p>}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Simpanan yang dihitung" error={errors.savings_types}>
                                    <div className="flex flex-wrap gap-3 pt-1">
                                        {["pokok", "wajib", "sukarela"].map((t) => (
                                            <label key={t} className="flex items-center gap-1 text-sm">
                                                <input type="checkbox" checked={form.savings_types.includes(t)} onChange={() => toggleType(t)} /> {typeLabels[t]}
                                            </label>
                                        ))}
                                    </div>
                                </Field>
                                <Field label="Periode simpanan" error={errors.savings_period}>
                                    <select className={inputClass} value={form.savings_period} onChange={(e) => setForm({ ...form, savings_period: e.target.value })}>
                                        <option value="year">Selama tahun buku {period.year} saja</option>
                                        <option value="all_time">Sejak awal s.d. {period.year}</option>
                                    </select>
                                </Field>
                                <Field label="Sumber data penjualan" error={errors.sales_source}>
                                    <select className={inputClass} value={form.sales_source} onChange={(e) => setForm({ ...form, sales_source: e.target.value })}>
                                        <option value="pos">Penjualan aplikasi (POS)</option>
                                        <option value="excel">Import Excel</option>
                                    </select>
                                </Field>
                                <Field label="Pembulatan" error={errors.rounding}>
                                    <select className={inputClass} value={form.rounding} onChange={(e) => setForm({ ...form, rounding: e.target.value })}>
                                        <option value="round">Ke Rp 1.000 terdekat</option>
                                        <option value="ceil">Ke atas (kelipatan Rp 1.000)</option>
                                        <option value="floor">Ke bawah (kelipatan Rp 1.000)</option>
                                        <option value="none">Tidak dibulatkan</option>
                                    </select>
                                </Field>
                            </div>
                            <Field label="Dikecualikan dari SHU" error={errors.excluded_customer_ids}>
                                <div className="mb-2 flex flex-wrap gap-2">
                                    {form.excluded_customer_ids.map((id) => (
                                        <span key={id} className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">
                                            {employeeById[id]?.name || `#${id}`}
                                            {editable && <button type="button" onClick={() => setForm({ ...form, excluded_customer_ids: form.excluded_customer_ids.filter((x) => x !== id) })}><IconX size={14} /></button>}
                                        </span>
                                    ))}
                                    {form.excluded_customer_ids.length === 0 && <span className="text-xs text-slate-500">Tidak ada.</span>}
                                </div>
                                {editable && (
                                    <div className="flex gap-2">
                                        <select className={inputClass} value={excludeId} onChange={(e) => setExcludeId(e.target.value)}>
                                            <option value="">— pilih pegawai yang dikecualikan —</option>
                                            {employees.filter((e) => !form.excluded_customer_ids.includes(e.id)).map((e) => <option key={e.id} value={e.id}>{e.name}{e.nip ? ` (${e.nip})` : ""}</option>)}
                                        </select>
                                        <button type="button" className={btn.secondary} disabled={!excludeId} onClick={() => { setForm({ ...form, excluded_customer_ids: [...form.excluded_customer_ids, Number(excludeId)] }); setExcludeId(""); }}>Tambah</button>
                                    </div>
                                )}
                            </Field>
                        </fieldset>
                        {editable && (
                            <div className="flex items-center justify-end gap-3">
                                {dirty && <span className="text-sm text-warning-700">Ada perubahan yang belum disimpan.</span>}
                                <button type="submit" className={btn.primary} disabled={!dirty}>Simpan pengaturan</button>
                            </div>
                        )}
                    </form>
                </Panel>

                <Panel title="Aturan SHU yang berlaku">
                    <ol className="list-decimal space-y-2 p-4 pl-8 text-sm text-slate-700 dark:text-slate-300">
                        {rules.map((r, i) => <li key={i}>{r}</li>)}
                    </ol>
                    <p className="px-4 pb-4 text-xs text-slate-500">Aturan di atas mengikuti pengaturan yang sudah disimpan.</p>
                </Panel>
            </div>

            {period.sales_source === "excel" && (
                <Panel title={`Penjualan dari Excel (${period.year})`} className="mb-6">
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <Stat label="Baris tersimpan" value={stats.excel_rows.toLocaleString("id-ID")} helper="dikelompokkan per pembeli per tanggal" />
                        <Stat label="Total penjualan" value={rupiah(stats.excel_total)} />
                        <Stat label="Nama belum cocok" value={unmatched.length} helper="tidak ikut SHU sebelum dipetakan" />
                    </div>
                    {editable && (
                        <form onSubmit={(e) => { e.preventDefault(); salesUpload.post(route("shu.sales-import", period.id), { forceFormData: true, preserveScroll: true, onSuccess: () => salesUpload.reset() }); }} className="flex flex-col gap-3 px-4 pb-4 sm:flex-row sm:items-center">
                            <input type="file" accept=".xlsx,.xls,.csv" className={inputClass} onChange={(e) => salesUpload.setData("file", e.target.files[0])} />
                            <button type="submit" className={btn.primary} disabled={!salesUpload.data.file || salesUpload.processing}><IconUpload size={18} /> {salesUpload.processing ? "Mengimport..." : "Import penjualan"}</button>
                        </form>
                    )}
                    <p className="px-4 pb-4 text-xs text-slate-500">Kolom: TGL BELANJA, NAMA PEGAWAI (atau "Nama Final"), TOTAL HARGA. Import menimpa data Excel tahun {period.year} sebelumnya.</p>
                    {unmatched.length > 0 && (
                        <div className="overflow-x-auto border-t border-slate-200 dark:border-slate-800">
                            <table className="w-full">
                                <thead className="bg-slate-50 dark:bg-slate-950">
                                    <tr><th className={th}>Nama pembeli di Excel</th><th className={th + " text-right"}>Total</th><th className={th}>Saran</th><th className={th}>Petakan ke pegawai</th></tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {unmatched.map((u) => (
                                        <tr key={u.buyer_name} className="align-top">
                                            <td className={td + " font-medium"}>{u.buyer_name}</td>
                                            <td className={td + " text-right"}>{rupiah(u.total)}</td>
                                            <td className={td + " whitespace-normal text-xs"}>
                                                {u.suggestions.map((s) => (
                                                    <button key={s.id} onClick={() => setMapChoice({ ...mapChoice, [u.buyer_name]: String(s.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 hover:bg-primary-50 dark:border-slate-700">
                                                        {s.name} <span className="text-slate-400">{s.score}%</span>
                                                    </button>
                                                ))}
                                            </td>
                                            <td className={td}>
                                                <div className="flex gap-2">
                                                    <select className={inputBase + " w-64"} value={mapChoice[u.buyer_name] || ""} onChange={(e) => setMapChoice({ ...mapChoice, [u.buyer_name]: e.target.value })} disabled={!editable}>
                                                        <option value="">— pilih —</option>
                                                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}{e.nip ? ` (${e.nip})` : ""}</option>)}
                                                    </select>
                                                    <button className={btn.secondary} disabled={!editable || !mapChoice[u.buyer_name]} onClick={() => router.post(route("shu.sales-map", period.id), { buyer_name: u.buyer_name, customer_id: mapChoice[u.buyer_name] }, { preserveScroll: true })}>Simpan</button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="p-4 text-xs text-slate-500">Nama non-pegawai (mis. UMUM, KANTOR) cukup dibiarkan — tidak mendapat SHU.</p>
                        </div>
                    )}
                </Panel>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Penerima SHU" value={allocations.length} helper={`${stats.payers} dapat base SHU`} />
                <Stat label="Belanja pegawai" value={rupiah(stats.purchase_total)} helper={`${stats.purchase_buyers} pembeli · sumber ${period.sales_source === "excel" ? "Excel" : "POS"}`} />
                <Stat label="Total terbagi" value={rupiah(stats.distributed)} helper={period.total_shu ? `dari ${rupiah(period.total_shu)}` : ""} />
                <Stat label="Total dibulatkan" value={rupiah(stats.distributed_rounded)} helper="jumlah uang yang perlu disiapkan" />
            </div>

            <Panel
                title={`Hasil perhitungan (${allocations.length} orang)`}
                actions={<input className={inputBase + " w-64"} placeholder="Cari nama / NIP..." value={search} onChange={(e) => setSearch(e.target.value)} />}
            >
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th className={th}>Nama</th>
                                <th className={th + " text-right"}>Simpanan</th>
                                <th className={th + " text-right"}>Pembelian</th>
                                {period.portion_loans > 0 && <th className={th + " text-right"}>Angsuran</th>}
                                <th className={th + " text-right"}>Base</th>
                                <th className={th + " text-right"}>SHU simpanan</th>
                                <th className={th + " text-right"}>SHU pembelian</th>
                                {period.portion_loans > 0 && <th className={th + " text-right"}>SHU pinjaman</th>}
                                <th className={th + " text-right"}>Total</th>
                                <th className={th + " text-right"}>Dibulatkan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {rows.map((a) => (
                                <tr key={a.id}>
                                    <td className={td}>
                                        <div className="font-medium text-slate-900 dark:text-white">{a.customer?.name}</div>
                                        <div className="text-xs text-slate-500"><span className="font-mono">{a.customer?.nip || "tanpa NIP"}</span> · {a.customer?.employee_type} · {a.customer?.employment_status}</div>
                                    </td>
                                    <td className={td + " text-right"}>{rupiah(a.savings_amount)}<div className="text-xs text-slate-500">{percent(a.savings_share)}</div></td>
                                    <td className={td + " text-right"}>{rupiah(a.purchase_amount)}<div className="text-xs text-slate-500">{percent(a.purchase_share)}</div></td>
                                    {period.portion_loans > 0 && <td className={td + " text-right"}>{rupiah(a.loan_amount)}<div className="text-xs text-slate-500">{percent(a.loan_share)}</div></td>}
                                    <td className={td + " text-right"}>{rupiah(a.base_amount)}</td>
                                    <td className={td + " text-right"}>{rupiah(a.shu_savings)}</td>
                                    <td className={td + " text-right"}>{rupiah(a.shu_purchases)}</td>
                                    {period.portion_loans > 0 && <td className={td + " text-right"}>{rupiah(a.shu_loans)}</td>}
                                    <td className={td + " text-right"}>{rupiah(a.total)}</td>
                                    <td className={td + " text-right font-semibold text-slate-900 dark:text-white"}>{rupiah(a.total_rounded)}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && <tr><td colSpan={10} className="p-10 text-center text-slate-500">{allocations.length ? "Tidak ada yang cocok." : "Belum dihitung. Isi pengaturan, simpan, lalu klik “Hitung SHU”."}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
