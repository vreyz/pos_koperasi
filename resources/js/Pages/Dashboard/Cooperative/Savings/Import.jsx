import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconAlertCircle, IconAlertTriangle, IconArrowLeft, IconCircleCheck, IconDownload, IconFileSpreadsheet, IconInfoCircle, IconRefresh, IconUpload } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, Stat, btn, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const LEVELS = {
    error: { icon: IconAlertCircle, label: "Kesalahan", box: "border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-900/60 dark:bg-danger-950/40 dark:text-danger-200" },
    warning: { icon: IconAlertTriangle, label: "Peringatan", box: "border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/40 dark:text-warning-200" },
    info: { icon: IconInfoCircle, label: "Info", box: "border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" },
    ok: { icon: IconCircleCheck, label: "OK", box: "border-success-200 bg-success-50 text-success-800 dark:border-success-900/60 dark:bg-success-950/40 dark:text-success-200" },
};

function Check({ check }) {
    const [open, setOpen] = useState(check.level === "error");
    const level = LEVELS[check.level] || LEVELS.info;
    const Icon = level.icon;
    return (
        <div className={`rounded-lg border px-3 py-2 text-sm ${level.box}`}>
            <div className="flex items-start gap-2">
                <Icon size={18} className="mt-0.5 shrink-0" />
                <div className="flex-1">
                    {check.title}
                    {check.details.length > 0 && (
                        <button type="button" onClick={() => setOpen(!open)} className="ml-2 text-xs underline opacity-80">
                            {open ? "sembunyikan" : `lihat rincian (${check.details.length})`}
                        </button>
                    )}
                    {open && check.details.length > 0 && (
                        <ul className="mt-1 max-h-60 list-disc overflow-y-auto pl-5 text-xs opacity-90">
                            {check.details.map((d, i) => <li key={i}>{d}</li>)}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

// Pratinjau baru (file lain) = state baru: pilihan nama & hasil validasi tidak terbawa dari file sebelumnya.
export default function Import(props) {
    return <ImportPage key={props.preview?.token || "upload"} {...props} />;
}

function ImportPage({ preview, employees }) {
    useFlashToasts();
    const upload = useForm({ file: null });
    const [onlyUnmatched, setOnlyUnmatched] = useState(true);
    const [saving, setSaving] = useState(false);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    const initialMapping = () => Object.fromEntries((preview?.names || []).map((n) => [n.key, n.customer_id ? String(n.customer_id) : ""]));
    const [mapping, setMappingState] = useState(initialMapping);
    // Hasil validasi berlaku untuk pemetaan saat divalidasi; mengubah pilihan nama → wajib validasi ulang.
    const [checks, setChecks] = useState(preview?.checks || []);
    const [stale, setStale] = useState(false);
    const [validating, setValidating] = useState(false);
    const setMapping = (next) => { setMappingState(next); setStale(true); };

    const names = useMemo(
        () => (preview?.names || []).filter((n) => !onlyUnmatched || !n.customer_id),
        [preview, onlyUnmatched]
    );
    const matched = (preview?.names || []).filter((n) => n.customer_id).length;
    const resolved = Object.values(mapping).filter(Boolean).length;
    const errorCount = checks.filter((c) => c.level === "error").length;
    const warningCount = checks.filter((c) => c.level === "warning").length;
    const hasWithdrawals = (preview?.names || []).some((n) => n.withdrawn > 0);

    const revalidate = async () => {
        setValidating(true);
        try {
            const { data } = await window.axios.post(route("savings.import.validate"), { token: preview.token, mapping });
            setChecks(data.checks);
            setStale(false);
        } catch (e) {
            alert(e.response?.data?.message || "Validasi gagal. Coba lagi.");
        } finally {
            setValidating(false);
        }
    };

    const commit = () => {
        const skipped = preview.names.length - resolved;
        if (!confirm(`Simpan simpanan untuk ${resolved} nama? ${skipped} nama yang belum dipilih akan dilewati.${warningCount ? ` Ada ${warningCount} peringatan validasi — pastikan sudah dibaca.` : ""}`)) return;
        setSaving(true);
        router.post(route("savings.import.commit"), { token: preview.token, mapping }, { onFinish: () => setSaving(false) });
    };

    const acceptSuggestions = () =>
        setMapping({
            ...mapping,
            ...Object.fromEntries(
                preview.names.filter((n) => !n.customer_id && !mapping[n.key] && n.suggestions[0]?.score >= 85).map((n) => [n.key, String(n.suggestions[0].id)])
            ),
        });

    const saveBlocked = saving || stale || errorCount > 0;

    return (
        <>
            <Head title="Impor Simpanan" />
            <PageHeader
                icon={IconFileSpreadsheet}
                title="Impor Simpanan"
                subtitle="Format yang dikenali otomatis: (1) sheet Master potongan gaji (Nama Pegawai, Wajib, Pokok, Sukarela, Bulan, Tahun) atau file hasil tombol Data; (2) rekap bulanan per nama dengan kolom JAN s.d. DES (tahun dari judul/nama sheet, jenis simpanan dari judul atau nama file); (3) pengembalian simpanan (Nama, Tanggal, Pengembalian Wajib/Sukarela) yang dicatat sebagai pengurang saldo. Sebelum disimpan, isi file divalidasi dulu. Impor ulang tidak membuat dobel."
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
                <p className="px-4 pb-4 text-xs text-slate-500">Semua sheet yang formatnya dikenali ikut dibaca; sheet lain (mis. daftar gaji/rekening) dilewati. Baris judul, total, dan tanda tangan otomatis dilewati.</p>
                {upload.errors.file && <p className="px-4 pb-4 text-sm text-danger-600">{upload.errors.file}</p>}
            </Panel>

            {preview && (
                <>
                    <Panel
                        title="2. Hasil validasi"
                        className="mb-6"
                        actions={
                            <button onClick={revalidate} disabled={validating} className={stale ? btn.primary : btn.secondary}>
                                <IconRefresh size={18} /> {validating ? "Memeriksa..." : "Validasi ulang"}
                            </button>
                        }
                    >
                        <div className="space-y-2 p-4">
                            {preview.file_name && <p className="text-xs text-slate-500">File: {preview.file_name} · sheet dibaca: {preview.sheets.map((s) => `${s.sheet} (${s.label})`).join("; ")}</p>}
                            {stale && (
                                <div className="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/40 dark:text-warning-200">
                                    Pilihan nama berubah. Klik <b>Validasi ulang</b> sebelum menyimpan.
                                </div>
                            )}
                            {checks.map((c, i) => <Check key={`${c.level}-${i}-${c.title}`} check={c} />)}
                        </div>
                    </Panel>

                    <Panel
                        title="3. Cocokkan nama dengan data pegawai"
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <button onClick={acceptSuggestions} className={btn.secondary}>Pakai saran ≥ 85%</button>
                                <button
                                    onClick={commit}
                                    disabled={saveBlocked}
                                    className={btn.primary}
                                    title={errorCount ? "Perbaiki kesalahan validasi dulu" : stale ? "Validasi ulang dulu" : ""}
                                >
                                    {saving ? "Menyimpan..." : errorCount ? `Ada ${errorCount} kesalahan` : stale ? "Validasi ulang dulu" : "Simpan import"}
                                </button>
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
                                        <th className={th + " text-right"}>Setoran</th>
                                        {hasWithdrawals && <th className={th + " text-right"}>Pengembalian</th>}
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
                                            {hasWithdrawals && <td className={td + " text-right text-danger-600 dark:text-danger-400"}>{n.withdrawn ? `−${rupiah(n.withdrawn)}` : ""}</td>}
                                            <td className={td + " whitespace-normal text-xs"}>
                                                {n.suggestions.map((s) => (
                                                    <button key={s.id} onClick={() => setMapping({ ...mapping, [n.key]: String(s.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 hover:bg-primary-50 dark:border-slate-700 dark:hover:bg-primary-900/30">
                                                        {s.name} <span className="text-slate-400">{s.score}%</span>
                                                    </button>
                                                ))}
                                            </td>
                                            <td className={td}>
                                                <SearchSelect className="w-72" options={empOptions} extra={[{ value: "", label: "— lewati —" }, { value: "new", label: "+ Buat pegawai baru dengan nama ini" }]} value={mapping[n.key] || ""} onChange={(v) => setMapping({ ...mapping, [n.key]: String(v) })} />
                                            </td>
                                        </tr>
                                    ))}
                                    {names.length === 0 && <tr><td colSpan={hasWithdrawals ? 6 : 5} className="p-8 text-center text-slate-500">Semua nama sudah cocok. Periksa hasil validasi, lalu klik “Simpan import”.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <p className="p-4 text-xs text-slate-500">Pilihan pencocokan disimpan sebagai alias, jadi nama yang sama otomatis cocok di impor berikutnya (simpanan maupun penjualan).</p>
                    </Panel>
                </>
            )}
        </>
    );
}

Import.layout = (page) => <DashboardLayout children={page} />;
