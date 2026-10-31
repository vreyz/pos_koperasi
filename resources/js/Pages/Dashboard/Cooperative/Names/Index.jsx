import React, { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconLink, IconUnlink } from "@tabler/icons-react";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const SOURCES = {
    sales: { label: "Penjualan", color: "blue" },
    savings: { label: "Simpanan", color: "green" },
    alias: { label: "Alias", color: "gray" },
};

export default function Index({ rows, employees, shuPeriods, shuYear }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const canEdit = can("employees-edit");
    const [status, setStatus] = useState(rows.some((r) => !r.customer_id) ? "unmatched" : "all");
    const [choice, setChoice] = useState({});
    const employeeById = useMemo(() => Object.fromEntries(employees.map((e) => [e.id, e])), [employees]);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);


    const key = (r) => `${r.source}|${r.name}|${r.customer_id || ""}`;
    const unmatched = rows.filter((r) => !r.customer_id).length;
    const link = (r) =>
        router.post(route("name-links.link"), { source: r.source, name: r.name, customer_id: choice[key(r)] }, { preserveScroll: true, preserveState: true });
    const unlink = (r) => {
        if (confirm(`Putuskan "${r.name}" dari ${employeeById[r.customer_id]?.name}?`)) {
            router.post(route("name-links.unlink"), { source: r.source, name: r.name }, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <>
            <Head title="Pencocokan Nama" />
            <PageHeader
                icon={IconLink}
                title="Pencocokan Nama"
                subtitle="Hubungkan nama di penjualan dan di file simpanan ke data pegawai. Perubahan langsung memindahkan data, diingat untuk impor berikutnya, dan ikut di SHU setelah dihitung ulang."
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Nama penjualan" value={rows.filter((r) => r.source === "sales").length} />
                <Stat label="Nama simpanan" value={rows.filter((r) => r.source === "savings").length} />
                <Stat label="Alias tersimpan" value={rows.filter((r) => r.source === "alias").length} />
                <Stat label="Belum cocok" value={unmatched} helper="tidak ikut SHU sampai dihubungkan" />
            </div>

            <Panel
                title="Daftar nama"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400">
                            <input type="checkbox" checked={status === "unmatched"} onChange={(e) => setStatus(e.target.checked ? "unmatched" : "all")} /> Hanya yang belum cocok ke pegawai
                        </label>
                        {shuPeriods.length > 0 && (
                            <select className={inputBase + " w-auto pr-9"} value={shuYear || ""} onChange={(e) => router.get(route("name-links.index"), { shu_year: e.target.value }, { preserveState: true })}>
                                {shuPeriods.map((p) => <option key={p.id} value={p.year}>Kolom SHU {p.year}</option>)}
                            </select>
                        )}
                    </div>
                }
            >
                <SortableTable
                    key={status}
                    rows={status === "unmatched" ? rows.filter((r) => !r.customer_id) : rows}
                    rowKey={key}
                    initialSort={{ key: "name", dir: "asc" }}
                    emptyText="Tidak ada nama dengan saringan ini."
                    columns={[
                        { key: "name", label: "Nama di sumber", filter: "text", value: (r) => r.name, render: (r) => <span className="font-medium">{r.name}</span> },
                        { key: "source", label: "Sumber", filter: "select", options: Object.entries(SOURCES).map(([value, v]) => ({ value, label: v.label })), value: (r) => r.source, render: (r) => <Badge color={SOURCES[r.source].color}>{SOURCES[r.source].label}</Badge> },
                        { key: "period", label: "Tahun", filter: "text", value: (r) => r.period || "" },
                        { key: "total", label: "Jumlah", align: "right", filter: "number", value: (r) => Number(r.total || 0), render: (r) => (r.total == null ? "-" : rupiah(r.total)) },
                        {
                            key: "employee", label: "Pegawai terhubung", filter: "text",
                            value: (r) => employeeById[r.customer_id]?.name || (r.buyer_customer ? "pembeli umum" : "belum cocok"),
                            render: (r) => {
                                const emp = employeeById[r.customer_id];
                                return emp ? (
                                    <>
                                        <div>{emp.name}</div>
                                        <div className="text-xs text-slate-500">{[emp.nip, emp.employee_type, emp.employment_status].filter(Boolean).join(" · ")}</div>
                                    </>
                                ) : (
                                    <>
                                        {r.buyer_customer ? <Badge color="gray">Pembeli umum (bukan pegawai)</Badge> : <Badge color="amber">Belum cocok</Badge>}
                                        <div className="mt-1 whitespace-normal">
                                            {(r.suggestions || []).map((sg) => (
                                                <button key={sg.id} onClick={() => setChoice({ ...choice, [key(r)]: String(sg.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 text-xs hover:bg-primary-50 dark:border-slate-700">
                                                    {sg.name} <span className="text-slate-400">{sg.score}%</span>
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                );
                            },
                        },
                        ...(shuYear ? [{ key: "shu", label: `SHU ${shuYear}`, align: "right", filter: "number", value: (r) => Number(r.shu || 0), render: (r) => (r.shu == null ? <span className="text-slate-400">-</span> : rupiah(r.shu)) }] : []),
                        ...(canEdit ? [{
                            key: "action", label: "Hubungkan / pindahkan ke", value: () => "",
                            render: (r) => (
                                <div className="flex gap-2">
                                    <SearchSelect className="w-64" options={empOptions} value={choice[key(r)] || ""} onChange={(v) => setChoice({ ...choice, [key(r)]: String(v) })} placeholder="Cari pegawai…" />
                                    <button className={btn.primary} disabled={!choice[key(r)] || Number(choice[key(r)]) === r.customer_id} onClick={() => link(r)}>Simpan</button>
                                    {employeeById[r.customer_id] && r.source !== "savings" && <button className={btn.secondary} onClick={() => unlink(r)} title="Putuskan"><IconUnlink size={16} /></button>}
                                </div>
                            ),
                        }] : []),
                    ]}
                />
                <p className="p-4 text-xs text-slate-500">
                    Nama non-pegawai (UMUM, KANTOR, DOKTER, dll.) otomatis tercatat sebagai pembeli umum (customer non-member) dan tidak mendapat SHU. Hubungkan ke pegawai bila ternyata pegawai. Nama simpanan hanya bisa dipindahkan ke pegawai lain.
                </p>
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
