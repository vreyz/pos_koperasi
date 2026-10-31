import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconChecklist } from "@tabler/icons-react";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, MONTHS, PageHeader, Panel, Stat, btn, inputBase, rupiah, td, useFlashToasts } from "@/Components/Cooperative/ui";

const NOTE_COLOR = (note) => (note === "Cocok" ? "green" : note.startsWith("Sudah dipotong") ? "blue" : note.startsWith("Belum dipotong") || note.startsWith("Potongan kurang") || note.startsWith("Tercatat lunas") ? "red" : "amber");

export default function Reconcile({ year, rows }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [onlyIssues, setOnlyIssues] = useState(true);
    const visible = useMemo(() => (onlyIssues ? rows.filter((r) => r.note !== "Cocok") : rows), [rows, onlyIssues]);
    const sum = (key) => rows.reduce((s, r) => s + Number(r[key] || 0), 0);
    const now = new Date();
    const notes = [...new Set(rows.map((r) => r.note))].map((v) => ({ value: v, label: v }));
    const fixable = rows.filter((r) => r.scheduled > 0 && r.deducted >= r.scheduled && r.marked_paid < r.scheduled).length;

    const columns = [
        { key: "name", label: "Nama", filter: "text", value: (r) => `${r.name || ""} ${r.nip || ""}`, render: (r) => <><div className="font-medium">{r.name}</div><div className="text-xs text-slate-500">{r.employee_type} {r.types.length ? `· ${r.types.join(" + ")}` : ""}</div></> },
        { key: "month", label: "Bulan", filter: "select", options: MONTHS.map((m, i) => ({ value: i + 1, label: m })), value: (r) => r.month, render: (r) => `${MONTHS[r.month - 1]} ${year}` },
        { key: "scheduled", label: "Jadwal cicilan", align: "right", filter: "number", value: (r) => r.scheduled, render: (r) => rupiah(r.scheduled) },
        { key: "marked_paid", label: "Lunas di monitoring", align: "right", filter: "number", value: (r) => r.marked_paid, render: (r) => rupiah(r.marked_paid) },
        { key: "deducted", label: "Potongan gaji", align: "right", filter: "number", value: (r) => r.deducted, render: (r) => rupiah(r.deducted) },
        { key: "difference", label: "Selisih", align: "right", filter: "number", value: (r) => r.difference, render: (r) => <span className={r.difference < 0 ? "font-semibold text-danger-600" : r.difference > 0 ? "font-semibold text-warning-700" : ""}>{rupiah(r.difference)}</span> },
        { key: "note", label: "Keterangan", filter: "select", options: notes, value: (r) => r.note, render: (r) => <Badge color={NOTE_COLOR(r.note)}>{r.note}</Badge> },
    ];

    return (
        <>
            <Head title="Cek Angsuran" />
            <PageHeader
                icon={IconChecklist}
                title="Cek Angsuran Pembiayaan"
                subtitle="Membandingkan jadwal cicilan (kontrak), status lunas di monitoring, dan potongan gaji 'Angsuran Koperasi' dari rekap potongan (Master) per pegawai per bulan."
                actions={
                    <>
                        <Link href={route("financing.index", "dana")} className={btn.secondary}><IconArrowLeft size={18} /> Pembiayaan</Link>
                        {can("financing-disburse") && fixable > 0 && (
                            <button onClick={() => confirm(`Tandai lunas ${fixable} bulan yang potongan gajinya sudah sesuai jadwal?`) && router.post(route("financing.reconcile.mark"), { year }, { preserveScroll: true })} className={btn.primary}>
                                Tandai lunas sesuai potongan ({fixable})
                            </button>
                        )}
                    </>
                }
            />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <span className="text-sm text-slate-500">Tahun:</span>
                {[now.getFullYear() - 3, now.getFullYear() - 2, now.getFullYear() - 1, now.getFullYear()].map((y) => (
                    <button key={y} onClick={() => router.get(route("financing.reconcile"), { year: y })} className={`rounded-lg px-3 py-1 text-sm ${y === year ? "bg-primary-500 text-white" : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"}`}>{y}</button>
                ))}
            </div>
            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Jadwal cicilan" value={rupiah(sum("scheduled"))} />
                <Stat label="Potongan gaji" value={rupiah(sum("deducted"))} helper="kolom Angsuran Koperasi" />
                <Stat label="Selisih total" value={rupiah(sum("deducted") - sum("scheduled"))} />
                <Stat label="Baris bermasalah" value={rows.filter((r) => r.note !== "Cocok").length} helper={`dari ${rows.length} pegawai-bulan`} />
            </div>
            <Panel
                title={`Perbandingan ${year}`}
                actions={<label className="flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400"><input type="checkbox" checked={onlyIssues} onChange={(e) => setOnlyIssues(e.target.checked)} /> Hanya yang tidak cocok</label>}
            >
                <SortableTable
                    key={`${year}-${onlyIssues}`}
                    rows={visible}
                    rowKey={(r) => `${r.customer_id}-${r.month}`}
                    columns={columns}
                    initialSort={{ key: "name", dir: "asc" }}
                    emptyText="Semua cocok."
                    footer={(list) => (
                        <tr>
                            <td className={td}>Total</td><td className={td}></td>
                            {["scheduled", "marked_paid", "deducted", "difference"].map((k) => <td key={k} className={td + " text-right"}>{rupiah(list.reduce((s, r) => s + r[k], 0))}</td>)}
                            <td className={td}></td>
                        </tr>
                    )}
                />
            </Panel>
        </>
    );
}

Reconcile.layout = (page) => <DashboardLayout children={page} />;
