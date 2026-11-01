import React, { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconArrowLeft, IconCash } from "@tabler/icons-react";
import { Field, PageHeader, Panel, Stat, btn, inputClass, rupiah } from "@/Components/Cooperative/ui";
import { MoneyInput, PercentInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

export default function Form({ type, typeLabel, financing, employees, settings, preselect, nextNumber }) {
    const [form, setForm] = useState({
        customer_id: financing?.customer_id || preselect || "",
        number: financing?.number || "",
        applied_at: financing?.applied_at || new Date().toISOString().slice(0, 10),
        item_name: financing?.item_name || "",
        principal: financing?.principal || (type === "dana" ? 5000000 : ""),
        tenor: financing?.tenor || (type === "dana" ? Number(settings.financing_tenor_dana_max) : Number(settings.financing_tenor_barang)),
        margin_percent: financing?.margin_percent ?? settings.financing_margin_barang,
        admin_fee: financing?.admin_fee ?? "",
        bank_name: financing?.bank_name || "",
        bank_account: financing?.bank_account || "",
        phone: financing?.phone || "",
        family_name: financing?.family_name || "",
        family_relation: financing?.family_relation || "",
        family_phone: financing?.family_phone || "",
        notes: financing?.notes || "",
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);

    const employee = employees.find((e) => e.id === Number(form.customer_id));
    const ppnpn = employee?.employee_type === "PPNPN";
    const max = Number(ppnpn ? settings.financing_max_ppnpn : settings.financing_max_pegawai);
    const unit = 5000000; // biaya formulir per Rp 5 jt pembiayaan

    // Pratinjau angka yang juga dihitung ulang di server.
    const calc = useMemo(() => {
        const principal = Number(form.principal || 0);
        const tenor = Math.max(1, Number(form.tenor || 1));
        const margin = type === "barang" ? Math.round((principal * Number(form.margin_percent || 0)) / 100) : 0;
        const autoFee = type === "dana"
            ? Math.ceil(principal / unit) * Number(ppnpn ? settings.financing_fee_ppnpn : settings.financing_fee_pegawai)
            : Math.round((principal * Number(settings.financing_admin_barang)) / 100);
        const fee = form.admin_fee === "" ? autoFee : Number(form.admin_fee);
        return { margin, fee, autoFee, total: principal + margin, installment: Math.ceil((principal + margin) / tenor) };
    }, [form, ppnpn, settings, type, unit]);

    const submit = (e) => {
        e.preventDefault();
        setSaving(true);
        const options = { onError: setErrors, onFinish: () => setSaving(false) };
        financing ? router.put(route("financing.update", financing.id), form, options) : router.post(route("financing.store", type), form, options);
    };

    const amountOptions = [5000000, 10000000, 15000000].filter((v) => v <= max);

    return (
        <>
            <Head title={`${financing ? "Ubah" : "Pengajuan"} ${typeLabel}`} />
            <PageHeader
                icon={IconCash}
                title={`${financing ? "Ubah" : "Pengajuan"} ${typeLabel}`}
                subtitle="Isi sesuai formulir permohonan. Setelah disimpan, pengajuan diperiksa Divisi Pembiayaan, direview Kepala Unit Usaha, lalu disetujui Ketua sebelum dicairkan."
                actions={<Link href={financing ? route("financing.show", financing.id) : route("financing.index", type)} className={btn.secondary}><IconArrowLeft size={18} /> Kembali</Link>}
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Panel title="Pemohon & pembiayaan">
                        <div className="grid gap-4 p-4 sm:grid-cols-2">
                            <Field label="Pemohon (pegawai)" error={errors.customer_id}>
                                <SearchSelect required options={empOptions} value={form.customer_id} onChange={(v) => setForm({ ...form, customer_id: v })} placeholder="Cari nama / NIP…" />
                            </Field>
                            <Field label="Nomor formulir" error={errors.number} hint={financing ? null : `Kosongkan untuk nomor otomatis: ${nextNumber}`}>
                                <input className={inputClass} value={form.number} onChange={set("number")} placeholder={nextNumber || ""} />
                            </Field>
                            <Field label="Tanggal permohonan" error={errors.applied_at}>
                                <input type="date" className={inputClass} value={form.applied_at} onChange={set("applied_at")} />
                            </Field>
                            {type === "barang" && (
                                <Field label="Nama barang" error={errors.item_name}>
                                    <input className={inputClass} value={form.item_name} onChange={set("item_name")} required />
                                </Field>
                            )}
                            <Field
                                label={type === "barang" ? "Harga barang" : "Jumlah pembiayaan"}
                                error={errors.principal}
                                hint={type === "dana" && employee ? `Plafon ${ppnpn ? "PPNPN" : "pegawai"}: ${rupiah(max)}` : null}
                            >
                                <MoneyInput value={form.principal} onChange={(v) => setForm({ ...form, principal: v })} required />
                                {type === "dana" && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {amountOptions.map((v) => (
                                            <button type="button" key={v} onClick={() => setForm({ ...form, principal: v })} className={`rounded-lg border px-2 py-1 text-xs ${Number(form.principal) === v ? "border-primary-500 bg-primary-50 text-primary-700" : "border-slate-200 dark:border-slate-700"}`}>{rupiah(v)}</button>
                                        ))}
                                    </div>
                                )}
                            </Field>
                            <Field label="Jangka waktu (bulan)" error={errors.tenor} hint={type === "dana" ? `1 s.d. ${settings.financing_tenor_dana_max} bulan` : `bawaan ${settings.financing_tenor_barang} bulan`}>
                                <input type="number" min="1" max="36" className={inputClass} value={form.tenor} onChange={set("tenor")} required />
                            </Field>
                            {type === "barang" && (
                                <Field label="Bagi hasil" error={errors.margin_percent}>
                                    <PercentInput value={form.margin_percent} onChange={(v) => setForm({ ...form, margin_percent: v })} />
                                </Field>
                            )}
                            <Field label={type === "dana" ? "Biaya formulir" : "Biaya lain"} error={errors.admin_fee} hint={`Kosongkan untuk otomatis: ${rupiah(calc.autoFee)}`}>
                                <MoneyInput value={form.admin_fee} onChange={(v) => setForm({ ...form, admin_fee: v })} placeholder={calc.autoFee.toLocaleString("id-ID")} />
                            </Field>
                        </div>
                    </Panel>

                    <Panel title="Data rekening & keluarga (sesuai formulir)">
                        <div className="grid gap-4 p-4 sm:grid-cols-2">
                            <Field label="Nama bank" error={errors.bank_name}><input className={inputClass} value={form.bank_name} onChange={set("bank_name")} /></Field>
                            <Field label="Nomor rekening" error={errors.bank_account}><input className={inputClass} value={form.bank_account} onChange={set("bank_account")} /></Field>
                            <Field label="Nomor telepon" error={errors.phone}><input className={inputClass} value={form.phone} onChange={set("phone")} /></Field>
                            <Field label="Nama keluarga" error={errors.family_name}><input className={inputClass} value={form.family_name} onChange={set("family_name")} /></Field>
                            <Field label="Status keluarga" error={errors.family_relation}><input className={inputClass} value={form.family_relation} onChange={set("family_relation")} placeholder="suami / istri / orang tua" /></Field>
                            <Field label="Telepon keluarga" error={errors.family_phone}><input className={inputClass} value={form.family_phone} onChange={set("family_phone")} /></Field>
                            <div className="sm:col-span-2">
                                <Field label="Catatan" error={errors.notes}><textarea rows={2} className={inputClass} value={form.notes} onChange={set("notes")} /></Field>
                            </div>
                        </div>
                    </Panel>
                </div>

                <div className="space-y-3">
                    <Stat label={type === "barang" ? "Harga + bagi hasil" : "Total dikembalikan"} value={rupiah(calc.total)} helper={type === "barang" ? `bagi hasil ${rupiah(calc.margin)}` : "tanpa bunga"} />
                    <Stat label="Cicilan per bulan" value={rupiah(calc.installment)} helper={`${form.tenor || 0} kali, mulai bulan setelah pencairan`} />
                    <Stat label={type === "dana" ? "Biaya formulir" : "Biaya lain (dipotong saat cair)"} value={rupiah(calc.fee)} helper={type === "dana" ? `${ppnpn ? "PPNPN" : "pegawai"}: ${rupiah(ppnpn ? settings.financing_fee_ppnpn : settings.financing_fee_pegawai)} per ${rupiah(unit)}` : `${settings.financing_admin_barang}% dari harga`} />
                    {employee && <p className="text-xs text-slate-500">{[employee.position, employee.work_unit].filter(Boolean).join(" · ")}</p>}
                    <button type="submit" disabled={saving} className={btn.primary + " w-full justify-center"}>{saving ? "Menyimpan..." : financing ? "Simpan perubahan" : "Simpan pengajuan"}</button>
                </div>
            </form>
        </>
    );
}

Form.layout = (page) => <DashboardLayout children={page} />;
