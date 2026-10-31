import React, { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconLink, IconUnlink } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, PageHeader, Panel, Stat, btn, inputBase, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

const SOURCES = {
    sales: { label: "Penjualan", color: "blue" },
    savings: { label: "Simpanan", color: "green" },
    alias: { label: "Alias", color: "gray" },
};

export default function Index({ rows, employees, shuPeriods, shuYear }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const canEdit = can("employees-edit");
    const [source, setSource] = useState("all");
    const [status, setStatus] = useState(rows.some((r) => !r.customer_id) ? "unmatched" : "all");
    const [search, setSearch] = useState("");
    const [choice, setChoice] = useState({});
    const employeeById = useMemo(() => Object.fromEntries(employees.map((e) => [e.id, e])), [employees]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter((r) =>
            (source === "all" || r.source === source) &&
            (status === "all" || (status === "unmatched" ? !r.customer_id : !!r.customer_id)) &&
            (!q || r.name.toLowerCase().includes(q) || (employeeById[r.customer_id]?.name || "").toLowerCase().includes(q))
        );
    }, [rows, source, status, search, employeeById]);

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
                subtitle="Hubungkan nama di penjualan dan di file simpanan ke data pegawai. Perubahan langsung memindahkan data, diingat untuk import berikutnya, dan ikut di SHU setelah dihitung ulang."
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Nama penjualan" value={rows.filter((r) => r.source === "sales").length} />
                <Stat label="Nama simpanan" value={rows.filter((r) => r.source === "savings").length} />
                <Stat label="Alias tersimpan" value={rows.filter((r) => r.source === "alias").length} />
                <Stat label="Belum cocok" value={unmatched} helper="tidak ikut SHU sampai dihubungkan" />
            </div>

            <Panel
                title={`Daftar nama (${filtered.length})`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <select className={inputBase + " w-auto pr-9"} value={source} onChange={(e) => setSource(e.target.value)}>
                            <option value="all">Semua sumber</option>
                            {Object.entries(SOURCES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                        </select>
                        <select className={inputBase + " w-auto pr-9"} value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="unmatched">Belum cocok</option>
                            <option value="matched">Sudah cocok</option>
                            <option value="all">Semua</option>
                        </select>
                        {shuPeriods.length > 0 && (
                            <select className={inputBase + " w-auto pr-9"} value={shuYear || ""} onChange={(e) => router.get(route("name-links.index"), { shu_year: e.target.value }, { preserveState: true })}>
                                {shuPeriods.map((p) => <option key={p.id} value={p.year}>Kolom SHU {p.year}</option>)}
                            </select>
                        )}
                        <input className={inputBase + " w-56"} placeholder="Cari nama..." value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                }
            >
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th className={th}>Nama di sumber</th>
                                <th className={th}>Sumber</th>
                                <th className={th}>Tahun</th>
                                <th className={th + " text-right"}>Jumlah</th>
                                <th className={th}>Pegawai terhubung</th>
                                {shuYear && <th className={th + " text-right"}>SHU {shuYear}</th>}
                                {canEdit && <th className={th}>Hubungkan / pindahkan ke</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {filtered.map((r) => {
                                const emp = employeeById[r.customer_id];
                                return (
                                    <tr key={key(r)} className="align-top">
                                        <td className={td + " font-medium"}>{r.name}</td>
                                        <td className={td}><Badge color={SOURCES[r.source].color}>{SOURCES[r.source].label}</Badge></td>
                                        <td className={td}>{r.period || "-"}</td>
                                        <td className={td + " text-right"}>{r.total == null ? "-" : rupiah(r.total)}</td>
                                        <td className={td}>
                                            {emp ? (
                                                <>
                                                    <div>{emp.name}</div>
                                                    <div className="text-xs text-slate-500">{[emp.nip, emp.employee_type, emp.employment_status].filter(Boolean).join(" · ")}</div>
                                                </>
                                            ) : (
                                                <>
                                                    <Badge color="amber">Belum cocok</Badge>
                                                    <div className="mt-1 whitespace-normal">
                                                        {(r.suggestions || []).map((s) => (
                                                            <button key={s.id} onClick={() => setChoice({ ...choice, [key(r)]: String(s.id) })} className="mb-1 mr-1 rounded-lg border border-slate-200 px-2 py-0.5 text-xs hover:bg-primary-50 dark:border-slate-700">
                                                                {s.name} <span className="text-slate-400">{s.score}%</span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </>
                                            )}
                                        </td>
                                        {shuYear && <td className={td + " text-right"}>{r.shu == null ? <span className="text-slate-400">-</span> : rupiah(r.shu)}</td>}
                                        {canEdit && (
                                            <td className={td}>
                                                <div className="flex gap-2">
                                                    <select className={inputBase + " w-52"} value={choice[key(r)] || ""} onChange={(e) => setChoice({ ...choice, [key(r)]: e.target.value })}>
                                                        <option value="">— pilih pegawai —</option>
                                                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}{e.nip ? ` (${e.nip})` : ""}{e.employee_type === "PPNPN" ? " · PPNPN" : ""}</option>)}
                                                    </select>
                                                    <button className={btn.primary} disabled={!choice[key(r)] || Number(choice[key(r)]) === r.customer_id} onClick={() => link(r)}>Simpan</button>
                                                    {emp && r.source !== "savings" && (
                                                        <button className={btn.secondary} onClick={() => unlink(r)} title="Putuskan"><IconUnlink size={16} /></button>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                            {filtered.length === 0 && <tr><td colSpan={7} className="p-10 text-center text-slate-500">Tidak ada nama dengan filter ini.</td></tr>}
                        </tbody>
                    </table>
                </div>
                <p className="p-4 text-xs text-slate-500">
                    Nama non-pegawai (UMUM, KANTOR, DOKTER, dll.) cukup dibiarkan belum cocok — tidak mendapat SHU. Nama simpanan hanya bisa dipindahkan ke pegawai lain (datanya tidak boleh tanpa pemilik).
                </p>
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
