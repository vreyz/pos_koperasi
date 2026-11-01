import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconCheck, IconReceipt2, IconUpload } from "@tabler/icons-react";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputBase, inputClass } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const STEPS = ["Unggah file", "Judul & kolom", "Cocokkan nama", "Pratinjau & simpan"];
const num = (v) => (v === null || v === undefined ? "–" : Math.round(Number(v)).toLocaleString("id-ID"));

export default function Import({ fields, typeLabels, employees }) {
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState("");
    const [error, setError] = useState("");
    const [layout, setLayout] = useState(null);
    const [mapping, setMapping] = useState({});
    const [header, setHeader] = useState({ from: 1, to: 1 });
    const [names, setNames] = useState([]);
    const [nameMap, setNameMap] = useState({});
    const [showMatched, setShowMatched] = useState(false);
    const [preview, setPreview] = useState(null);
    const [confirmed, setConfirmed] = useState({});
    const [result, setResult] = useState(null);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);

    const call = async (label, url, body, onOk) => {
        setBusy(label);
        setError("");
        try {
            const { data } = await window.axios.post(url, body);
            onOk(data);
        } catch (e) {
            setError(e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || "Terjadi kesalahan. Coba lagi.");
        } finally {
            setBusy("");
        }
    };
    const applyLayout = (data) => {
        setLayout((old) => ({ ...old, ...data }));
        setHeader({ from: data.header_from, to: data.header_to });
        setMapping(data.mapping);
        setStep(1);
    };
    const base = () => ({ token: layout.token, sheet: layout.sheet, header_to: header.to, mapping });

    const upload = (file) => {
        if (!file) return;
        const body = new FormData();
        body.append("file", file);
        call("Membaca file… (file besar bisa 1 menit)", route("deductions.import.upload"), body, applyLayout);
    };
    const changeLayout = (params) => call("Membaca sheet…", route("deductions.import.layout"), { token: layout.token, sheet: layout.sheet, ...params }, applyLayout);
    const analyze = () =>
        call("Membaca data…", route("deductions.import.analyze"), base(), (data) => {
            setNames(data.names);
            // Nama yang belum cocok hanya diisi otomatis bila sangat mirip (≥ 90); selebihnya dipilih user.
            setNameMap(Object.fromEntries(data.names.map((n) => [n.key, n.customer_id || (n.suggestions?.[0]?.score >= 90 ? n.suggestions[0].id : "")])));
            setLayout((old) => ({ ...old, rows: data.rows, years: data.years }));
            setStep(2);
        });
    const runPreview = () =>
        call("Membandingkan dengan data tersimpan…", route("deductions.import.preview"), { ...base(), names: nameMap }, (data) => {
            setPreview(data);
            setConfirmed({});
            setStep(3);
        });
    const commit = () =>
        call("Menyimpan…", route("deductions.import.commit"), { ...base(), names: nameMap, confirmed: Object.keys(confirmed).filter((k) => confirmed[k]) }, setResult);

    const mappedIndexes = new Set(Object.values(mapping).filter((v) => v !== null && v !== ""));
    const unmatched = names.filter((n) => !n.customer_id);
    const matched = names.filter((n) => n.customer_id);
    const allChecked = preview && preview.changed_rows.length > 0 && preview.changed_rows.every((r) => confirmed[r.key]);

    return (
        <>
            <Head title="Impor Angsuran & Potongan" />
            <PageHeader
                icon={IconReceipt2}
                title="Impor Angsuran & Potongan Koperasi"
                subtitle="Unggah rekap potongan gaji (mis. sheet Master). Data yang sama dilewati, data baru langsung disimpan, data yang berbeda harus Anda centang dulu sebelum disimpan."
                actions={<Link href={route("deductions.index")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>}
            />

            <ol className="mb-6 flex flex-wrap gap-2 text-sm">
                {STEPS.map((s, i) => (
                    <li key={s} className={`rounded-full px-3 py-1 ${i === step ? "bg-primary-600 text-white" : i < step ? "bg-primary-50 text-primary-700 dark:bg-primary-900/30" : "bg-slate-100 text-slate-500 dark:bg-slate-800"}`}>{i + 1}. {s}</li>
                ))}
            </ol>
            {busy && <p className="mb-4 text-sm text-slate-500">{busy}</p>}
            {error && <p className="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-900/20">{error}</p>}

            {result ? (
                <Panel title="Impor selesai">
                    <div className="space-y-3 p-4 text-sm">
                        <p className="flex items-center gap-2 text-success-600"><IconCheck size={18} /> {result.inserted} data baru disimpan, {result.updated} data diperbarui, {result.skipped} dilewati.</p>
                        {result.installments_paid > 0 && <p>{result.installments_paid} cicilan pembiayaan ditandai lunas dari Angsuran Koperasi.</p>}
                        <button onClick={() => router.visit(route("deductions.index"))} className={btn.primary}>Lihat rekap</button>
                    </div>
                </Panel>
            ) : step === 0 ? (
                <Panel title="1. Pilih file Excel">
                    <div className="p-4">
                        <input type="file" accept=".xlsx,.xls,.csv" className={inputClass} disabled={!!busy} onChange={(e) => upload(e.target.files[0])} />
                    </div>
                </Panel>
            ) : step === 1 ? (
                <div className="space-y-6">
                    <Panel title="2. Pilih sheet, baris judul, dan kolom">
                        <div className="grid gap-4 p-4 sm:grid-cols-3">
                            <Field label="Sheet">
                                <select className={inputBase + " w-full"} value={layout.sheet} onChange={(e) => changeLayout({ sheet: e.target.value })}>
                                    {layout.sheets.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </Field>
                            <Field label="Baris judul dari" tip="Judul bertingkat (mis. Simpanan Koperasi → Wajib/Pokok) boleh lebih dari satu baris. Klik nomor baris di tabel untuk memilih.">
                                <input type="number" min="1" className={inputClass} value={header.from} onChange={(e) => setHeader({ ...header, from: Number(e.target.value) })} />
                            </Field>
                            <Field label="Baris judul sampai" tip="Data dibaca mulai baris setelah ini. Baris nomor kolom/total otomatis dilewati.">
                                <div className="flex gap-2">
                                    <input type="number" min={header.from} className={inputClass} value={header.to} onChange={(e) => setHeader({ ...header, to: Number(e.target.value) })} />
                                    <button type="button" className={btn.secondary} onClick={() => changeLayout({ header_from: header.from, header_to: header.to })}>Terapkan</button>
                                </div>
                            </Field>
                        </div>
                        <div className="max-h-96 overflow-auto border-t border-slate-100 dark:border-slate-800">
                            <table className="text-xs">
                                <thead className="sticky top-0 bg-slate-50 dark:bg-slate-900">
                                    <tr>
                                        <th className="px-2 py-1">Baris</th>
                                        {layout.columns.map((c) => <th key={c.index} className={`px-2 py-1 ${mappedIndexes.has(c.index) ? "bg-primary-100 dark:bg-primary-900/40" : ""}`}>{c.letter}</th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {layout.grid.map((r) => {
                                        const isHeader = r.row >= header.from && r.row <= header.to;
                                        return (
                                            <tr key={r.row} className={isHeader ? "bg-warning-50 font-semibold dark:bg-warning-900/20" : ""}>
                                                <td className="px-2 py-1">
                                                    <button type="button" title="Jadikan baris judul" className="text-primary-600 hover:underline" onClick={() => setHeader(r.row < header.from || r.row > header.to + 3 ? { from: r.row, to: r.row } : { ...header, to: r.row })}>{r.row}</button>
                                                </td>
                                                {layout.columns.map((c) => <td key={c.index} className="max-w-[10rem] truncate whitespace-nowrap px-2 py-1">{r.cells[c.index] || ""}</td>)}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <p className="px-4 py-2 text-xs text-slate-500">Menampilkan 40 baris pertama dari {layout.total_rows} baris. Baris kuning = judul; kolom biru = sudah dipilih.</p>
                    </Panel>

                    <Panel title="Cocokkan kolom">
                        <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5">
                            {Object.entries(fields).map(([key, label]) => (
                                <Field key={key} label={label + (["name", "month", "year"].includes(key) ? " *" : "")}>
                                    <select className={inputBase + " w-full"} value={mapping[key] ?? ""} onChange={(e) => setMapping({ ...mapping, [key]: e.target.value === "" ? null : Number(e.target.value) })}>
                                        <option value="">— tidak ada —</option>
                                        {layout.columns.map((c) => <option key={c.index} value={c.index}>{c.letter}{c.label ? ` · ${c.label}` : ""}</option>)}
                                    </select>
                                </Field>
                            ))}
                        </div>
                        <div className="flex justify-end gap-2 p-4 pt-0">
                            <button className={btn.secondary} onClick={() => setStep(0)}>Ganti file</button>
                            <button className={btn.primary} disabled={!!busy} onClick={analyze}>Lanjut: cocokkan nama</button>
                        </div>
                    </Panel>
                </div>
            ) : step === 2 ? (
                <Panel title={`3. Cocokkan nama (${layout.rows} baris data, tahun ${layout.years.join(", ")})`}>
                    <div className="space-y-3 p-4">
                        <p className="text-sm text-slate-600 dark:text-slate-300">
                            {matched.length} nama sudah cocok otomatis. {unmatched.length ? `${unmatched.length} nama perlu dipilih — pilih pegawainya, atau "Lewati" bila bukan pegawai.` : "Semua nama sudah cocok."}
                        </p>
                        {unmatched.map((n) => (
                            <div key={n.key} className="grid items-center gap-2 sm:grid-cols-[16rem_1fr]">
                                <div><div className="font-medium">{n.name}</div><div className="text-xs text-slate-500">{n.rows} baris{n.nip ? ` · NIP ${n.nip}` : ""}</div></div>
                                <SearchSelect options={empOptions} extra={[{ value: "", label: "Lewati (tidak diimpor)" }]} value={nameMap[n.key] ?? ""} onChange={(v) => setNameMap({ ...nameMap, [n.key]: v })} placeholder="Cari pegawai…" />
                            </div>
                        ))}
                        <button type="button" className="text-sm text-primary-600 hover:underline" onClick={() => setShowMatched(!showMatched)}>{showMatched ? "Sembunyikan" : "Lihat"} nama yang sudah cocok</button>
                        {showMatched && (
                            <div className="grid gap-1 text-xs sm:grid-cols-2 lg:grid-cols-3">
                                {matched.map((n) => <div key={n.key}>{n.name} → {employees.find((e) => e.id === n.customer_id)?.name}</div>)}
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <button className={btn.secondary} onClick={() => setStep(1)}>Kembali</button>
                            <button className={btn.primary} disabled={!!busy} onClick={runPreview}>Lanjut: pratinjau</button>
                        </div>
                    </div>
                </Panel>
            ) : (
                <div className="space-y-6">
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Data baru" value={num(preview.new)} helper="langsung disimpan" />
                        <Stat label="Sama" value={num(preview.same)} helper="dilewati" />
                        <Stat label="Berbeda" value={num(preview.changed)} helper="centang untuk mengubah" />
                        <Stat label="Nama dilewati" value={num(preview.skipped_names)} />
                    </div>
                    {Object.keys(preview.new_by_type).length > 0 && (
                        <p className="text-sm text-slate-600 dark:text-slate-300">Data baru: {Object.entries(preview.new_by_type).map(([t, n]) => `${typeLabels[t]} ${num(n)}`).join(" · ")}</p>
                    )}

                    <Panel title={`Data berbeda dengan yang tersimpan (${preview.changed})`}>
                        {preview.changed === 0 ? (
                            <p className="p-4 text-sm text-slate-500">Tidak ada data yang berbeda.</p>
                        ) : (
                            <div className="max-h-[28rem] overflow-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900">
                                        <tr>
                                            <th className="px-3 py-2 text-left"><label className="flex items-center gap-2"><input type="checkbox" checked={allChecked} onChange={(e) => setConfirmed(Object.fromEntries(preview.changed_rows.map((r) => [r.key, e.target.checked])))} /> Ubah</label></th>
                                            <th className="px-3 py-2 text-left">Nama</th>
                                            <th className="px-3 py-2 text-left">Periode</th>
                                            <th className="px-3 py-2 text-left">Jenis</th>
                                            <th className="px-3 py-2 text-right">Tersimpan</th>
                                            <th className="px-3 py-2 text-right">Di file</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.changed_rows.map((r) => (
                                            <tr key={r.key} className="border-t border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-1.5"><input type="checkbox" checked={!!confirmed[r.key]} onChange={(e) => setConfirmed({ ...confirmed, [r.key]: e.target.checked })} /></td>
                                                <td className="px-3 py-1.5">{r.name}</td>
                                                <td className="px-3 py-1.5">{MONTHS[r.month - 1]} {r.year}</td>
                                                <td className="px-3 py-1.5"><Badge>{typeLabels[r.type]}</Badge></td>
                                                <td className="px-3 py-1.5 text-right">{num(r.old)}</td>
                                                <td className="px-3 py-1.5 text-right font-semibold">{num(r.new)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>

                    {preview.new_sample.length > 0 && (
                        <Panel title={`Contoh data baru (${Math.min(preview.new_sample.length, preview.new)} dari ${num(preview.new)})`}>
                            <div className="max-h-72 overflow-auto">
                                <table className="w-full text-sm">
                                    <tbody>
                                        {preview.new_sample.map((r) => (
                                            <tr key={r.key} className="border-t border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-1.5">{r.name}</td>
                                                <td className="px-3 py-1.5">{MONTHS[r.month - 1]} {r.year}</td>
                                                <td className="px-3 py-1.5">{typeLabels[r.type]}</td>
                                                <td className="px-3 py-1.5 text-right">{num(r.new)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Panel>
                    )}

                    <div className="flex justify-end gap-2">
                        <button className={btn.secondary} onClick={() => setStep(2)}>Kembali</button>
                        <button className={btn.primary} disabled={!!busy || (preview.new === 0 && !Object.values(confirmed).some(Boolean))} onClick={commit}>
                            <IconUpload size={18} /> Simpan {num(preview.new)} data baru{Object.values(confirmed).filter(Boolean).length ? ` + ${Object.values(confirmed).filter(Boolean).length} perubahan` : ""}
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}

Import.layout = (page) => <DashboardLayout children={page} />;
