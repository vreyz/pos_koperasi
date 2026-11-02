import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconBook2, IconPlus, IconPencilPlus, IconArrowBackUp, IconRefresh, IconChevronDown, IconChevronRight } from "@tabler/icons-react";
import { PageHeader, Panel, btn, inputBase, useFlashToasts } from "@/Components/Cooperative/ui";
import { AccountingTabs, DivisionBadge, PeriodFilter, idr, fmtDate } from "@/Components/Accounting/shared";
import { useAuthorization } from "@/Utils/authorization";
import Pagination from "@/Components/Dashboard/Pagination";

function LineRow({ line }) {
    const [open, setOpen] = useState(false);
    const hasDetail = line.detail && line.detail.length > 0;
    return (
        <>
            <tr className="border-t border-slate-100 dark:border-slate-800">
                <td className={`px-3 py-1 text-sm ${line.credit ? "pl-8" : ""}`}>
                    {hasDetail ? (
                        <button type="button" onClick={() => setOpen(!open)} className="inline-flex items-center gap-1 text-left hover:underline">
                            {open ? <IconChevronDown size={14} /> : <IconChevronRight size={14} />}
                            <span className="font-mono text-xs text-slate-500">{line.code}</span> {line.name}
                            {line.people > 0 && <span className="ml-1 text-xs text-slate-500">({line.people} pegawai)</span>}
                        </button>
                    ) : (
                        <span><span className="font-mono text-xs text-slate-500">{line.code}</span> {line.name}{line.memo ? <span className="text-xs text-slate-500"> · {line.memo}</span> : null}</span>
                    )}
                </td>
                <td className="px-3 py-1 text-right font-mono text-sm">{line.debit ? idr(line.debit) : ""}</td>
                <td className="px-3 py-1 text-right font-mono text-sm">{line.credit ? idr(line.credit) : ""}</td>
            </tr>
            {open &&
                line.detail.map((d, i) => (
                    <tr key={i} className="bg-slate-50/60 dark:bg-slate-800/30">
                        <td className="py-0.5 pl-14 pr-3 text-xs text-slate-600 dark:text-slate-400">{d.customer || "(tanpa nama)"}{d.memo ? ` · ${d.memo}` : ""}</td>
                        <td className="px-3 py-0.5 text-right font-mono text-xs">{d.debit ? idr(d.debit) : ""}</td>
                        <td className="px-3 py-0.5 text-right font-mono text-xs">{d.credit ? idr(d.credit) : ""}</td>
                    </tr>
                ))}
        </>
    );
}

export default function Journals({ entries, filters, divisions, events }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [q, setQ] = useState(filters.q || "");
    const apply = (params) => router.get(route("accounting.journals"), { ...filters, ...params }, { preserveState: true });
    const reverse = (e) => {
        const reason = window.prompt(`Balik jurnal ${e.number}? Isi alasan (boleh kosong):`, "");
        if (reason === null) return;
        router.post(route("accounting.reverse", e.id), { reason }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Jurnal" />
            <PageHeader
                icon={IconBook2}
                title="Akuntansi"
                subtitle="Semua jurnal dari kejadian di setiap divisi. Jurnal yang sudah tersimpan tidak diubah; koreksi dengan jurnal pembalik."
                actions={
                    <>
                        {can("accounting-create") && <Link href={route("accounting.record")} className={btn.primary}><IconPlus size={16} /> Catat Kejadian</Link>}
                        {can("accounting-manage") && <Link href={route("accounting.manual")} className={btn.secondary}><IconPencilPlus size={16} /> Jurnal Umum</Link>}
                        {can("accounting-manage") && (
                            <button type="button" className={btn.secondary} title="Buat/perbarui jurnal otomatis dari kasir, pembiayaan, potongan, simpanan" onClick={() => router.post(route("accounting.sync"), {}, { preserveScroll: true })}>
                                <IconRefresh size={16} /> Sinkronkan
                            </button>
                        )}
                    </>
                }
            />
            <AccountingTabs />
            <PeriodFilter routeName="accounting.journals" filters={filters}>
                <select className={inputBase} value={filters.division || ""} onChange={(e) => apply({ division: e.target.value || undefined })}>
                    <option value="">Semua divisi</option>
                    {Object.entries(divisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
                <select className={inputBase} value={filters.event || ""} onChange={(e) => apply({ event: e.target.value || undefined })}>
                    <option value="">Semua kejadian</option>
                    {events.map((e) => <option key={e.code} value={e.code}>{e.code} {e.label}</option>)}
                </select>
                <form onSubmit={(ev) => { ev.preventDefault(); apply({ q: q || undefined }); }}>
                    <input className={inputBase} placeholder="Cari nomor/uraian…" value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
            </PeriodFilter>

            {entries.data.length === 0 && (
                <Panel><p className="p-6 text-center text-sm text-slate-500">Belum ada jurnal pada periode ini.</p></Panel>
            )}
            <div className="space-y-3">
                {entries.data.map((e) => (
                    <Panel
                        key={e.id}
                        title={
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-sm">{e.number}</span>
                                <span className="text-sm font-normal text-slate-500">{fmtDate(e.date)}</span>
                                <DivisionBadge division={e.division} divisions={divisions} />
                                {e.event_code && <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{e.event_code}</span>}
                                <span className="text-sm font-medium">{e.description}</span>
                                {e.reversed_by && <span className="text-xs text-danger-600">dibalik oleh {e.reversed_by}</span>}
                                {e.reversal_of && <span className="text-xs text-warning-600">pembalik {e.reversal_of}</span>}
                            </span>
                        }
                        actions={
                            can("accounting-manage") && !e.reversed_by && !e.reversal_of && !e.is_opening && (
                                <button type="button" onClick={() => reverse(e)} className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-danger-600">
                                    <IconArrowBackUp size={14} /> Balik
                                </button>
                            )
                        }
                    >
                        <table className="w-full">
                            <thead>
                                <tr className="text-xs text-slate-500">
                                    <th className="px-3 py-1 text-left font-medium">Akun</th>
                                    <th className="w-36 px-3 py-1 text-right font-medium">Debit</th>
                                    <th className="w-36 px-3 py-1 text-right font-medium">Kredit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {e.lines.map((l) => <LineRow key={`${l.code}-${l.debit ? "D" : "K"}`} line={l} />)}
                                <tr className="border-t border-slate-200 font-semibold dark:border-slate-700">
                                    <td className="px-3 py-1 text-right text-xs text-slate-500">{e.creator ? `dicatat oleh ${e.creator}` : ""}</td>
                                    <td className="px-3 py-1 text-right font-mono text-sm">{idr(e.total)}</td>
                                    <td className="px-3 py-1 text-right font-mono text-sm">{idr(e.total)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Panel>
                ))}
            </div>
            {entries.last_page > 1 && <div className="mt-4"><Pagination links={entries.links} /></div>}
        </>
    );
}

Journals.layout = (page) => <DashboardLayout children={page} />;
