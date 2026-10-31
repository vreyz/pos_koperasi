import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconDownload, IconFileSpreadsheet, IconUpload } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

export default function Import({ preview, employees }) {
    useFlashToasts();
    const upload = useForm({ file: null });
    const [onlyUnmatched, setOnlyUnmatched] = useState(true);
    const [saving, setSaving] = useState(false);
    const [mapping, setMapping] = useState(() =>
        Object.fromEntries((preview?.names || []).map((n) => [n.key, n.customer_id ? String(n.customer_id) : ""]))
    );

    const names = useMemo(
        () => (preview?.names || []).filter((n) => !onlyUnmatched || !n.customer_id),
        [preview, onlyUnmatched]
    );
    const matched = (preview?.names || []).filter((n) => n.customer_id).length;
    const resolved = Object.values(mapping).filter(Boolean).length;

    const commit = () => {
        const skipped = (preview.names.length - resolved);
        if (!confirm(`Simpan simpanan untuk ${resolved} nama? ${skipped} nama yang belum dipilih akan dilewati.`)) return;
        setSaving(true);
        router.post(route("savings.import.commit"), { token: preview.token, mapping }, { onFinish: () => setSaving(false) });
    };

    const acceptSuggestions = () =>
        setMapping((prev) => ({
            ...prev,
            ...Object.fromEntries(
                preview.names.filter((n) => !n.customer_id && !prev[n.key] && n.suggestions[0]?.score >= 85).map((n) => [n.key, String(n.suggestions[0].id)])
            ),
        }));

    return (
        <>
            <Head title="Impor Simpanan" />
            <PageHeader
                icon={IconFileSpreadsheet}
                title="Impor Rekap Simpanan"
                subtitle="Format sheet Master: Nama Pegawai, Wajib, Pokok, Sukarela, Angsuran Koperasi, Bulan, Tahun (NIP opsional). Impor ulang periode yang sama menimpa hasil import sebelumnya."
                actions={
                    <>
                        <Link href={route("savings.index")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>
                        <a href={route("savings.import.template")} className={btn.secondary}><IconDownload size={18} /> Template</a>
                    </>
                }
            />

            <Panel title="1. Upload file Excel" className="mb-6">
                <form onSubmit={(e) => { e.preventDefault(); upload.post(route("savings.import.preview"), { forceFormData: true }); }} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <input type="file" accept=".xlsx,.xls,.csv" className={inputClass} onChange={(e) => upload.setData("file", e.target.files[0])} />
                    <button type="submit" disabled={!upload.data.file || upload.processing} className={btn.primary}>
                        <IconUpload size={18} /> {upload.processing ? "Membaca file..." : "Pratinjau"}
                    </button>
                </form>
                <p className="px-4 pb-4 text-xs text-slate-500">Bila file punya beberapa sheet, sheet bernama "Master" dipakai. Baris judul/total otomatis dilewati.</p>
                {upload.errors.file && <p className="px-4 pb-4 text-sm text-danger-600">{upload.errors.file}</p>}
            </Panel>

            {preview && (
                <Panel
                    title="2. Cocokkan nama dengan data pegawai"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <button onClick={acceptSuggestions} className={btn.secondary}>Pakai saran ≥ 85%</button>
                            <button onClick={commit} disabled={saving} className={btn.primary}>{saving ? "Menyimpan..." : "Simpan import"}</button>
                        </div>
                    }
                >
                    <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                        <Stat label="Baris data" value={preview.rows.toLocaleString("id-ID")} />
                        <Stat label="Tahun" value={preview.years.join(", ")} />
                        <Stat label="Nama cocok otomatis" value={`${matched} / ${preview.names.length}`} />
                        <Stat label="Akan disimpan" value={`${resolved} nama`} />
                    </div>
                    <label className="flex items-center gap-2 px-4 pb-3 text-sm text-slate-600 dark:text-slate-400">
                        <input type="checkbox" checked={onlyUnmatched} onChange={(e) => setOnlyUnmatched(e.target.checked)} /> Tampilkan hanya nama yang belum cocok
                    </label>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th className={th}>Nama di Excel</th>
                                    <th className={th}>Periode</th>
                                    <th className={th + " text-right"}>Total</th>
                                    <th className={th}>Saran</th>
                                    <th className={th}>Pegawai</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {names.map((n) => (
                                    <tr key={n.key} className="align-top">
                                        <td className={td}>
                                            <div className="font-medium">{n.name}</div>
                                            {n.customer_id ? <Badge color="green">Cocok</Badge> : <Badge color="amber">Belum cocok</Badge>}
                                        </td>
                                        <td className={td + " text-xs"}>{n.first} s.d. {n.last}<div className="text-slate-500">{n.rows} baris</div></td>
                                        <td className={td + " text-right"}>{rupiah(n.total)}</td>
                                        <td className={td + " whitespace-normal text-xs"}>
                                            {n.suggestions.map((s) => (
                                                <button key={s.id} onClick={() => setMapping({ ...mapping, [n.key]: String(s.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 hover:bg-primary-50 dark:border-slate-700">
                                                    {s.name} <span className="text-slate-400">{s.score}%</span>
                                                </button>
                                            ))}
                                        </td>
                                        <td className={td}>
                                            <select className={inputBase + " w-72"} value={mapping[n.key] || ""} onChange={(e) => setMapping({ ...mapping, [n.key]: e.target.value })}>
                                                <option value="">— lewati —</option>
                                                <option value="new">+ Buat pegawai baru dengan nama ini</option>
                                                {employees.map((e) => <option key={e.id} value={e.id}>{e.name}{e.nip ? ` (${e.nip})` : ""}</option>)}
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                                {names.length === 0 && <tr><td colSpan={5} className="p-8 text-center text-slate-500">Semua nama sudah cocok. Klik “Simpan import”.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <p className="p-4 text-xs text-slate-500">Pilihan pencocokan disimpan sebagai alias, jadi nama yang sama otomatis cocok di impor berikutnya (simpanan maupun penjualan).</p>
                </Panel>
            )}
        </>
    );
}

Import.layout = (page) => <DashboardLayout children={page} />;
