import React from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconReceipt2, IconUpload } from "@tabler/icons-react";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, rupiah } from "@/Components/Cooperative/ui";

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const TYPES = ["pinjaman", "wajib", "pokok", "sukarela", "angsuran", "pg"];
const SHORT = { pinjaman: "Simpanan Pinjaman", wajib: "Simpanan Wajib", pokok: "Simpanan Pokok", sukarela: "Simpanan Sukarela", angsuran: "Angsuran Koperasi", pg: "Potong Gaji Koperasi" };
const num = (v) => Math.round(Number(v || 0)).toLocaleString("id-ID");

export default function Index({ year, month, years, rows, typeLabels }) {
    const { can } = useAuthorization();
    const go = (params) => router.get(route("deductions.index"), { year, month, ...params }, { preserveState: true });
    const totals = Object.fromEntries(TYPES.map((t) => [t, rows.reduce((s, r) => s + r[t], 0)]));
    const rowTotal = (r) => TYPES.reduce((s, t) => s + r[t], 0);

    const columns = [
        {
            key: "name",
            label: "Nama",
            filter: "text",
            value: (r) => `${r.name} ${r.nip || ""}`,
            render: (r) => (<><div className="font-medium">{r.name}</div><div className="font-mono text-xs text-slate-500">{r.nip || "tanpa NIP"}</div></>),
        },
        { key: "employee_type", label: "Jenis", filter: "select", options: ["PNS", "PPPK", "PPNPN", "LAINNYA"].map((v) => ({ value: v, label: v })), value: (r) => r.employee_type || "", render: (r) => <Badge>{r.employee_type}</Badge> },
        ...TYPES.map((t) => ({ key: t, label: SHORT[t], align: "right", filter: "number", value: (r) => r[t], render: (r) => num(r[t]) })),
        { key: "total", label: "Total", align: "right", filter: "number", value: rowTotal, render: (r) => <span className="font-semibold">{num(rowTotal(r))}</span> },
    ];
    const footer = (visible) => (
        <tr className="font-semibold">
            <td className="px-4 py-3" colSpan={2}>Total</td>
            {TYPES.map((t) => <td key={t} className="px-4 py-3 text-right">{num(visible.reduce((s, r) => s + r[t], 0))}</td>)}
            <td className="px-4 py-3 text-right">{num(visible.reduce((s, r) => s + rowTotal(r), 0))}</td>
        </tr>
    );

    return (
        <>
            <Head title="Angsuran & Potongan Koperasi" />
            <PageHeader
                icon={IconReceipt2}
                title="Angsuran & Potongan Koperasi"
                subtitle="Rekap potongan gaji per pegawai: Simpanan Koperasi (Pinjaman, Wajib, Pokok, Sukarela), Angsuran Koperasi (membayar cicilan pembiayaan pegawai), dan Potong Gaji Koperasi (melunasi utang belanja/kantin)."
                actions={can("deductions-import") && <Link href={route("deductions.import")} className={btn.primary}><IconUpload size={18} /> Impor Excel</Link>}
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <select className={inputBase + " w-28"} value={year} onChange={(e) => go({ year: e.target.value })}>
                    {years.map((y) => <option key={y} value={y}>{y}</option>)}
                </select>
                <select className={inputBase + " w-40"} value={month} onChange={(e) => go({ month: e.target.value })}>
                    <option value={0}>Setahun penuh</option>
                    {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m} {year}</option>)}
                </select>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                {TYPES.map((t) => <Stat key={t} label={typeLabels[t]} value={rupiah(totals[t])} />)}
            </div>

            <Panel title={`Potongan ${month ? MONTHS[month - 1] + " " : ""}${year} (${rows.length} pegawai)`}>
                <SortableTable rows={rows} rowKey={(r) => r.customer_id} columns={columns} initialSort={{ key: "name", dir: "asc" }} footer={footer} emptyText="Belum ada data. Klik Impor Excel." />
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
