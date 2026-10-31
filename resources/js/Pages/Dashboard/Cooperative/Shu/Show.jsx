import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconCalculator, IconCoins, IconDownload, IconLock, IconLockOpen, IconLink, IconPencil, IconPlus, IconTrash, IconUpload, IconX } from "@tabler/icons-react";
import Modal from "@/Components/Modal";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, Tip, PageHeader, Panel, Stat, btn, inputBase, inputClass, percent, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const MONTH_SHORT = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
// Data lama menyimpan jumlah bulan (angka) → anggap Januari s.d. bulan ke-n.
const boardMonths = (m) => (Array.isArray(m.months) ? m.months : Array.from({ length: Math.min(12, Number(m.months || 0)) }, (_, i) => i + 1));

const SETTING_KEYS = ["total_shu", "base_per_person", "base_requires_savings", "base_for_ppnpn", "base_mode", "board_amount", "board_members", "portion_savings", "portion_purchases", "portion_financing_dana", "portion_financing_barang", "savings_types", "savings_period", "sales_source", "rounding", "rounding_unit", "excluded_customer_ids"];

export default function Show({ period, allocations, adjustments, rules, stats, unmatched, employees, typeLabels, buyersWithoutDues = [], boardFromDecrees = [] }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const locked = period.status === "final";
    const editable = can("shu-manage") && !locked;
    const empty = (k) => (k.endsWith("ids") || k === "board_members" ? [] : "");
    const [form, setForm] = useState(() => {
        const initial = Object.fromEntries(SETTING_KEYS.map((k) => [k, period[k] ?? empty(k)]));
        return { ...initial, board_members: initial.board_members.map((m) => ({ ...m, months: boardMonths(m) })) };
    });
    const setBoard = (i, patch) => setForm({ ...form, board_members: form.board_members.map((x, j) => (j === i ? { ...x, ...patch } : x)) });
    const positionById = useMemo(() => Object.fromEntries(boardFromDecrees.map((m) => [m.customer_id, m.position])), [boardFromDecrees]);
    const loadBoardFromDecrees = () => setForm({ ...form, board_members: boardFromDecrees.map((m) => ({ customer_id: m.customer_id, months: m.months })) });
    const toggleMonth = (i, month) => {
        const months = form.board_members[i].months;
        setBoard(i, { months: months.includes(month) ? months.filter((x) => x !== month) : [...months, month].sort((a, b) => a - b) });
    };
    const [errors, setErrors] = useState({});
    const [excludeId, setExcludeId] = useState("");
    const salesUpload = useForm({ file: null });
    const [mapChoice, setMapChoice] = useState({});
    const [adjust, setAdjust] = useState(null);
    const adjustmentByCustomer = useMemo(() => Object.fromEntries(adjustments.map((a) => [a.customer_id, a])), [adjustments]);
    const openAdjust = (customerId) => {
        const a = adjustmentByCustomer[customerId];
        setAdjust({ customer_id: customerId || "", base_override: a?.base_override ?? "", amount: a?.amount || "", note: a?.note || "" });
    };
    const saveAdjust = (e) => {
        e.preventDefault();
        router.post(route("shu.adjustments.save", period.id), { ...adjust, base_override: adjust.base_override === "" ? null : adjust.base_override }, { preserveScroll: true, onSuccess: () => setAdjust(null) });
    };

    const portionTotal = ["portion_savings", "portion_purchases", "portion_financing_dana", "portion_financing_barang"].reduce((sum, k) => sum + Number(form[k] || 0), 0);
    const businessTotal = ["portion_purchases", "portion_financing_dana", "portion_financing_barang"].reduce((sum, k) => sum + Number(form[k] || 0), 0);
    const boardTotal = (form.board_members || []).reduce((sum, m) => sum + Number(form.board_amount || 0) * m.months.length, 0);
    const boardPerMonth = MONTH_SHORT.map((_, i) => (form.board_members || []).filter((m) => m.customer_id && m.months.includes(i + 1)).length);
    const remainder = Number(form.total_shu || 0) - stats.base_total - boardTotal - stats.adjustment_total;
    const employeeById = useMemo(() => Object.fromEntries(employees.map((e) => [e.id, e])), [employees]);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    const dirty = SETTING_KEYS.some((k) => JSON.stringify(form[k]) !== JSON.stringify(period[k] ?? empty(k)));


    const money = (key, label, shareKey, extra = {}) => ({
        key, label, align: "right", filter: "number", sum: true,
        value: (a) => Number(a[key] || 0),
        render: (a) => <>{rupiah(a[key])}{shareKey && <div className="text-xs text-slate-500">{percent(a[shareKey])}</div>}</>,
        ...extra,
    });
    const resultColumns = [
        {
            key: "name", label: "Nama", filter: "text",
            value: (a) => `${a.customer?.name || ""} ${a.customer?.nip || ""}`,
            render: (a) => (
                <>
                    <div className="font-medium text-slate-900 dark:text-white">{a.customer?.name}</div>
                    <div className="text-xs text-slate-500"><span className="font-mono">{a.customer?.nip || "tanpa NIP"}</span> · {a.customer?.employment_status}</div>
                </>
            ),
        },
        { key: "type", label: "Jenis", filter: "select", options: ["PNS", "PPPK", "PPNPN", "LAINNYA"].map((v) => ({ value: v, label: v })), value: (a) => a.customer?.employee_type || "" },
        money("savings_amount", "Iuran", "savings_share"),
        money("purchase_amount", "Belanja", "purchase_share"),
        ...(period.portion_financing_dana > 0 ? [money("financing_dana_amount", "Pemb. dana", "financing_dana_share")] : []),
        ...(period.portion_financing_barang > 0 ? [money("financing_barang_amount", "Pemb. barang", "financing_barang_share")] : []),
        money("base_amount", "SHU dasar", null, {
            render: (a) => <>{rupiah(a.base_amount)}{period.base_mode === "monthly" && <div className="text-xs text-slate-500">{a.months_paid} bln</div>}{adjustmentByCustomer[a.customer_id]?.base_override != null && <div className="text-xs text-warning-700">manual</div>}</>,
        }),
        ...(stats.board_total > 0 ? [money("board_amount", "SHU Pengurus Koperasi")] : []),
        money("shu_savings", "SHU iuran"),
        money("shu_purchases", "SHU belanja"),
        ...(period.portion_financing_dana > 0 ? [money("shu_financing_dana", "SHU dana")] : []),
        ...(period.portion_financing_barang > 0 ? [money("shu_financing_barang", "SHU barang")] : []),
        money("adjustment_amount", "Koreksi", null, {
            render: (a) => <>{a.adjustment_amount ? rupiah(a.adjustment_amount) : "-"}{a.adjustment_note && <div className="max-w-[10rem] truncate text-xs text-slate-500" title={a.adjustment_note}>{a.adjustment_note}</div>}</>,
        }),
        money("total", "Total"),
        money("total_rounded", "Dibulatkan", null, { render: (a) => <span className="font-semibold text-slate-900 dark:text-white">{rupiah(a.total_rounded)}</span> }),
        ...(editable ? [{ key: "edit", label: "", value: () => "", render: (a) => <button onClick={() => openAdjust(a.customer_id)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" title="Koreksi manual"><IconPencil size={16} /></button> }] : []),
    ];

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
                title={<>SHU Tahun Buku {period.year} {locked ? <Badge color="green">Final</Badge> : <Badge color="amber">Draf</Badge>}</>}
                subtitle={`Dibagikan pada tahun ${period.year + 1}.${period.calculated_at ? " Terakhir dihitung " + new Date(period.calculated_at).toLocaleString("id-ID") + "." : " Belum dihitung."}`}
                actions={
                    <>
                        <Link href={route("shu.index")} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>
                        {allocations.length > 0 && <a href={route("shu.export", period.id)} className={btn.secondary}><IconDownload size={18} /> Ekspor Excel</a>}
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
                                <Field label="Jumlah SHU yang dibagi" error={errors.total_shu} tip="Total uang SHU tahun buku ini yang akan dibagikan ke anggota (sesuai keputusan RAT). Semua bagian di bawah diambil dari angka ini.">
                                    <MoneyInput value={form.total_shu} onChange={(v) => setForm({ ...form, total_shu: v })} />
                                </Field>
                                <Field label="SHU dasar per orang" error={errors.base_per_person} tip="Nominal yang sama untuk setiap penerima SHU dasar, sebelum sisa SHU dibagi berdasarkan iuran, belanja, dan pembiayaan." hint={`Pengaturan tersimpan: ${stats.base_recipients} orang dapat SHU dasar → total ${rupiah(stats.base_total)}`}>
                                    <MoneyInput value={form.base_per_person} onChange={(v) => setForm({ ...form, base_per_person: v })} />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Siapa dapat SHU dasar" error={errors.base_requires_savings} tip="Hanya pembayar iuran: anggota yang membayar pokok/wajib/sukarela di tahun buku. Semua pegawai aktif yang ikut: termasuk yang hanya belanja atau ikut pembiayaan tanpa iuran." hint={form.base_requires_savings ? "Belanja tanpa iuran tetap dapat bagian Belanja Toko, tanpa SHU dasar." : "Siapa pun yang ikut (iuran/belanja/pembiayaan) dapat SHU dasar."}>
                                    <select className={inputClass} value={form.base_requires_savings ? "1" : "0"} onChange={(e) => setForm({ ...form, base_requires_savings: e.target.value === "1" })}>
                                        <option value="1">Hanya yang membayar simpanan/iuran</option>
                                        <option value="0">Semua pegawai aktif yang ikut</option>
                                    </select>
                                </Field>
                                <Field label="PPNPN dapat SHU dasar?" error={errors.base_for_ppnpn} tip="Ya: PPNPN diperlakukan sama seperti pegawai lain untuk SHU dasar (tetap mengikuti aturan siapa yang dapat SHU dasar). Tidak: PPNPN hanya dapat bagian iuran/belanja/pembiayaan.">
                                    <select className={inputClass} value={form.base_for_ppnpn ? "1" : "0"} onChange={(e) => setForm({ ...form, base_for_ppnpn: e.target.value === "1" })}>
                                        <option value="1">Ya, PPNPN dapat SHU dasar</option>
                                        <option value="0">Tidak</option>
                                    </select>
                                </Field>
                                <Field label="Besaran SHU dasar" error={errors.base_mode} tip="Penuh: setiap penerima dapat nominal penuh. Proporsional: nominal × (jumlah bulan membayar iuran ÷ 12), cocok untuk yang masuk/keluar di tengah tahun.">
                                    <select className={inputClass} value={form.base_mode} onChange={(e) => setForm({ ...form, base_mode: e.target.value })}>
                                        <option value="full">Penuh per orang</option>
                                        <option value="monthly">Proporsional bulan membayar (÷12)</option>
                                    </select>
                                </Field>
                            </div>
                            <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                <div className="mb-2 flex flex-wrap items-end gap-3">
                                    <Field label="SHU Pengurus Koperasi per orang per bulan" error={errors.board_amount} tip="Tambahan SHU untuk pengurus koperasi, dihitung per orang per bulan bertugas. Centang bulan bertugas tiap pengurus, karena susunan pengurus bisa berganti di tengah tahun. SHU pengurus = nilai per bulan × jumlah bulan bertugas. Dipotong dari SHU yang dibagi sebelum sisa dibagi. Contoh 2023: Rp 300.000 per 6 bulan = Rp 50.000 per bulan.">
                                        <MoneyInput className="w-56" value={form.board_amount} onChange={(v) => setForm({ ...form, board_amount: v })} disabled={!editable} />
                                    </Field>
                                    <span className="pb-2 text-sm text-slate-500">Total SHU pengurus koperasi: {rupiah(boardTotal)}</span>
                                </div>
                                <div className="space-y-3">
                                    {(form.board_members || []).map((m, i) => (
                                        <div key={i} className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <SearchSelect className="min-w-[16rem] flex-1" options={empOptions} value={m.customer_id} onChange={(v) => setBoard(i, { customer_id: Number(v) })} placeholder="Cari pengurus…" disabled={!editable} />
                                                {positionById[m.customer_id] && <Badge color="blue">{positionById[m.customer_id]}</Badge>}
                                                <span className="text-xs text-slate-500">{m.months.length} bulan × {rupiah(form.board_amount || 0)} = <b>{rupiah(Number(form.board_amount || 0) * m.months.length)}</b></span>
                                                {editable && <button type="button" onClick={() => setForm({ ...form, board_members: form.board_members.filter((_, j) => j !== i) })} className="text-danger-600" title="Hapus"><IconX size={16} /></button>}
                                            </div>
                                            <div className="flex flex-wrap gap-1">
                                                {MONTH_SHORT.map((label, k) => (
                                                    <button
                                                        type="button"
                                                        key={label}
                                                        disabled={!editable}
                                                        onClick={() => toggleMonth(i, k + 1)}
                                                        className={`w-11 rounded-md border py-0.5 text-xs ${m.months.includes(k + 1) ? "border-primary-500 bg-primary-500 text-white" : "border-slate-200 text-slate-500 dark:border-slate-700"}`}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                                {editable && (
                                                    <button type="button" onClick={() => setBoard(i, { months: m.months.length === 12 ? [] : MONTH_SHORT.map((_, k) => k + 1) })} className="ml-1 text-xs text-primary-600 hover:underline">
                                                        {m.months.length === 12 ? "Kosongkan" : "Setahun penuh"}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    {form.board_members?.length > 0 && (
                                        <p className="text-xs text-slate-500">
                                            Jumlah pengurus per bulan: {MONTH_SHORT.map((label, k) => `${label} ${boardPerMonth[k]}`).join(" · ")}
                                        </p>
                                    )}
                                    {editable && (
                                        <div className="flex flex-wrap items-center gap-4">
                                            <button type="button" onClick={() => setForm({ ...form, board_members: [...(form.board_members || []), { customer_id: "", months: MONTH_SHORT.map((_, k) => k + 1) }] })} className="text-sm text-primary-600 hover:underline">+ Tambah pengurus</button>
                                            {boardFromDecrees.length > 0 ? (
                                                <button type="button" onClick={loadBoardFromDecrees} className="text-sm text-primary-600 hover:underline">
                                                    Ambil dari SK pengurus ({boardFromDecrees.length} orang)
                                                </button>
                                            ) : (
                                                <Link href={route("board.index")} className="text-sm text-slate-500 hover:underline">Belum ada SK pengurus untuk {period.year} — upload di menu Pengurus Koperasi</Link>
                                            )}
                                            <Tip text="Mengisi daftar pengurus dan bulan bertugas dari SK di menu Pengurus Koperasi. Bulan dihitung dari masa berlaku SK: SK yang berlaku di akhir bulan itu yang dipakai. Hapus yang tidak berhak (mis. pengawas) bila perlu, lalu Simpan." />
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div>
                                <p className="mb-1 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Porsi sisa SHU<Tip text="Sisa = SHU yang dibagi − SHU dasar − SHU pengurus koperasi − koreksi manual. Sisa ini dibagi ke 4 bagian sesuai persentase; total harus 100%." /> ({rupiah(Math.max(remainder, 0))}) — total{" "}
                                    <span className={Math.abs(portionTotal - 100) > 0.001 ? "text-danger-600" : "text-success-700"}>{portionTotal}%</span>
                                    {Math.abs(portionTotal - 100) > 0.001 && <span className="text-danger-600"> (harus 100%)</span>}
                                    <span className="text-slate-500"> · jasa usaha (belanja + pembiayaan) {businessTotal}%</span>
                                </p>
                                <div className="grid gap-4 sm:grid-cols-4">
                                    <Field label="Iuran Koperasi" error={errors.portion_savings} hint="pokok + wajib + sukarela" tip="Bagian ini dibagi sebanding jumlah iuran (simpanan pokok + wajib + sukarela) tiap anggota dibanding total iuran semua anggota.">
                                        <PercentInput value={form.portion_savings} onChange={(v) => setForm({ ...form, portion_savings: v })} />
                                    </Field>
                                    <Field label="Belanja Toko" error={errors.portion_purchases} hint="total belanja" tip="Bagian ini dibagi sebanding total belanja tiap pegawai di toko koperasi (POS atau impor Excel) dibanding total belanja semua pegawai.">
                                        <PercentInput value={form.portion_purchases} onChange={(v) => setForm({ ...form, portion_purchases: v })} />
                                    </Field>
                                    <Field label="Pembiayaan Dana" error={errors.portion_financing_dana} hint="biaya formulir" tip="Bagian ini dibagi sebanding biaya formulir yang dibayar tiap pegawai untuk pembiayaan dana yang dicairkan di tahun buku ini. Cicilan pokoknya tidak dihitung.">
                                        <PercentInput value={form.portion_financing_dana} onChange={(v) => setForm({ ...form, portion_financing_dana: v })} />
                                    </Field>
                                    <Field label="Pembiayaan Barang" error={errors.portion_financing_barang} hint="bagi hasil + biaya lain" tip="Bagian ini dibagi sebanding bagi hasil (dari cicilan yang dibayar di tahun buku) + biaya lain saat pencairan, per pegawai. Cicilan pokoknya tidak dihitung.">
                                        <PercentInput value={form.portion_financing_barang} onChange={(v) => setForm({ ...form, portion_financing_barang: v })} />
                                    </Field>
                                </div>
                                {remainder < 0 && <p className="mt-1 text-sm text-danger-600">SHU dasar + SHU pengurus koperasi + koreksi melebihi SHU yang dibagi.</p>}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Iuran yang dihitung" error={errors.savings_types} tip="Jenis iuran yang dijumlahkan untuk bagian Iuran Koperasi. Normalnya ketiganya dicentang.">
                                    <div className="flex flex-wrap gap-3 pt-1">
                                        {["pokok", "wajib", "sukarela"].map((t) => (
                                            <label key={t} className="flex items-center gap-1 text-sm">
                                                <input type="checkbox" checked={form.savings_types.includes(t)} onChange={() => toggleType(t)} /> {typeLabels[t]}
                                            </label>
                                        ))}
                                    </div>
                                </Field>
                                <Field label="Periode iuran" error={errors.savings_period} tip="Tahun buku saja: hanya iuran yang dibayar di tahun buku. Sejak awal: saldo iuran yang terkumpul sejak awal menjadi anggota s.d. tahun buku (yang lebih lama jadi anggota mendapat lebih).">
                                    <select className={inputClass} value={form.savings_period} onChange={(e) => setForm({ ...form, savings_period: e.target.value })}>
                                        <option value="year">Selama tahun buku {period.year} saja</option>
                                        <option value="all_time">Sejak awal s.d. {period.year}</option>
                                    </select>
                                </Field>
                                <Field label="Sumber data belanja" error={errors.sales_source} tip="POS: transaksi kasir di aplikasi ini. Impor Excel: rekap penjualan dari file Excel (untuk tahun sebelum POS dipakai).">
                                    <select className={inputClass} value={form.sales_source} onChange={(e) => setForm({ ...form, sales_source: e.target.value })}>
                                        <option value="pos">Penjualan aplikasi (POS)</option>
                                        <option value="excel">Impor Excel</option>
                                    </select>
                                </Field>
                                <Field label="Pembulatan" error={errors.rounding || errors.rounding_unit} tip="Pembulatan nilai SHU per orang untuk uang yang disiapkan. Terdekat/ke atas/ke bawah ke kelipatan yang dipilih.">
                                    <div className="flex gap-2">
                                        <select className={inputClass} value={form.rounding} onChange={(e) => setForm({ ...form, rounding: e.target.value })}>
                                            <option value="round">Terdekat</option>
                                            <option value="ceil">Ke atas</option>
                                            <option value="floor">Ke bawah</option>
                                            <option value="none">Tidak dibulatkan</option>
                                        </select>
                                        <select className={inputClass} value={form.rounding_unit} onChange={(e) => setForm({ ...form, rounding_unit: Number(e.target.value) })} disabled={form.rounding === "none"}>
                                            {[100, 500, 1000, 5000, 10000].map((u) => <option key={u} value={u}>{rupiah(u)}</option>)}
                                        </select>
                                    </div>
                                </Field>
                            </div>
                            {buyersWithoutDues.length > 0 && (
                                <div className="rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm dark:border-warning-900/40 dark:bg-warning-900/10">
                                    <p className="mb-2 font-medium text-warning-800 dark:text-warning-300">
                                        Belanja di {period.year} tetapi tidak tercatat iuran di {period.year} ({buyersWithoutDues.length} orang)
                                        <Tip text="Pegawai aktif yang namanya ada di data belanja tahun ini tetapi tidak ada di data iuran tahun ini. Mereka tetap mendapat bagian Belanja Toko (tanpa SHU dasar bila SHU dasar hanya untuk pembayar iuran). Kecualikan bila tidak berhak, atau cocokkan namanya di menu Pencocokan Nama bila ternyata salah ejaan." />
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {buyersWithoutDues.map((b) => {
                                            const out = form.excluded_customer_ids.includes(b.customer_id);
                                            return (
                                                <span key={b.customer_id} className={`inline-flex items-center gap-2 rounded-lg px-2 py-1 text-xs ${out ? "bg-slate-200 line-through dark:bg-slate-700" : "bg-white dark:bg-slate-900"}`}>
                                                    {b.name}{b.employee_type === "PPNPN" ? " · PPNPN" : ""} · {rupiah(b.amount)}
                                                    {editable && (
                                                        <button type="button" className="text-primary-600 hover:underline" onClick={() => setForm({ ...form, excluded_customer_ids: out ? form.excluded_customer_ids.filter((x) => x !== b.customer_id) : [...form.excluded_customer_ids, b.customer_id] })}>
                                                            {out ? "batal" : "kecualikan"}
                                                        </button>
                                                    )}
                                                </span>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                            <Field label="Dikecualikan dari SHU" error={errors.excluded_customer_ids} tip="Pegawai di daftar ini tidak mendapat SHU sama sekali (mis. akun kantor, pengurus yang sudah mendapat SHU pengurus koperasi, atau keputusan rapat).">
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
                                        <SearchSelect className="flex-1" options={empOptions.filter((o) => !form.excluded_customer_ids.includes(o.value))} value={excludeId} onChange={(v) => setExcludeId(v)} placeholder="Cari pegawai yang dikecualikan…" />
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
                            <button type="submit" className={btn.primary} disabled={!salesUpload.data.file || salesUpload.processing}><IconUpload size={18} /> {salesUpload.processing ? "Mengimpor..." : "Impor penjualan"}</button>
                        </form>
                    )}
                    <p className="px-4 pb-4 text-xs text-slate-500">Kolom: TGL BELANJA, NAMA PEGAWAI (atau "Nama Final"), TOTAL HARGA. Impor menimpa data Excel tahun {period.year} sebelumnya.</p>
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
                                                    <SearchSelect className="w-64" disabled={!editable} options={empOptions} value={mapChoice[u.buyer_name] || ""} onChange={(v) => setMapChoice({ ...mapChoice, [u.buyer_name]: String(v) })} placeholder="Cari pegawai…" />
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
                <Stat label="Penerima SHU" value={allocations.length} helper={`${stats.active} pegawai aktif · ${stats.base_recipients} dapat SHU dasar`} />
                <Stat label="Belanja toko" value={rupiah(stats.purchase_total)} helper={`${stats.purchase_buyers} pembeli · sumber ${period.sales_source === "excel" ? "Excel" : "POS"} · pendapatan pembiayaan: dana ${rupiah(stats.financing_dana_total)}, barang ${rupiah(stats.financing_barang_total)}`} />
                <Stat label="Total terbagi" value={rupiah(stats.distributed)} helper={period.total_shu ? `dari ${rupiah(period.total_shu)}` : ""} />
                <Stat label="Total dibulatkan" value={rupiah(stats.distributed_rounded)} helper="jumlah uang yang perlu disiapkan" />
            </div>

            <Panel
                title={`Hasil perhitungan (${allocations.length} orang)`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Link href={route("name-links.index", { shu_year: period.year })} className={btn.secondary}><IconLink size={18} /> Pencocokan nama</Link>
                        {editable && <button onClick={() => openAdjust(null)} className={btn.secondary}><IconPlus size={18} /> Koreksi / penerima manual</button>}
                    </div>
                }
            >
                <SortableTable
                    key={`${period.id}-${period.calculated_at}`}
                    rows={allocations}
                    rowKey={(a) => a.id}
                    columns={resultColumns}
                    initialSort={{ key: "total_rounded", dir: "desc" }}
                    emptyText="Belum dihitung. Isi pengaturan, simpan, lalu klik “Hitung SHU”."
                    footer={(list) => (
                        <tr>
                            {resultColumns.map((c, i) => (
                                <td key={c.key} className={`${td} ${c.align === "right" ? "text-right" : ""}`}>
                                    {i === 0 ? "Total" : c.sum ? rupiah(list.reduce((s, r) => s + Number(c.value(r) || 0), 0)) : ""}
                                </td>
                            ))}
                        </tr>
                    )}
                />
            </Panel>

            {adjustments.length > 0 && (
                <p className="mt-3 text-sm text-slate-500">
                    {adjustments.length} koreksi manual tersimpan: {adjustments.map((a) => a.customer?.name).join(", ")}. Koreksi tetap berlaku saat dihitung ulang.
                </p>
            )}

            <Modal show={adjust !== null} onClose={() => setAdjust(null)}>
                {adjust && (
                    <form onSubmit={saveAdjust} className="space-y-4 p-6">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Koreksi manual SHU</h2>
                        <p className="text-sm text-slate-500">Dipotong dari SHU yang dibagi sebelum bagian iuran/belanja/pembiayaan dihitung. Kosongkan kedua nilai untuk menghapus koreksi.</p>
                        <Field label="Pegawai">
                            <SearchSelect required options={empOptions} value={adjust.customer_id} onChange={(v) => { const id = Number(v); const a = adjustmentByCustomer[id]; setAdjust({ customer_id: id, base_override: a?.base_override ?? "", amount: a?.amount || "", note: a?.note || "" }); }} placeholder="Cari pegawai…" />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="SHU dasar pengganti" hint="Kosong = ikut aturan. Isi 0 untuk meniadakan SHU dasar." tip="Mengganti SHU dasar orang ini saja, misalnya karena keputusan rapat.">
                                <MoneyInput value={adjust.base_override} onChange={(v) => setAdjust({ ...adjust, base_override: v })} />
                            </Field>
                            <Field label="Tambahan / pengurangan" hint="mis. SHU pengurus koperasi 300000, atau -50000">
                                <MoneyInput value={adjust.amount} onChange={(v) => setAdjust({ ...adjust, amount: v })} />
                            </Field>
                        </div>
                        <Field label="Catatan">
                            <input className={inputClass} value={adjust.note} onChange={(e) => setAdjust({ ...adjust, note: e.target.value })} placeholder="mis. SHU pengurus koperasi 2025" />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setAdjust(null)} className={btn.secondary}>Batal</button>
                            <button type="submit" className={btn.primary}>Simpan koreksi</button>
                        </div>
                    </form>
                )}
            </Modal>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
