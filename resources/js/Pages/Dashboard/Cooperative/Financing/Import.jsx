import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconFileSpreadsheet, IconUpload } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, inputClass, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

export default function Import({ preview, employees }) {
    useFlashToasts();
    const upload = useForm({ file: null });
    const [onlyUnmatched, setOnlyUnmatched] = useState(true);
    const [saving, setSaving] = useState(false);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    const [mapping, setMapping] = useState(() => Object.fromEntries((preview?.names || []).map((n) => [n.key, n.customer_id ? String(n.customer_id) : ""])));
    const names = useMemo(() => (preview?.names || []).filter((n) => !onlyUnmatched || !n.customer_id), [preview, onlyUnmatched]);

    const commit = () => {
        const skipped = preview.names.filter((n) => !mapping[n.key]).length;
        if (!confirm(`Simpan ${preview.contracts} kontrak${skipped ? `? ${skipped} nama tanpa pegawai akan dilewati` : ""}?`)) return;
        setSaving(true);
        router.post(route("financing.import.commit"), { token: preview.token, mapping }, { onFinish: () => setSaving(false) });
    };

    return (
        <>
            <Head title="Impor Pembiayaan" />
            <PageHeader
                icon={IconFileSpreadsheet}
                title="Impor Data Pembiayaan"
                subtitle="Bisa file PEMBIAYAAN KOPERASI (sheet dana pegawai, dana PPNPN, barang), Data Pembiayaan LK, Permohonan Pencairan Dana (biaya formulir & rekening), atau file hasil Unduh Excel dari aplikasi. Impor ulang tidak membuat data dobel: kontrak yang sama diperbarui."
                actions={<Link href={route("financing.index", "dana")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>}
            />
            <Panel title="1. Upload file Excel" className="mb-6">
                <form onSubmit={(e) => { e.preventDefault(); upload.post(route("financing.import.preview"), { forceFormData: true }); }} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <input type="file" accept=".xlsx,.xls" className={inputClass} onChange={(e) => upload.setData("file", e.target.files[0])} />
                    <button type="submit" disabled={!upload.data.file || upload.processing} className={btn.primary}><IconUpload size={18} /> {upload.processing ? "Membaca..." : "Pratinjau"}</button>
                </form>
                <p className="px-4 pb-4 text-xs text-slate-500">Urutan disarankan: file kontrak dulu (LK / PEMBIAYAAN KOPERASI), baru file Permohonan Pencairan agar biaya formulir terpasang ke kontraknya. Jumlah cicilan terbayar diambil dari kolom Angsuran 1–10 / checklist.</p>
                {upload.errors.file && <p className="px-4 pb-4 text-sm text-danger-600">{upload.errors.file}</p>}
            </Panel>

            {preview && (
                <Panel title="2. Cocokkan nama pemohon" actions={<button onClick={commit} disabled={saving} className={btn.primary}>{saving ? "Menyimpan..." : "Simpan impor"}</button>}>
                    <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                        <Stat label="Kontrak dana" value={preview.by_type?.dana || 0} />
                        <Stat label="Kontrak barang" value={preview.by_type?.barang || 0} />
                        <Stat label="Baris pencairan" value={preview.disbursements} helper="biaya formulir & rekening" />
                        <Stat label="Nama cocok" value={`${preview.names.filter((n) => n.customer_id).length} / ${preview.names.length}`} />
                    </div>
                    <label className="flex items-center gap-2 px-4 pb-3 text-sm text-slate-600 dark:text-slate-400">
                        <input type="checkbox" checked={onlyUnmatched} onChange={(e) => setOnlyUnmatched(e.target.checked)} /> Hanya nama yang belum cocok
                    </label>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-950"><tr><th className={th}>Nama di Excel</th><th className={th}>Baris</th><th className={th}>Saran</th><th className={th}>Pegawai</th></tr></thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {names.map((n) => (
                                    <tr key={n.key} className="align-top">
                                        <td className={td}><div className="font-medium">{n.name}</div>{n.status_hint && <Badge color="blue">{n.status_hint}</Badge>} {n.customer_id ? <Badge color="green">Cocok</Badge> : <Badge color="amber">Belum cocok</Badge>}</td>
                                        <td className={td}>{n.count}</td>
                                        <td className={td + " whitespace-normal text-xs"}>
                                            {n.suggestions.map((s) => (
                                                <button key={s.id} onClick={() => setMapping({ ...mapping, [n.key]: String(s.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 hover:bg-primary-50 dark:border-slate-700">{s.name} <span className="text-slate-400">{s.score}%</span></button>
                                            ))}
                                        </td>
                                        <td className={td}>
                                            <SearchSelect className="w-72" options={empOptions} extra={[{ value: "", label: "— lewati —" }, { value: "new", label: `+ Buat pegawai baru${n.status_hint === "PPNPN" ? " (PPNPN)" : ""}` }]} value={mapping[n.key] || ""} onChange={(v) => setMapping({ ...mapping, [n.key]: String(v) })} />
                                        </td>
                                    </tr>
                                ))}
                                {names.length === 0 && <tr><td colSpan={4} className="p-8 text-center text-slate-500">Semua nama sudah cocok.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}
        </>
    );
}

Import.layout = (page) => <DashboardLayout children={page} />;
