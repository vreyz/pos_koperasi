import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import SortableTable from "@/Components/Cooperative/SortableTable";
import Modal from "@/Components/Modal";
import { IconAlertTriangle, IconDownload, IconId, IconPencil, IconPlus, IconTrash, IconUpload } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputBase, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";

const EMPTY = { nip: "", name: "", employee_type: "PNS", rank: "", grade: "", position: "", work_unit: "", employment_status: "AKTIF", active_from: "", active_until: "", aliases: "" };

const statusColor = (status) =>
    status === "AKTIF" ? "green" : ["MUTASI", "PENSIUN", "KELUAR"].includes(status) ? "amber" : "gray";

export default function Index({ employees, types, statuses, summary }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});

    const open = (employee) => {
        setErrors({});
        setEditing(employee || "new");
        setForm(
            employee
                ? {
                      ...EMPTY,
                      ...Object.fromEntries(Object.keys(EMPTY).map((k) => [k, employee[k] ?? ""])),
                      aliases: (employee.aliases || []).map((a) => a.alias).join(", "),
                  }
                : EMPTY
        );
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onError: setErrors, onSuccess: () => setEditing(null) };
        editing === "new"
            ? router.post(route("employees.store"), form, options)
            : router.put(route("employees.update", editing.id), form, options);
    };

    const destroy = (employee) => {
        if (confirm(`Hapus pegawai ${employee.name}?`)) router.delete(route("employees.destroy", employee.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Data Pegawai" />
            <PageHeader
                icon={IconId}
                title="Data Pegawai"
                subtitle="Satu data pegawai (kunci: NIP) dipakai bersama oleh kasir POS, simpanan, dan SHU."
                actions={
                    <>
                        <a href={route("employees.export")} className={btn.secondary}>
                            <IconDownload size={18} /> Ekspor
                        </a>
                        {can("employees-import") && (
                            <Link href={route("employees.import")} className={btn.secondary}>
                                <IconUpload size={18} /> Impor Excel
                            </Link>
                        )}
                        {can("employees-create") && (
                            <button onClick={() => open(null)} className={btn.primary}>
                                <IconPlus size={18} /> Tambah
                            </button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Total pegawai" value={summary.total} />
                {types.map((t) => (
                    <Stat key={t} label={t} value={summary.by_type?.[t] || 0} />
                ))}
            </div>
            {summary.without_nip > 0 && (
                <p className="mb-4 flex items-center gap-2 text-sm text-warning-700 dark:text-warning-400">
                    <IconAlertTriangle size={18} /> {summary.without_nip} pegawai belum punya NIP.
                </p>
            )}

            <Panel title={`Daftar Pegawai (${employees.length})`}>
                <SortableTable
                    rows={employees}
                    rowKey={(e) => e.id}
                    initialSort={{ key: "name", dir: "asc" }}
                    emptyText="Belum ada data pegawai. Gunakan Impor Excel untuk memasukkan daftar pegawai."
                    columns={[
                        {
                            key: "nip", label: "NIP", filter: "text", value: (e) => e.nip || "",
                            render: (e) => <span className="font-mono">{e.nip || <span className="text-slate-400">—</span>}{e.nip_problem && <span title={e.nip_problem} className="ml-1 inline-block align-middle text-warning-600"><IconAlertTriangle size={16} /></span>}</span>,
                        },
                        {
                            key: "name", label: "Nama", filter: "text", value: (e) => `${e.name} ${(e.aliases || []).map((a) => a.alias).join(" ")}`,
                            render: (e) => (
                                <>
                                    <div className="font-medium text-slate-900 dark:text-white">{e.name}</div>
                                    {e.aliases?.length > 0 && <div className="max-w-xs truncate text-xs text-slate-500" title={e.aliases.map((a) => a.alias).join(", ")}>alias: {e.aliases.map((a) => a.alias).join(", ")}</div>}
                                </>
                            ),
                        },
                        { key: "type", label: "Jenis", filter: "select", options: types.map((t) => ({ value: t, label: t })), value: (e) => e.employee_type, render: (e) => <Badge color={e.employee_type === "PPNPN" ? "blue" : "gray"}>{e.employee_type}</Badge> },
                        { key: "unit", label: "Unit / Jabatan", filter: "text", value: (e) => `${e.work_unit || ""} ${e.position || ""}`, render: (e) => <><div>{e.work_unit || "-"}</div><div className="text-xs text-slate-500">{[e.position, e.grade].filter(Boolean).join(" · ")}</div></> },
                        {
                            key: "status", label: "Status", filter: "select", options: statuses.map((st) => ({ value: st, label: st })), value: (e) => e.employment_status || "",
                            render: (e) => <><Badge color={statusColor(e.employment_status)}>{e.employment_status || "-"}</Badge>{e.active_until && <div className="text-xs text-slate-500">s.d. {new Date(e.active_until).toLocaleDateString("id-ID")}</div>}</>,
                        },
                        { key: "savings", label: "Saldo simpanan", align: "right", filter: "number", value: (e) => Number(e.savings_total || 0), render: (e) => rupiah(e.savings_total) },
                        {
                            key: "actions", label: "", value: () => "",
                            render: (e) => (
                                <div className="text-right">
                                    {can("employees-edit") && <button onClick={() => open(e)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" title="Ubah"><IconPencil size={18} /></button>}
                                    {can("employees-delete") && <button onClick={() => destroy(e)} className="rounded-lg p-2 text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20" title="Hapus"><IconTrash size={18} /></button>}
                                </div>
                            ),
                        },
                    ]}
                />
            </Panel>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{editing === "new" ? "Tambah Pegawai" : "Ubah Pegawai"}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Nama" error={errors.name}>
                            <input className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                        </Field>
                        <Field label="NIP" error={errors.nip} hint="Kosongkan untuk PPNPN tanpa NIP">
                            <input className={inputClass + " font-mono"} value={form.nip} onChange={(e) => setForm({ ...form, nip: e.target.value })} />
                        </Field>
                        <Field label="Jenis pegawai" error={errors.employee_type}>
                            <select className={inputClass} value={form.employee_type} onChange={(e) => setForm({ ...form, employee_type: e.target.value })}>
                                {types.map((t) => <option key={t}>{t}</option>)}
                            </select>
                        </Field>
                        <Field label="Status" error={errors.employment_status} hint="AKTIF, MUTASI, PENSIUN, KELUAR, dll.">
                            <input className={inputClass} list="employment-statuses" value={form.employment_status} onChange={(e) => setForm({ ...form, employment_status: e.target.value.toUpperCase() })} />
                            <datalist id="employment-statuses">
                                {["AKTIF", "MUTASI", "PENSIUN", "KELUAR", ...statuses].filter((v, i, a) => a.indexOf(v) === i).map((s) => <option key={s} value={s} />)}
                            </datalist>
                        </Field>
                        <Field label="Unit kerja" error={errors.work_unit}>
                            <input className={inputClass} value={form.work_unit} onChange={(e) => setForm({ ...form, work_unit: e.target.value })} />
                        </Field>
                        <Field label="Jabatan" error={errors.position}>
                            <input className={inputClass} value={form.position} onChange={(e) => setForm({ ...form, position: e.target.value })} />
                        </Field>
                        <Field label="Pangkat" error={errors.rank}>
                            <input className={inputClass} value={form.rank} onChange={(e) => setForm({ ...form, rank: e.target.value })} />
                        </Field>
                        <Field label="Golongan" error={errors.grade}>
                            <input className={inputClass} value={form.grade} onChange={(e) => setForm({ ...form, grade: e.target.value })} />
                        </Field>
                        <Field label="Aktif sejak" error={errors.active_from} hint="Kosongkan bila tidak tahu">
                            <input type="date" className={inputClass} value={form.active_from} onChange={(e) => setForm({ ...form, active_from: e.target.value })} />
                        </Field>
                        <Field label="Aktif sampai" error={errors.active_until} hint="Tanggal mutasi/pensiun/keluar. Kosong = masih aktif. Menentukan hak SHU per tahun.">
                            <input type="date" className={inputClass} value={form.active_until} onChange={(e) => setForm({ ...form, active_until: e.target.value })} />
                        </Field>
                    </div>
                    <Field label="Nama lain / alias" error={errors.aliases} hint="Pisahkan dengan koma. Contoh: ADHI, ADHI Y, ADHI YOGA — dipakai untuk mencocokkan nama di Excel penjualan & simpanan.">
                        <textarea className={inputClass} rows={2} value={form.aliases} onChange={(e) => setForm({ ...form, aliases: e.target.value })} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditing(null)} className={btn.secondary}>Batal</button>
                        <button type="submit" className={btn.primary}>Simpan</button>
                    </div>
                </form>
            </Modal>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
