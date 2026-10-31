import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import Modal from "@/Components/Modal";
import { IconCalendarPlus, IconDownload, IconPigMoney, IconPlus, IconTrash, IconUpload } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, MONTHS, PageHeader, Panel, Stat, btn, inputBase, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

const SOURCE_LABELS = { manual: "Manual", potong_gaji: "Potong gaji", tunai: "Tunai", transfer: "Transfer", import: "Impor Excel" };

export default function Index({ tab, year, years, filters, recap, history, totals, payers, typeLabels, employees }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [search, setSearch] = useState(filters.search || "");
    const [modal, setModal] = useState(null);
    const [errors, setErrors] = useState({});
    const now = new Date();
    const [entry, setEntry] = useState({ customer_id: "", type: "sukarela", period_year: year, period_month: now.getMonth() + 1, direction: "in", amount: "", source: "tunai", notes: "" });
    const [gen, setGen] = useState({ type: "wajib", period_year: now.getFullYear(), period_month: now.getMonth() + 1, amount: 100000, employee_types: ["PNS", "PPPK"] });

    const go = (changes) => router.get(route("savings.index"), { tab, year, ...filters, ...changes }, { preserveState: true, replace: true });
    const savingsTotal = ["pokok", "wajib", "sukarela"].reduce((s, t) => s + Number(totals[t] || 0), 0);

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
                subtitle="Simpanan pokok, wajib, sukarela, dan angsuran pinjaman per bulan."
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
                    <button key={y} onClick={() => go({ year: y, page: 1 })} className={`rounded-lg px-3 py-1 text-sm ${y === year ? "bg-primary-500 text-white" : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"}`}>{y}</button>
                ))}
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-6">
                <Stat label="Anggota membayar" value={payers} helper={`membayar simpanan di ${year}`} />
                <Stat label="Pokok" value={rupiah(totals.pokok)} />
                <Stat label="Wajib" value={rupiah(totals.wajib)} />
                <Stat label="Sukarela" value={rupiah(totals.sukarela)} />
                <Stat label="Total simpanan" value={rupiah(savingsTotal)} />
                <Stat label="Angsuran pinjaman" value={rupiah(totals.angsuran)} />
            </div>

            <Panel
                title={
                    <div className="flex gap-1">
                        {[["recap", "Rekap per anggota"], ["history", "Riwayat"]].map(([key, label]) => (
                            <button key={key} onClick={() => go({ tab: key, page: 1 })} className={`rounded-lg px-3 py-1 text-sm ${tab === key ? "bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300" : "text-slate-500"}`}>{label}</button>
                        ))}
                    </div>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {tab === "recap" && (
                            <label className="flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400">
                                <input type="checkbox" checked={filters.all} onChange={(e) => go({ all: e.target.checked ? 1 : 0, page: 1 })} /> Tampilkan yang tidak membayar
                            </label>
                        )}
                        <form onSubmit={(e) => { e.preventDefault(); go({ search, page: 1 }); }}>
                            <input className={inputBase + " w-56"} placeholder="Cari nama / NIP..." value={search} onChange={(e) => setSearch(e.target.value)} />
                        </form>
                    </div>
                }
            >
                <div className="overflow-x-auto">
                    {tab === "recap" ? (
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th className={th}>Nama</th>
                                    <th className={th}>Jenis / Status</th>
                                    <th className={th + " text-right"}>Pokok</th>
                                    <th className={th + " text-right"}>Wajib</th>
                                    <th className={th + " text-right"}>Sukarela</th>
                                    <th className={th + " text-right"}>Angsuran</th>
                                    <th className={th + " text-right"}>Saldo simpanan s.d. {year}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {recap.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className={td}>
                                            <button onClick={() => go({ tab: "history", customer_id: r.id, search: "", page: 1 })} className="text-left font-medium text-primary-600 hover:underline">{r.name}</button>
                                            <div className="font-mono text-xs text-slate-500">{r.nip || "tanpa NIP"}</div>
                                        </td>
                                        <td className={td}><Badge>{r.employee_type}</Badge> <span className="text-xs text-slate-500">{r.employment_status}</span></td>
                                        <td className={td + " text-right"}>{rupiah(r.pokok_total)}</td>
                                        <td className={td + " text-right"}>{rupiah(r.wajib_total)}</td>
                                        <td className={td + " text-right"}>{rupiah(r.sukarela_total)}</td>
                                        <td className={td + " text-right"}>{rupiah(r.angsuran_total)}</td>
                                        <td className={td + " text-right font-semibold"}>{rupiah(r.savings_balance)}</td>
                                    </tr>
                                ))}
                                {recap.data.length === 0 && <tr><td colSpan={7} className="p-10 text-center text-slate-500">Belum ada simpanan di tahun {year}.</td></tr>}
                            </tbody>
                        </table>
                    ) : (
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-950">
                                <tr>
                                    <th className={th}>Periode</th>
                                    <th className={th}>Nama</th>
                                    <th className={th}>Jenis</th>
                                    <th className={th + " text-right"}>Jumlah</th>
                                    <th className={th}>Sumber</th>
                                    <th className={th}>Catatan</th>
                                    <th className={th}></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {filters.customer_id && (
                                    <tr><td colSpan={7} className="bg-primary-50 px-4 py-2 text-sm dark:bg-primary-900/20">
                                        Filter: {employees.find((e) => e.id === filters.customer_id)?.name} — <button className="text-primary-600 hover:underline" onClick={() => go({ customer_id: "", page: 1 })}>hapus filter</button>
                                    </td></tr>
                                )}
                                {history.data.map((h) => (
                                    <tr key={h.id}>
                                        <td className={td}>{MONTHS[h.period_month - 1]} {h.period_year}</td>
                                        <td className={td}>{h.customer?.name}</td>
                                        <td className={td}>{typeLabels[h.type]}</td>
                                        <td className={td + ` text-right font-medium ${h.amount < 0 ? "text-danger-600" : ""}`}>{rupiah(h.amount)}</td>
                                        <td className={td}><Badge color={h.source === "import" ? "blue" : "gray"}>{SOURCE_LABELS[h.source] || h.source}</Badge></td>
                                        <td className={td + " text-xs text-slate-500"}>{h.notes}{h.creator && ` · ${h.creator.name}`}</td>
                                        <td className={td}>
                                            {can("savings-delete") && (
                                                <button onClick={() => confirm("Hapus catatan ini?") && router.delete(route("savings.destroy", h.id), { preserveScroll: true })} className="rounded-lg p-2 text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20">
                                                    <IconTrash size={16} />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {history.data.length === 0 && <tr><td colSpan={7} className="p-10 text-center text-slate-500">Tidak ada catatan.</td></tr>}
                            </tbody>
                        </table>
                    )}
                </div>
            </Panel>
            <div className="mt-4"><Pagination links={(tab === "recap" ? recap : history).links} /></div>

            <Modal show={modal === "entry"} onClose={() => setModal(null)}>
                <form onSubmit={submit(route("savings.store"), entry)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Setor / Tarik Simpanan</h2>
                    <Field label="Pegawai" error={errors.customer_id}>
                        <select className={inputClass} value={entry.customer_id} onChange={(e) => setEntry({ ...entry, customer_id: e.target.value })} required>
                            <option value="">— pilih pegawai —</option>
                            {employees.map((e) => <option key={e.id} value={e.id}>{e.name}{e.nip ? ` (${e.nip})` : ""}</option>)}
                        </select>
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
                        <Field label="Jumlah (Rp)" error={errors.amount}>
                            <input type="number" min="1" className={inputClass} value={entry.amount} onChange={(e) => setEntry({ ...entry, amount: e.target.value })} required />
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
                        <Field label="Jumlah per orang (Rp)" error={errors.amount}>
                            <input type="number" min="1" className={inputClass} value={gen.amount} onChange={(e) => setGen({ ...gen, amount: e.target.value })} />
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
