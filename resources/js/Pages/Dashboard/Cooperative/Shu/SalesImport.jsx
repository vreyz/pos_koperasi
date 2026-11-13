import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconFileSpreadsheet, IconRefresh, IconUpload } from "@tabler/icons-react";
import { PageHeader, Panel, Stat, btn, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";
import ValidationCheck from "@/Components/Cooperative/ValidationCheck";

// Pratinjau baru (file lain) = state baru: pilihan nama & hasil validasi tidak terbawa dari file sebelumnya.
export default function SalesImport(props) {
    return <SalesImportPage key={props.preview?.token || "upload"} {...props} />;
}

function SalesImportPage({ period, current, preview, employees }) {
    useFlashToasts();
    const upload = useForm({ file: null });
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    // Hanya pilihan yang diubah di layar; nama lain mengikuti pencocokan otomatis.
    const [mapping, setMappingState] = useState({});
    const [checks, setChecks] = useState(preview?.checks || []);
    const [stale, setStale] = useState(false);
    const [validating, setValidating] = useState(false);
    const [saving, setSaving] = useState(false);
    const setMapping = (next) => { setMappingState(next); setStale(true); };
    const errorCount = checks.filter((c) => c.level === "error").length;
    const warningCount = checks.filter((c) => c.level === "warning").length;
    const chosen = Object.values(mapping).filter(Boolean).length;

    const revalidate = async () => {
        setValidating(true);
        try {
            const { data } = await window.axios.post(route("shu.sales-import.validate", period.id), { token: preview.token, mapping });
            setChecks(data.checks);
            setStale(false);
        } catch (e) {
            alert(e.response?.data?.message || "Validasi gagal. Coba lagi.");
        } finally {
            setValidating(false);
        }
    };

    const commit = () => {
        if (!confirm(`Ganti penjualan Excel ${period.year} (${current.rows.toLocaleString("id-ID")} baris, ${rupiah(current.total)}) dengan isi file ini (${rupiah(preview.total)})?${warningCount ? ` Ada ${warningCount} peringatan validasi — pastikan sudah dibaca.` : ""}`)) return;
        setSaving(true);
        router.post(route("shu.sales-import.commit", period.id), { token: preview.token, mapping }, { onFinish: () => setSaving(false) });
    };

    const picker = (key) => {
        const n = preview.names.find((x) => x.key === key);
        if (!n) return null;
        return (
            <div key={key} className="flex flex-wrap items-center gap-2 rounded-md bg-white/60 px-2 py-1.5 text-slate-800 dark:bg-slate-950/40 dark:text-slate-200">
                <div className="min-w-[12rem] flex-1">
                    <div className="font-medium">{n.name}</div>
                    <div className="text-xs opacity-75">{rupiah(n.total)} · {n.days} hari belanja{n.first ? ` · ${n.first} s.d. ${n.last}` : ""}</div>
                    {n.suggestions.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1 text-xs">
                            {n.suggestions.map((s) => (
                                <button key={s.id} type="button" onClick={() => setMapping({ ...mapping, [key]: String(s.id) })} className="rounded border border-slate-300 px-1.5 py-0.5 hover:bg-primary-50 dark:border-slate-700 dark:hover:bg-primary-900/30">
                                    {s.name} <span className="opacity-60">{s.score}%</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
                <SearchSelect className="w-80" options={empOptions} extra={[{ value: "", label: "— bukan pegawai / biarkan —" }]} value={mapping[key] || ""} onChange={(v) => setMapping({ ...mapping, [key]: String(v) })} />
            </div>
        );
    };

    const saveBlocked = saving || stale || errorCount > 0;

    return (
        <>
            <Head title={`Impor Penjualan ${period.year}`} />
            <PageHeader
                icon={IconFileSpreadsheet}
                title={`Impor Penjualan ${period.year}`}
                subtitle="Kolom yang dibaca: TGL BELANJA, NAMA PEGAWAI (atau Nama Final), TOTAL HARGA per baris barang — mis. rekap setahun &quot;Penjualan 2025&quot; (Sheet1). Kolom TOTAL BELANJA dan cara bayar (PG, QR, CASH, VC) dipakai untuk mengecek total. Sheet rekap lain dilewati."
                actions={<Link href={route("shu.show", period.id)} className={btn.secondary}><IconArrowLeft size={18} /> Kembali ke SHU {period.year}</Link>}
            />

            <Panel title="1. Upload file Excel" className="mb-6">
                <form onSubmit={(e) => { e.preventDefault(); upload.post(route("shu.sales-import.preview", period.id), { forceFormData: true }); }} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <input type="file" accept=".xlsx,.xls,.csv" className={inputClass} onChange={(e) => upload.setData("file", e.target.files[0])} />
                    <button type="submit" disabled={!upload.data.file || upload.processing} className={btn.primary}>
                        <IconUpload size={18} /> {upload.processing ? "Membaca file..." : "Pratinjau"}
                    </button>
                </form>
                <p className="px-4 pb-4 text-xs text-slate-500">Data sekarang: {current.rows.toLocaleString("id-ID")} baris, {rupiah(current.total)}. Belum ada yang berubah sampai Anda klik Simpan.</p>
                {upload.errors.file && <p className="px-4 pb-4 text-sm text-danger-600">{upload.errors.file}</p>}
            </Panel>

            {preview && (
                <>
                    <Panel
                        title="2. Hasil validasi"
                        className="mb-6"
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <button onClick={revalidate} disabled={validating} className={stale ? btn.primary : btn.secondary}>
                                    <IconRefresh size={18} /> {validating ? "Memeriksa..." : "Validasi ulang"}
                                </button>
                                <button onClick={commit} disabled={saveBlocked} className={btn.primary} title={errorCount ? "Perbaiki kesalahan validasi dulu" : stale ? "Validasi ulang dulu" : ""}>
                                    {saving ? "Menyimpan..." : errorCount ? `Ada ${errorCount} kesalahan` : stale ? "Validasi ulang dulu" : "Simpan penjualan"}
                                </button>
                            </div>
                        }
                    >
                        <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                            <Stat label="Baris barang" value={preview.rows.toLocaleString("id-ID")} helper={`sheet ${preview.sheet}`} />
                            <Stat label="Total penjualan" value={rupiah(preview.total)} />
                            <Stat label="Nama pembeli" value={preview.buyers.toLocaleString("id-ID")} helper={`${preview.matched} cocok dengan pegawai`} />
                            <Stat label="Dipilih manual" value={`${chosen} nama`} />
                        </div>
                        <div className="space-y-2 px-4 pb-4">
                            {preview.file_name && <p className="text-xs text-slate-500">File: {preview.file_name}</p>}
                            {stale && (
                                <div className="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/40 dark:text-warning-200">
                                    Pilihan nama berubah. Klik <b>Validasi ulang</b> sebelum menyimpan.
                                </div>
                            )}
                            {checks.map((c, i) => <ValidationCheck key={`${c.level}-${i}-${c.title}`} check={c} />)}
                        </div>
                    </Panel>

                    <Panel title={`3. Nama pembeli yang belum cocok dengan pegawai (${preview.names.length})`}>
                        <p className="px-4 pt-3 text-xs text-slate-500">Pilih pegawainya bila memang pegawai (mis. ejaan/singkatan berbeda). Pilihan disimpan sebagai alias, jadi otomatis cocok di impor berikutnya. Biarkan kosong untuk pembeli umum/non-pegawai — penjualannya tetap tersimpan, tetapi tidak ikut SHU belanja.</p>
                        <div className="max-h-[36rem] space-y-1.5 overflow-y-auto p-4">
                            {preview.names.map((n) => picker(n.key))}
                            {preview.names.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Semua nama pembeli sudah cocok dengan pegawai.</p>}
                        </div>
                    </Panel>
                </>
            )}
        </>
    );
}

SalesImport.layout = (page) => <DashboardLayout children={page} />;
