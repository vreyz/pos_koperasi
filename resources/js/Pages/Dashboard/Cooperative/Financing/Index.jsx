import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconAlertTriangle, IconChecklist, IconCash, IconDownload, IconPlus, IconPrinter, IconSettings, IconUpload } from "@tabler/icons-react";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputClass, rupiah, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput } from "@/Components/Cooperative/inputs";

export const STATUS_COLOR = { pengajuan: "gray", diperiksa: "blue", direview: "blue", disetujui: "amber", berjalan: "green", lunas: "gray", ditolak: "red" };

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" }) : "-");

// Aturan terpisah per jenis pembiayaan. Nama/alamat/kota koperasi dari Profil Toko, Ketua & Kepala Unit Usaha dari SK Pengurus.
const SETTING_FIELDS = {
    dana: [
        ["financing_fee_pegawai", "Biaya formulir pegawai per Rp 5 jt pembiayaan", "money"],
        ["financing_fee_ppnpn", "Biaya formulir PPNPN per Rp 5 jt pembiayaan", "money"],
        ["financing_max_pegawai", "Plafon pegawai", "money"],
        ["financing_max_ppnpn", "Plafon PPNPN", "money"],
        ["financing_tenor_dana_max", "Jangka waktu bawaan & maksimal (bulan)", "int"],
        ["coop_bank", "Rekening pembayaran biaya formulir", "text"],
        ["coop_contact_pegawai", "Kontak konfirmasi pembayaran (pegawai)", "text"],
        ["coop_contact_ppnpn", "Kontak konfirmasi pembayaran (PPNPN)", "text"],
    ],
    barang: [
        ["financing_margin_barang", "Bagi hasil", "percent"],
        ["financing_admin_barang", "Biaya lain (dipotong saat pencairan)", "percent"],
        ["financing_tenor_barang", "Jangka waktu bawaan (bulan)", "int"],
    ],
};

export default function Index({ type, typeLabel, financings, statusLabels, summary, settings, templates = {} }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [showSettings, setShowSettings] = useState(false);
    const fields = SETTING_FIELDS[type];
    const [form, setForm] = useState(() => Object.fromEntries(fields.map(([key]) => [key, settings[key]])));
    const [errors, setErrors] = useState({});

    const columns = [
        {
            key: "name",
            label: "Pemohon",
            filter: "text",
            value: (f) => `${f.customer?.name} ${f.customer?.nip || ""} ${f.number || ""}`,
            render: (f) => (
                <Link href={route("financing.show", f.id)} className="block">
                    <span className="font-medium text-primary-600 hover:underline">{f.customer?.name}</span>
                    <span className="ml-1">{f.customer?.employee_type === "PPNPN" && <Badge color="blue">PPNPN</Badge>}</span>
                    <div className="text-xs text-slate-500">{[f.number, type === "barang" ? f.item_name : null].filter(Boolean).join(" · ") || f.customer?.nip}</div>
                </Link>
            ),
        },
        { key: "disbursed_at", label: "Tgl cair", value: (f) => f.disbursed_at || f.applied_at || "", render: (f) => fmtDate(f.disbursed_at) },
        { key: "principal", label: type === "barang" ? "Harga barang" : "Nominal", align: "right", filter: "number", value: (f) => f.principal, render: (f) => rupiah(f.principal) },
        { key: "income", label: type === "barang" ? "Bagi hasil + biaya" : "Biaya formulir", align: "right", filter: "number", value: (f) => f.admin_fee + f.margin_amount, render: (f) => rupiah(f.admin_fee + f.margin_amount) },
        { key: "installment", label: "Cicilan / bln", align: "right", value: (f) => f.installment, render: (f) => <>{rupiah(f.installment)}<div className="text-xs text-slate-500">{f.tenor} bulan</div></> },
        { key: "paid", label: "Terbayar", align: "right", value: (f) => (f.tenor ? f.paid_count / f.tenor : 0), render: (f) => (f.status === "berjalan" || f.status === "lunas" ? `${f.paid_count}/${f.tenor}` : "-") },
        { key: "outstanding", label: "Sisa", align: "right", filter: "number", value: (f) => Number(f.outstanding || 0), render: (f) => (f.outstanding ? <span className="font-semibold">{rupiah(f.outstanding)}</span> : "-") },
        {
            key: "status",
            label: "Status",
            filter: "select",
            options: [...Object.entries(statusLabels).map(([value, label]) => ({ value, label })), { value: "macet", label: "Macet" }],
            value: (f) => (f.is_bad_debt && f.status === "berjalan" ? "macet" : f.status),
            render: (f) => (
                <div className="flex flex-wrap gap-1">
                    <Badge color={STATUS_COLOR[f.status]}>{statusLabels[f.status]}</Badge>
                    {f.is_bad_debt && f.status === "berjalan" && <Badge color="red">Macet</Badge>}
                    {f.customer?.active_until && f.status === "berjalan" && <Badge color="amber">Mutasi – wajib lunas</Badge>}
                </div>
            ),
        },
    ];

    const saveSettings = (e) => {
        e.preventDefault();
        router.put(route("financing.settings", type), form, { preserveScroll: true, onError: setErrors, onSuccess: () => setShowSettings(false) });
    };

    return (
        <>
            <Head title={typeLabel} />
            <PageHeader
                icon={IconCash}
                title={typeLabel}
                subtitle={
                    type === "dana"
                        ? `Pinjaman uang dicicil maks. ${settings.financing_tenor_dana_max} bulan lewat potong gaji. Plafon pegawai ${rupiah(settings.financing_max_pegawai)}, PPNPN ${rupiah(settings.financing_max_ppnpn)}. Pendapatan koperasi: biaya formulir.`
                        : `Barang dibelikan koperasi, dicicil (bawaan ${settings.financing_tenor_barang} bulan, bisa dilunasi lebih cepat). Bagi hasil ${settings.financing_margin_barang}% + biaya lain ${settings.financing_admin_barang}%.`
                }
                actions={
                    <>
                        {type === "dana" ? (
                            <>
                                <a href={route("financing.blank-form", "dana")} target="_blank" className={btn.secondary}><IconPrinter size={18} /> Formulir pegawai</a>
                                <a href={route("financing.blank-form", { type: "dana", ppnpn: 1 })} target="_blank" className={btn.secondary}><IconPrinter size={18} /> Formulir PPNPN</a>
                            </>
                        ) : (
                            <a href={route("financing.blank-form", "barang")} target="_blank" className={btn.secondary}><IconPrinter size={18} /> Formulir kosong</a>
                        )}
                        <Link href={route("financing.reconcile")} className={btn.secondary}><IconChecklist size={18} /> Cek angsuran</Link>
                        <a href={route("financing.export", type)} className={btn.secondary} title="Bisa diedit lalu diunggah ulang di Impor Excel: kontrak yang sama diperbarui, tidak dobel."><IconDownload size={18} /> Unduh Excel</a>
                        {can("financing-import") && <Link href={route("financing.import")} className={btn.secondary}><IconUpload size={18} /> Impor Excel</Link>}
                        {can("financing-approve") && <button onClick={() => setShowSettings(!showSettings)} className={btn.secondary}><IconSettings size={18} /> Aturan</button>}
                        {can("financing-create") && <Link href={route("financing.create", type)} className={btn.primary}><IconPlus size={18} /> Pengajuan baru</Link>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Berjalan" value={summary.active} />
                <Stat label="Sisa piutang" value={rupiah(summary.outstanding)} helper="belum dibayar" />
                <Stat label="Macet" value={summary.bad_debt} />
                <Stat label="Menunggu proses" value={summary.waiting} helper="pengajuan s.d. siap cair" />
                <Stat label={`Pendapatan ${new Date().getFullYear()}`} value={rupiah(summary.income_year)} helper={type === "dana" ? "biaya formulir" : "bagi hasil + biaya lain"} />
            </div>

            {showSettings && (
                <Panel title={`Aturan ${typeLabel} (dipakai di formulir & perhitungan)`} className="mb-6">
                    <form onSubmit={saveSettings} className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {fields.map(([key, label, kind]) => (
                            <Field key={key} label={label} error={errors[key]}>
                                {kind === "money" ? (
                                    <MoneyInput value={form[key]} onChange={(v) => setForm({ ...form, [key]: v })} />
                                ) : kind === "percent" ? (
                                    <PercentInput value={form[key]} onChange={(v) => setForm({ ...form, [key]: v })} />
                                ) : (
                                    <input className={inputClass} value={form[key] ?? ""} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
                                )}
                            </Field>
                        ))}
                        <div className="space-y-2 rounded-xl border border-slate-200 p-3 sm:col-span-2 lg:col-span-3 dark:border-slate-700">
                            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">Template formulir Word (milik koperasi)</p>
                            <p className="text-xs text-slate-500">Unggah formulir .docx koperasi sekali. Setelah pengajuan disimpan, tombol "Unduh formulir (Word)" mengisi data pegawai ke template ini — kop surat dan syarat & ketentuan tetap seperti file Anda. File disimpan di komputer ini saja.</p>
                            {Object.entries(templates).map(([key, t]) => (
                                <div key={key} className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="w-64 font-medium">{t.label}</span>
                                    {t.file ? (
                                        <>
                                            <a href={route("financing.templates.download", key)} className="text-primary-600 hover:underline">{t.file}</a>
                                            <span className="text-xs text-slate-500">({t.uploaded_at})</span>
                                            <button type="button" className="text-xs text-danger-600 hover:underline" onClick={() => router.delete(route("financing.templates.delete", key), { preserveScroll: true })}>hapus</button>
                                        </>
                                    ) : (
                                        <span className="text-xs text-warning-600">belum diunggah</span>
                                    )}
                                    <label className="cursor-pointer text-xs text-primary-600 hover:underline">
                                        {t.file ? "ganti" : "unggah .docx"}
                                        <input type="file" accept=".docx" className="hidden" onChange={(e) => e.target.files[0] && router.post(route("financing.templates.upload", key), { file: e.target.files[0] }, { forceFormData: true, preserveScroll: true, onError: (err) => setErrors(err) })} />
                                    </label>
                                </div>
                            ))}
                            {errors.file && <p className="text-xs text-danger-600">{errors.file}</p>}
                        </div>
                        <p className="text-xs text-slate-500 sm:col-span-2 lg:col-span-3">
                            Nama, alamat, dan kota koperasi di formulir diambil dari Pengaturan → Profil Toko. Nama Ketua dan Kepala Unit Usaha diambil dari SK di menu Pengurus Koperasi.
                        </p>
                        <div className="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                            <button type="button" onClick={() => setShowSettings(false)} className={btn.secondary}>Batal</button>
                            <button type="submit" className={btn.primary}>Simpan aturan</button>
                        </div>
                    </form>
                </Panel>
            )}

            <Panel title={`Daftar ${typeLabel.toLowerCase()} (${financings.length})`}>
                {summary.bad_debt > 0 && (
                    <p className="flex items-center gap-2 px-4 pt-3 text-sm text-danger-600"><IconAlertTriangle size={16} /> {summary.bad_debt} pembiayaan macet — cek di menu Cek angsuran.</p>
                )}
                <SortableTable
                    rows={financings}
                    rowKey={(f) => f.id}
                    columns={columns}
                    initialSort={{ key: "disbursed_at", dir: "desc" }}
                    emptyText="Belum ada data. Buat pengajuan baru atau impor dari Excel."
                />
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
