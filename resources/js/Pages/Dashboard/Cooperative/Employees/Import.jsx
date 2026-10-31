import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconDownload, IconFileSpreadsheet, IconUpload } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, inputClass, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

const STATUS = {
    new: { label: "Baru", color: "green", help: "Belum ada di database → langsung diimport" },
    same: { label: "Sama", color: "gray", help: "Sudah ada dan datanya sama → dilewati" },
    changed: { label: "Berubah", color: "blue", help: "Sudah ada tetapi ada perbedaan → konfirmasi" },
    suspect: { label: "Perlu dicek", color: "amber", help: "NIP/nama mirip data lain, kemungkinan salah ketik → konfirmasi" },
};

export default function Import({ preview, fieldLabels }) {
    useFlashToasts();
    const upload = useForm({ file: null });
    const [filter, setFilter] = useState(preview ? (preview.counts.suspect ? "suspect" : preview.counts.changed ? "changed" : "all") : "all");
    const [actions, setActions] = useState(() =>
        Object.fromEntries((preview?.rows || []).map((r) => [r.row, r.default_action]))
    );
    const [saving, setSaving] = useState(false);

    const rows = useMemo(
        () => (preview?.rows || []).filter((r) => filter === "all" || r.status === filter),
        [preview, filter]
    );

    const planned = useMemo(() => {
        const out = { create: 0, update: 0, skip: 0 };
        Object.values(actions).forEach((a) => (out[a.startsWith("update") ? "update" : a] += 1));
        return out;
    }, [actions]);

    const submitUpload = (e) => {
        e.preventDefault();
        upload.post(route("employees.import.preview"), { forceFormData: true });
    };

    const commit = () => {
        if (!confirm(`Simpan: ${planned.create} baru, ${planned.update} diperbarui, ${planned.skip} dilewati?`)) return;
        setSaving(true);
        router.post(route("employees.import.commit"), { token: preview.token, actions }, { onFinish: () => setSaving(false) });
    };

    const setAll = (status, action) =>
        setActions((prev) => ({
            ...prev,
            ...Object.fromEntries(preview.rows.filter((r) => r.status === status).map((r) => [r.row, action])),
        }));

    return (
        <>
            <Head title="Impor Pegawai" />
            <PageHeader
                icon={IconFileSpreadsheet}
                title="Impor Data Pegawai"
                subtitle="Data yang sama dilewati, data berbeda dikonfirmasi, data baru langsung diimport. NIP/nama mirip ditandai untuk dicek."
                actions={
                    <>
                        <Link href={route("employees.index")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>
                        <a href={route("employees.import.template")} className={btn.secondary}><IconDownload size={18} /> Template</a>
                    </>
                }
            />

            <Panel title="1. Upload file Excel" className="mb-6">
                <form onSubmit={submitUpload} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <input type="file" accept=".xlsx,.xls,.csv" className={inputClass} onChange={(e) => upload.setData("file", e.target.files[0])} />
                    <button type="submit" disabled={!upload.data.file || upload.processing} className={btn.primary}>
                        <IconUpload size={18} /> {upload.processing ? "Membaca..." : "Pratinjau"}
                    </button>
                </form>
                <p className="px-4 pb-4 text-xs text-slate-500">
                    Kolom yang dikenali: NIP, Nama (atau "N A M A"), Jenis (PNS/PPPK/PPNPN), Pangkat, Gol, Jabatan, Unit, Status. Header boleh di baris mana saja dalam 15 baris pertama.
                    Simpan kolom NIP sebagai <b>Teks</b> agar Excel tidak membulatkan digit terakhir.
                </p>
                {upload.errors.file && <p className="px-4 pb-4 text-sm text-danger-600">{upload.errors.file}</p>}
            </Panel>

            {preview && (
                <Panel
                    title={`2. Periksa & konfirmasi (${preview.rows.length} baris)`}
                    actions={
                        <button onClick={commit} disabled={saving} className={btn.primary}>
                            {saving ? "Menyimpan..." : `Simpan (${planned.create} baru, ${planned.update} update)`}
                        </button>
                    }
                >
                    <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                        {Object.entries(STATUS).map(([key, s]) => (
                            <button key={key} onClick={() => setFilter(filter === key ? "all" : key)} className={`text-left ${filter === key ? "ring-2 ring-primary-400 rounded-2xl" : ""}`}>
                                <Stat label={s.label} value={preview.counts[key] || 0} helper={s.help} />
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2 px-4 pb-4 text-sm">
                        <span className="text-slate-500">Atur sekaligus:</span>
                        <button className="text-primary-600 hover:underline" onClick={() => setAll("changed", "update")}>Update semua yang berubah</button>
                        <button className="text-primary-600 hover:underline" onClick={() => setAll("changed", "skip")}>Lewati semua yang berubah</button>
                        <button className="text-primary-600 hover:underline" onClick={() => setAll("suspect", "skip")}>Lewati semua yang perlu dicek</button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th className={th}>Baris</th>
                                    <th className={th}>Status</th>
                                    <th className={th}>NIP</th>
                                    <th className={th}>Nama</th>
                                    <th className={th}>Detail</th>
                                    <th className={th}>Tindakan</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {rows.map((r) => (
                                    <tr key={r.row} className="align-top">
                                        <td className={td}>{r.row}</td>
                                        <td className={td}><Badge color={STATUS[r.status].color}>{STATUS[r.status].label}</Badge></td>
                                        <td className={td + " font-mono"}>{r.data.nip || "—"}</td>
                                        <td className={td}>
                                            <div className="font-medium">{r.data.name}</div>
                                            <div className="text-xs text-slate-500">{[r.data.employee_type, r.data.work_unit, r.data.employment_status].filter(Boolean).join(" · ")}</div>
                                        </td>
                                        <td className={td + " whitespace-normal text-xs"}>
                                            {Object.entries(r.diff).map(([field, d]) => (
                                                <div key={field}>
                                                    <b>{fieldLabels[field]}:</b> <span className="text-danger-600 line-through">{d.old || "(kosong)"}</span> → <span className="text-success-700">{d.new}</span>
                                                </div>
                                            ))}
                                            {r.warnings.map((w, i) => <div key={i} className="text-warning-700 dark:text-warning-400">⚠ {w}</div>)}
                                            {r.status === "suspect" && r.candidates.map((c) => (
                                                <div key={c.id} className="text-slate-600 dark:text-slate-400">
                                                    Mirip: <b>{c.name}</b> {c.nip && <span className="font-mono">({c.nip})</span>} — {c.reason}
                                                </div>
                                            ))}
                                        </td>
                                        <td className={td}>
                                            {r.status === "same" ? (
                                                <span className="text-xs text-slate-500">Dilewati</span>
                                            ) : (
                                                <select className={inputBase + " w-56"} value={actions[r.row]} onChange={(e) => setActions({ ...actions, [r.row]: e.target.value })}>
                                                    <option value="skip">Lewati</option>
                                                    {r.status !== "changed" && <option value="create">Tambah sebagai pegawai baru</option>}
                                                    {r.status === "changed" && <option value="update">Update data lama</option>}
                                                    {r.status === "suspect" && r.candidates.map((c) => (
                                                        <option key={c.id} value={`update:${c.id}`}>Update “{c.name}”</option>
                                                    ))}
                                                </select>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={6} className="p-8 text-center text-slate-500">Tidak ada baris dengan status ini.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}
        </>
    );
}

Import.layout = (page) => <DashboardLayout children={page} />;
