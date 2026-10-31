import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Modal from "@/Components/Modal";
import { IconAlertTriangle, IconArrowLeft, IconCash, IconCheck, IconCircleCheck, IconCircleDashed, IconPencil, IconPrinter, IconTrash, IconX } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, MONTHS, PageHeader, Panel, Stat, btn, inputClass, rupiah, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput } from "@/Components/Cooperative/inputs";
import { STATUS_COLOR } from "./Index";

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "-");
const fmtMonth = (d) => { const x = new Date(d); return `${MONTHS[x.getMonth()]} ${x.getFullYear()}`; };
const SOURCE = { manual: "manual", potong_gaji: "potong gaji", pelunasan: "pelunasan", import: "impor Excel" };

export default function Show({ financing: f, typeLabel, statusLabels, steps, outstanding, otherActive, mutationWarning }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const [modal, setModal] = useState(null);
    const [reason, setReason] = useState("");
    const [disburse, setDisburse] = useState({
        disbursed_at: new Date().toISOString().slice(0, 10),
        transferred_amount: f.type === "barang" ? f.principal - f.admin_fee : f.principal,
        first_due_month: "",
    });
    const post = (name, params, data = {}, confirmText) => {
        if (confirmText && !confirm(confirmText)) return;
        router.post(route(name, params), data, { preserveScroll: true, onSuccess: () => setModal(null) });
    };
    const paid = f.installments.filter((i) => i.paid_at).length;
    const canEdit = ["pengajuan", "diperiksa", "direview", "disetujui"].includes(f.status) && can("financing-create");

    const timeline = [
        { label: "Pengajuan dibuat", done: true, when: f.created_at, who: null },
        { label: steps.check.label, done: !!f.checked_at, when: f.checked_at, who: f.checker?.name, step: "check", permission: steps.check.permission, from: steps.check.from },
        { label: steps.review.label, done: !!f.reviewed_at, when: f.reviewed_at, who: f.reviewer?.name, step: "review", permission: steps.review.permission, from: steps.review.from },
        { label: steps.approve.label, done: !!f.approved_at, when: f.approved_at, who: f.approver?.name, step: "approve", permission: steps.approve.permission, from: steps.approve.from },
        { label: "Dana dicairkan (Bendahara)", done: !!f.disbursed_at, when: f.disbursed_at, who: null, disburse: true },
    ];

    return (
        <>
            <Head title={`${typeLabel} – ${f.customer.name}`} />
            <PageHeader
                icon={IconCash}
                title={<>{typeLabel}: {f.customer.name} <Badge color={STATUS_COLOR[f.status]}>{statusLabels[f.status]}</Badge> {f.is_bad_debt && <Badge color="red">Macet</Badge>}</>}
                subtitle={[f.number, f.type === "barang" ? f.item_name : null, f.customer.nip, f.customer.employee_type].filter(Boolean).join(" · ")}
                actions={
                    <>
                        <Link href={route("financing.index", f.type)} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>
                        <a href={route("financing.print", [f.id, "formulir"])} target="_blank" className={btn.secondary}><IconPrinter size={18} /> Cetak formulir</a>
                        {f.disbursed_at && <a href={route("financing.print", [f.id, "pencairan"])} target="_blank" className={btn.secondary}><IconPrinter size={18} /> Bukti pencairan</a>}
                        {canEdit && <Link href={route("financing.edit", f.id)} className={btn.secondary}><IconPencil size={18} /> Ubah</Link>}
                        {["pengajuan", "ditolak"].includes(f.status) && can("financing-create") && (
                            <button onClick={() => confirm("Hapus pengajuan ini?") && router.delete(route("financing.destroy", f.id))} className={btn.secondary}><IconTrash size={18} /></button>
                        )}
                    </>
                }
            />

            {mutationWarning && (
                <p className="mb-4 flex items-center gap-2 rounded-xl bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-900/20 dark:text-warning-300">
                    <IconAlertTriangle size={18} /> Pegawai ini tercatat mutasi/keluar ({fmtDate(f.customer.active_until)}). Sesuai ketentuan, sisa angsuran wajib dilunasi sebelum pindah.
                </p>
            )}
            {otherActive.length > 0 && (
                <p className="mb-4 text-sm text-slate-600 dark:text-slate-400">
                    Pembiayaan lain yang masih berjalan:{" "}
                    {otherActive.map((o) => <Link key={o.id} href={route("financing.show", o.id)} className="mr-2 text-primary-600 hover:underline">{o.type} {rupiah(o.principal)} (sisa {rupiah(o.outstanding)})</Link>)}
                </p>
            )}

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label={f.type === "barang" ? "Harga barang" : "Pembiayaan"} value={rupiah(f.principal)} helper={f.transferred_amount != null ? `ditransfer ${rupiah(f.transferred_amount)}` : null} />
                <Stat label={f.type === "barang" ? "Bagi hasil + biaya lain" : "Biaya formulir"} value={rupiah(f.margin_amount + f.admin_fee)} helper={f.type === "barang" ? `${f.margin_percent}% = ${rupiah(f.margin_amount)} + biaya ${rupiah(f.admin_fee)}` : "pendapatan koperasi"} />
                <Stat label="Cicilan" value={rupiah(f.installment)} helper={`${f.tenor} bulan, total ${rupiah(f.principal + f.margin_amount)}`} />
                <Stat label="Terbayar" value={`${paid} / ${f.tenor}`} helper={f.disbursed_at ? `cair ${fmtDate(f.disbursed_at)}` : "belum cair"} />
                <Stat label="Sisa" value={rupiah(outstanding)} />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <Panel title="Alur persetujuan">
                    <ol className="space-y-4 p-4">
                        {timeline.map((t, i) => {
                            const actionable = f.status !== "ditolak" && !t.done && (t.step ? f.status === t.from && can(t.permission) : t.disburse && f.status === "disetujui" && can("financing-disburse"));
                            return (
                                <li key={i} className="flex gap-3">
                                    {t.done ? <IconCircleCheck className="shrink-0 text-success-600" size={22} /> : <IconCircleDashed className="shrink-0 text-slate-400" size={22} />}
                                    <div className="flex-1 text-sm">
                                        <div className={t.done ? "font-medium text-slate-900 dark:text-white" : "text-slate-500"}>{t.label}</div>
                                        {t.done && t.when && <div className="text-xs text-slate-500">{fmtDate(t.when)}{t.who ? ` · ${t.who}` : ""}</div>}
                                        {actionable && (
                                            <button
                                                onClick={() => (t.disburse ? setModal("disburse") : post("financing.advance", [f.id, t.step], {}, `${t.label}?`))}
                                                className={btn.primary + " mt-2 py-1 text-xs"}
                                            >
                                                <IconCheck size={14} /> {t.disburse ? "Cairkan" : "Setujui tahap ini"}
                                            </button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                    {f.status === "ditolak" && (
                        <p className="mx-4 mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-300">
                            Ditolak {fmtDate(f.rejected_at)}{f.rejecter ? ` oleh ${f.rejecter.name}` : ""}: {f.rejection_reason}
                        </p>
                    )}
                    {["pengajuan", "diperiksa", "direview", "disetujui"].includes(f.status) && (can("financing-check") || can("financing-review") || can("financing-approve")) && (
                        <div className="px-4 pb-4"><button onClick={() => setModal("reject")} className="text-sm text-danger-600 hover:underline"><IconX size={14} className="inline" /> Tolak pengajuan</button></div>
                    )}
                </Panel>

                <Panel
                    title="Jadwal cicilan"
                    className="lg:col-span-2"
                    actions={
                        f.status === "berjalan" && (
                            <div className="flex gap-2">
                                {can("financing-approve") && <button onClick={() => post("financing.bad-debt", f.id, {}, f.is_bad_debt ? "Hapus tanda macet?" : "Tandai pembiayaan ini macet?")} className={btn.secondary + " py-1 text-xs"}>{f.is_bad_debt ? "Hapus tanda macet" : "Tandai macet"}</button>}
                                {can("financing-disburse") && <button onClick={() => post("financing.payoff", f.id, {}, `Lunasi semua sisa cicilan (${rupiah(outstanding)}) sekarang?`)} className={btn.primary + " py-1 text-xs"}>Pelunasan dipercepat</button>}
                            </div>
                        )
                    }
                >
                    {f.installments.length === 0 ? (
                        <p className="p-6 text-sm text-slate-500">Jadwal cicilan dibuat otomatis saat dana dicairkan.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-slate-50 dark:bg-slate-950">
                                    <tr><th className={th}>Ke</th><th className={th}>Bulan</th><th className={th + " text-right"}>Jumlah</th><th className={th}>Status</th><th className={th}></th></tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {f.installments.map((i) => (
                                        <tr key={i.id}>
                                            <td className={td}>{i.seq}</td>
                                            <td className={td}>{fmtMonth(i.due_month)}</td>
                                            <td className={td + " text-right"}>{rupiah(i.amount)}</td>
                                            <td className={td}>{i.paid_at ? <Badge color="green">Lunas · {SOURCE[i.paid_source] || i.paid_source}</Badge> : <Badge color={new Date(i.due_month) < new Date() ? "amber" : "gray"}>{new Date(i.due_month) < new Date() ? "Belum dibayar" : "Akan datang"}</Badge>}</td>
                                            <td className={td + " text-right"}>
                                                {can("financing-disburse") && (
                                                    <button onClick={() => post("financing.installments.toggle", i.id)} className="text-xs text-primary-600 hover:underline">{i.paid_at ? "Batalkan" : "Tandai lunas"}</button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </div>

            {(f.bank_name || f.phone || f.family_name || f.notes) && (
                <Panel title="Data formulir" className="mt-6">
                    <dl className="grid gap-3 p-4 text-sm sm:grid-cols-3">
                        <div><dt className="text-slate-500">Rekening</dt><dd>{[f.bank_name, f.bank_account].filter(Boolean).join(" ") || "-"}</dd></div>
                        <div><dt className="text-slate-500">Telepon</dt><dd>{f.phone || "-"}</dd></div>
                        <div><dt className="text-slate-500">Keluarga</dt><dd>{[f.family_name, f.family_relation, f.family_phone].filter(Boolean).join(" · ") || "-"}</dd></div>
                        {f.notes && <div className="sm:col-span-3"><dt className="text-slate-500">Catatan</dt><dd className="whitespace-pre-line">{f.notes}</dd></div>}
                    </dl>
                </Panel>
            )}

            <Modal show={modal === "reject"} onClose={() => setModal(null)}>
                <form onSubmit={(e) => { e.preventDefault(); post("financing.reject", f.id, { reason }); }} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Tolak pengajuan</h2>
                    <Field label="Alasan"><textarea rows={3} className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} required /></Field>
                    <div className="flex justify-end gap-2"><button type="button" onClick={() => setModal(null)} className={btn.secondary}>Batal</button><button type="submit" className={btn.danger}>Tolak</button></div>
                </form>
            </Modal>

            <Modal show={modal === "disburse"} onClose={() => setModal(null)}>
                <form onSubmit={(e) => { e.preventDefault(); post("financing.disburse", f.id, disburse); }} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Cairkan dana</h2>
                    <p className="text-sm text-slate-500">
                        {f.type === "dana" ? "Pastikan biaya formulir sudah dibayar." : `Biaya lain ${rupiah(f.admin_fee)} dipotong dari dana yang ditransfer.`} Jadwal cicilan dibuat mulai bulan berikutnya.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tanggal transfer"><input type="date" className={inputClass} value={disburse.disbursed_at} onChange={(e) => setDisburse({ ...disburse, disbursed_at: e.target.value })} required /></Field>
                        <Field label="Jumlah ditransfer" hint="Kurangi bila sisa pinjaman lama dilunasi dari pencairan ini"><MoneyInput value={disburse.transferred_amount} onChange={(v) => setDisburse({ ...disburse, transferred_amount: v })} /></Field>
                        <Field label="Cicilan pertama (opsional)" hint="Kosong = bulan setelah transfer"><input type="month" className={inputClass} value={disburse.first_due_month?.slice(0, 7) || ""} onChange={(e) => setDisburse({ ...disburse, first_due_month: e.target.value ? `${e.target.value}-01` : "" })} /></Field>
                    </div>
                    <div className="flex justify-end gap-2"><button type="button" onClick={() => setModal(null)} className={btn.secondary}>Batal</button><button type="submit" className={btn.primary}>Cairkan</button></div>
                </form>
            </Modal>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
