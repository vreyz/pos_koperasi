import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import SortableTable from "@/Components/Cooperative/SortableTable";
import Modal from "@/Components/Modal";
import { IconCalendarPlus, IconDownload, IconPigMoney, IconPlus, IconTrash, IconUpload } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, MONTHS, PageHeader, Panel, Stat, btn, inputBase, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const SOURCE_LABELS = { manual: "Manual", potong_gaji: "Potong gaji", tunai: "Tunai", transfer: "Transfer", import: "Impor Excel" };

export default function Index({ tab, year, years, filters, recap, history, totals, payers, typeLabels, employees }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [merged, setMerged] = useState(false);
    const [detail, setDetail] = useState(null);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    const [modal, setModal] = useState(null);
    const [errors, setErrors] = useState({});
    const now = new Date();
    const [entry, setEntry] = useState({ customer_id: "", type: "sukarela", period_year: year, period_month: now.getMonth() + 1, direction: "in", amount: "", source: "tunai", notes: "" });
    const [gen, setGen] = useState({ type: "wajib", period_year: now.getFullYear(), period_month: now.getMonth() + 1, amount: 100000, employee_types: ["PNS", "PPPK"] });

    const go = (changes) => router.get(route("savings.index"), { tab, year, ...filters, ...changes }, { preserveState: true, replace: true });
    const savingsTotal = ["pokok", "wajib", "sukarela"].reduce((s, t) => s + Number(totals[t] || 0), 0);
    const num = (v) => (v ? Math.round(v).toLocaleString("id-ID") : "");
    const typeOptions = Object.entries(typeLabels).map(([value, label]) => ({ value, label }));

    // Riwayat: baris per nama per jenis, atau digabung (pokok+wajib+sukarela) per nama.
    const historyRows = useMemo(() => {
        if (!history) return [];
        if (!merged) return history;
        const byPerson = {};
        history.forEach((r) => {
            const p = (byPerson[r.customer_id] ??= { ...r, key: `${r.customer_id}|simpanan`, type: "simpanan", months: {}, total: 0, entries: {} });
            for (let m = 1; m <= 12; m++) {
                p.months[m] = (p.months[m] || 0) + Number(r.months[m] || 0);
                (r.entries[m] || []).forEach((e) => (p.entries[m] ??= []).push({ ...e, type: r.type }));
            }
            p.total += r.total;
        });
        return Object.values(byPerson);
    }, [history, merged]);

    const monthColumns = MONTHS.map((label, i) => ({
        key: `m${i + 1}`,
        label,
        align: "right",
        filter: "number",
        className: "min-w-[5.5rem] px-2",
        value: (r) => Number(r.months[i + 1] || 0),
        render: (r) =>
            r.months[i + 1] ? (
                <button type="button" onClick={() => setDetail({ row: r, month: i + 1 })} className={`hover:underline ${r.months[i + 1] < 0 ? "text-danger-600" : ""}`} title="Lihat / hapus catatan">
                    {num(r.months[i + 1])}
                </button>
            ) : (
                <span className="text-slate-300 dark:text-slate-700">–</span>
            ),
    }));

    const historyColumns = [
        {
            key: "name",
            label: "Nama",
            filter: "text",
            className: "sticky left-0 z-10 bg-white dark:bg-slate-900",
            value: (r) => `${r.name || ""} ${r.nip || ""}`.trim(),
            render: (r) => (
                <>
                    <div className="font-medium text-slate-900 dark:text-white">{r.name}</div>
                    <div className="font-mono text-xs text-slate-500">{r.nip || r.employee_type}</div>
                </>
            ),
        },
        merged
            ? { key: "type", label: "Jenis", value: () => "Simpanan" }
            : { key: "type", label: "Jenis", filter: "select", options: typeOptions, value: (r) => r.type, render: (r) => typeLabels[r.type] },
        ...monthColumns,
        { key: "total", label: "Total", align: "right", filter: "number", value: (r) => r.total, render: (r) => <span className="font-semibold">{num(r.total)}</span> },
    ];

    const sumFooter = (columns, amountKeys) => (rows) => (
        <tr>
            {columns.map((c, i) => (
                <td key={c.key} className={`${td} ${c.align === "right" ? "text-right" : ""} ${c.className || ""}`}>
                    {i === 0 ? "Total" : amountKeys.includes(c.key) ? num(rows.reduce((s, r) => s + Number(c.value(r) || 0), 0)) : ""}
                </td>
            ))}
        </tr>
    );

    const statusOptions = useMemo(
        () => [...new Set((recap || []).map((r) => r.employment_status).filter(Boolean))].sort().map((v) => ({ value: v, label: v })),
        [recap]
    );
    const recapColumns = [
        {
            key: "name",
            label: "Nama",
            filter: "text",
            value: (r) => `${r.name} ${r.nip || ""}`.trim(),
            render: (r) => (
                <>
                    <button onClick={() => go({ tab: "history", customer_id: r.id })} className="text-left font-medium text-primary-600 hover:underline">{r.name}</button>
                    <div className="font-mono text-xs text-slate-500">{r.nip || "tanpa NIP"}</div>
                </>
            ),
        },
        { key: "employee_type", label: "Jenis", filter: "select", options: ["PNS", "PPPK", "PPNPN", "LAINNYA"].map((v) => ({ value: v, label: v })), value: (r) => r.employee_type, render: (r) => <Badge>{r.employee_type}</Badge> },
        { key: "employment_status", label: "Status", filter: "select", options: statusOptions, value: (r) => r.employment_status || "" },
        { key: "pokok", label: "Pokok", align: "right", filter: "number", value: (r) => Number(r.pokok_total || 0), render: (r) => num(r.pokok_total) },
        { key: "wajib", label: "Wajib", align: "right", filter: "number", value: (r) => Number(r.wajib_total || 0), render: (r) => num(r.wajib_total) },
        { key: "sukarela", label: "Sukarela", align: "right", filter: "number", value: (r) => Number(r.sukarela_total || 0), render: (r) => num(r.sukarela_total) },
        { key: "balance", label: `Saldo s.d. ${year}`, align: "right", filter: "number", value: (r) => Number(r.savings_balance || 0), render: (r) => <span className="font-semibold">{num(r.savings_balance)}</span> },
    ];
    const initialHistoryFilters = filters.customer_id ? { name: employees.find((e) => e.id === filters.customer_id)?.name || "" } : {};

    const submit = (url, data) => (e) => {
        e.preventDefault();
        router.post(url, data, { preserveScroll: true, onError: setErrors, onSuccess: () => { setModal(null); setErrors({}); } });
    };

    return (
        <>
            <Head title="Simpanan Anggota" />
            <PageHeader
                icon={IconPigMoney}
                title="Simpanan Anggota"
                subtitle="Simpanan pokok, wajib, dan sukarela per bulan. Angsuran pinjaman tidak termasuk simpanan (dicek di menu Pembiayaan → Cek angsuran)."
                actions={
                    <>
                        <a href={route("savings.export", { year })} className={btn.secondary}><IconDownload size={18} /> Ekspor {year}</a>
                        {can("savings-import") && <Link href={route("savings.import")} className={btn.secondary}><IconUpload size={18} /> Impor Excel</Link>}
                        {can("savings-create") && (
                            <>
                                <button onClick={() => { setErrors({}); setModal("generate"); }} className={btn.secondary}><IconCalendarPlus size={18} /> Generate Potongan</button>
                                <button onClick={() => { setErrors({}); setModal("entry"); }} className={btn.primary}><IconPlus size={18} /> Setor / Tarik</button>
                            </>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <span className="text-sm text-slate-500">Tahun:</span>
                {years.map((y) => (
                    <button key={y} onClick={() => go({ year: y })} className={`rounded-lg px-3 py-1 text-sm ${y === year ? "bg-primary-500 text-white" : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"}`}>{y}</button>
                ))}
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Anggota membayar" value={payers} helper={`membayar simpanan di ${year}`} />
                <Stat label="Pokok" value={rupiah(totals.pokok)} />
                <Stat label="Wajib" value={rupiah(totals.wajib)} />
                <Stat label="Sukarela" value={rupiah(totals.sukarela)} />
                <Stat label="Total simpanan" value={rupiah(savingsTotal)} />
            </div>

            <Panel
                title={
                    <div className="flex gap-1">
                        {[["recap", "Rekap per anggota"], ["history", "Riwayat per bulan"]].map(([key, label]) => (
                            <button key={key} onClick={() => go({ tab: key, customer_id: "" })} className={`rounded-lg px-3 py-1 text-sm ${tab === key ? "bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300" : "text-slate-500"}`}>{label}</button>
                        ))}
                    </div>
                }
                actions={
                    tab === "recap" ? (
                        <label className="flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400">
                            <input type="checkbox" checked={filters.all} onChange={(e) => go({ all: e.target.checked ? 1 : 0 })} /> Tampilkan yang tidak membayar
                        </label>
                    ) : (
                        <label className="flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400">
                            <input type="checkbox" checked={merged} onChange={(e) => setMerged(e.target.checked)} /> Gabungkan pokok + wajib + sukarela per nama
                        </label>
                    )
                }
            >
                <p className="px-4 pt-3 text-xs text-slate-500">Klik judul kolom untuk mengurutkan. Kotak di bawah judul untuk menyaring — kolom angka menerima &gt;0, 0, &gt;=100000, 50000-100000, atau "kosong".{tab === "history" && " Klik angka bulan untuk melihat atau menghapus catatannya."}</p>
                {tab === "recap" ? (
                    <SortableTable
                        key={`recap-${year}-${filters.all}`}
                        rows={recap}
                        rowKey={(r) => r.id}
                        columns={recapColumns}
                        initialSort={{ key: "name", dir: "asc" }}
                        emptyText={`Belum ada simpanan di tahun ${year}.`}
                        footer={sumFooter(recapColumns, ["pokok", "wajib", "sukarela", "balance"])}
                    />
                ) : (
                    <SortableTable
                        key={`history-${year}-${merged}-${filters.customer_id || ""}`}
                        rows={historyRows}
                        rowKey={(r) => r.key}
                        columns={historyColumns}
                        initialSort={{ key: "name", dir: "asc" }}
                        initialFilters={initialHistoryFilters}
                        emptyText={`Tidak ada catatan di tahun ${year}.`}
                        footer={sumFooter(historyColumns, [...monthColumns.map((c) => c.key), "total"])}
                    />
                )}
            </Panel>

            <Modal show={detail !== null} onClose={() => setDetail(null)}>
                {detail && (
                    <div className="space-y-4 p-6">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            {detail.row.name} — {MONTHS[detail.month - 1]} {year}
                        </h2>
                        <div className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                            {(detail.row.entries[detail.month] || []).map((e) => (
                                <div key={e.id} className="flex items-center gap-3 p-3 text-sm">
                                    <div className="flex-1">
                                        <div className="font-medium text-slate-900 dark:text-white">{typeLabels[e.type || detail.row.type]} · {rupiah(e.amount)}</div>
                                        <div className="text-xs text-slate-500">
                                            <Badge color={e.source === "import" ? "blue" : "gray"}>{SOURCE_LABELS[e.source] || e.source}</Badge>
                                            {e.source_name && ` nama di file: ${e.source_name}`}
                                            {e.notes && ` · ${e.notes}`}
                                            {e.creator && ` · ${e.creator}`}
                                        </div>
                                    </div>
                                    {can("savings-delete") && (
                                        <button
                                            onClick={() => confirm("Hapus catatan ini?") && router.delete(route("savings.destroy", e.id), { preserveScroll: true, onSuccess: () => setDetail(null) })}
                                            className="rounded-lg p-2 text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20"
                                            title="Hapus"
                                        >
                                            <IconTrash size={16} />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="flex justify-end">
                            <button type="button" onClick={() => setDetail(null)} className={btn.secondary}>Tutup</button>
                        </div>
                    </div>
                )}
            </Modal>

            <Modal show={modal === "entry"} onClose={() => setModal(null)}>
                <form onSubmit={submit(route("savings.store"), entry)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Setor / Tarik Simpanan</h2>
                    <Field label="Pegawai" error={errors.customer_id}>
                        <SearchSelect required options={empOptions} value={entry.customer_id} onChange={(v) => setEntry({ ...entry, customer_id: v })} placeholder="Cari nama / NIP…" />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Jenis" error={errors.type}>
                            <select className={inputClass} value={entry.type} onChange={(e) => setEntry({ ...entry, type: e.target.value })}>
                                {Object.entries(typeLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </Field>
                        <Field label="Setor / Tarik" error={errors.direction}>
                            <select className={inputClass} value={entry.direction} onChange={(e) => setEntry({ ...entry, direction: e.target.value })}>
                                <option value="in">Setor</option>
                                <option value="out">Tarik / pengembalian</option>
                            </select>
                        </Field>
                        <Field label="Bulan" error={errors.period_month}>
                            <select className={inputClass} value={entry.period_month} onChange={(e) => setEntry({ ...entry, period_month: e.target.value })}>
                                {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                            </select>
                        </Field>
                        <Field label="Tahun" error={errors.period_year}>
                            <input type="number" className={inputClass} value={entry.period_year} onChange={(e) => setEntry({ ...entry, period_year: e.target.value })} />
                        </Field>
                        <Field label="Jumlah" error={errors.amount}>
                            <MoneyInput value={entry.amount} onChange={(v) => setEntry({ ...entry, amount: v })} required />
                        </Field>
                        <Field label="Sumber" error={errors.source}>
                            <select className={inputClass} value={entry.source} onChange={(e) => setEntry({ ...entry, source: e.target.value })}>
                                {["tunai", "transfer", "potong_gaji", "manual"].map((s) => <option key={s} value={s}>{SOURCE_LABELS[s]}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field label="Catatan" error={errors.notes}>
                        <input className={inputClass} value={entry.notes} onChange={(e) => setEntry({ ...entry, notes: e.target.value })} placeholder="mis. pengembalian simpanan karena mutasi" />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setModal(null)} className={btn.secondary}>Batal</button>
                        <button type="submit" className={btn.primary}>Simpan</button>
                    </div>
                </form>
            </Modal>

            <Modal show={modal === "generate"} onClose={() => setModal(null)}>
                <form onSubmit={submit(route("savings.generate"), gen)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Generate Potongan Bulanan</h2>
                    <p className="text-sm text-slate-500">
                        Membuat potongan untuk semua pegawai berstatus <b>AKTIF</b> yang belum punya catatan di periode itu. Simpanan pokok hanya dibuat sekali per pegawai.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Jenis" error={errors.type}>
                            <select className={inputClass} value={gen.type} onChange={(e) => setGen({ ...gen, type: e.target.value, amount: e.target.value === "pokok" ? 50000 : 100000 })}>
                                <option value="wajib">Simpanan Wajib</option>
                                <option value="pokok">Simpanan Pokok</option>
                            </select>
                        </Field>
                        <Field label="Jumlah per orang" error={errors.amount}>
                            <MoneyInput value={gen.amount} onChange={(v) => setGen({ ...gen, amount: v })} />
                        </Field>
                        <Field label="Bulan" error={errors.period_month}>
                            <select className={inputClass} value={gen.period_month} onChange={(e) => setGen({ ...gen, period_month: e.target.value })}>
                                {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                            </select>
                        </Field>
                        <Field label="Tahun" error={errors.period_year}>
                            <input type="number" className={inputClass} value={gen.period_year} onChange={(e) => setGen({ ...gen, period_year: e.target.value })} />
                        </Field>
                    </div>
                    <Field label="Jenis pegawai" error={errors.employee_types}>
                        <div className="flex flex-wrap gap-3">
                            {["PNS", "PPPK", "PPNPN", "LAINNYA"].map((t) => (
                                <label key={t} className="flex items-center gap-1 text-sm">
                                    <input type="checkbox" checked={gen.employee_types.includes(t)} onChange={(e) => setGen({ ...gen, employee_types: e.target.checked ? [...gen.employee_types, t] : gen.employee_types.filter((x) => x !== t) })} /> {t}
                                </label>
                            ))}
                        </div>
                    </Field>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setModal(null)} className={btn.secondary}>Batal</button>
                        <button type="submit" className={btn.primary}>Generate</button>
                    </div>
                </form>
            </Modal>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
