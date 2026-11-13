import React, { useMemo } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconBuildingWarehouse } from "@tabler/icons-react";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputClass, rupiah, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

/** Daftar penitip barang konsinyasi + ringkasan stok titipan & hutang ke penitip. */
export default function Index({ consignors, employees, numberCode }) {
    useFlashToasts();
    const people = useMemo(() => employeeOptions(employees), [employees]);
    const form = useForm({ name: "", customer_id: "", phone: "", bank_name: "", bank_account: "", account_holder: "", notes: "", is_active: true });
    const pickEmployee = (id) => {
        const e = employees.find((x) => x.id === id);
        form.setData((d) => ({ ...d, customer_id: id || "", name: d.name || e?.name || "", account_holder: d.account_holder || e?.name || "" }));
    };
    const sum = (k) => consignors.reduce((s, c) => s + (c.summary?.[k] || 0), 0);

    return (
        <>
            <Head title="Konsinyasi" />
            <PageHeader
                icon={IconBuildingWarehouse}
                title="Konsinyasi (barang titipan)"
                subtitle="Penitip menaruh barang, koperasi menjual. Barang bukan milik koperasi: koperasi hanya dapat selisih harga jual dan harga titip. Bagian penitip dibayar lewat Instruksi Pembayaran."
            />
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Penitip aktif" value={consignors.filter((c) => c.is_active).length} />
                <Stat label="Stok titipan (nilai jual)" value={rupiah(sum("stock_value"))} helper={`${sum("stock").toLocaleString("id-ID")} unit`} />
                <Stat label="Penjualan titipan" value={rupiah(sum("sales"))} helper={`Komisi koperasi ${rupiah(sum("sales") - sum("owed"))}`} />
                <Stat label="Hutang ke penitip" value={rupiah(sum("balance"))} helper={`Belum dibuat instruksi ${rupiah(sum("unbilled"))}`} />
            </div>
            <div className="grid gap-4 xl:grid-cols-[1fr_24rem]">
                <Panel title="Penitip">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-slate-50 dark:bg-slate-800/50">
                                <tr>
                                    <th className={th}>Nama</th><th className={`${th} text-right`}>Barang</th><th className={`${th} text-right`}>Stok</th>
                                    <th className={`${th} text-right`}>Penjualan</th><th className={`${th} text-right`}>Hutang</th><th className={th}>Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {consignors.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-3 py-1.5 text-sm">
                                            <Link className="font-medium text-primary-600 hover:underline" href={route("consignments.show", c.id)}>{c.name}</Link>
                                            {c.customer && <span className="block text-xs text-slate-500">Pegawai{c.customer.nip ? ` · ${c.customer.nip}` : ""}</span>}
                                        </td>
                                        <td className={`${td} text-right`}>{c.summary?.items || 0}</td>
                                        <td className={`${td} text-right`}>{(c.summary?.stock || 0).toLocaleString("id-ID")}</td>
                                        <td className={`${td} text-right font-mono`}>{rupiah(c.summary?.sales)}</td>
                                        <td className={`${td} text-right font-mono`}>{rupiah(c.summary?.balance)}</td>
                                        <td className={td}>{c.is_active ? <Badge color="green">aktif</Badge> : <Badge>berhenti</Badge>}</td>
                                    </tr>
                                ))}
                                {!consignors.length && (
                                    <tr><td colSpan={6} className="p-4 text-center text-sm text-slate-500">Belum ada penitip. Tambahkan di samping.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Panel>
                <Panel title="Tambah penitip">
                    <form className="space-y-3 p-3" onSubmit={(e) => { e.preventDefault(); form.post(route("consignments.store")); }}>
                        <Field label="Pegawai (bila penitip pegawai)">
                            <SearchSelect options={people} value={form.data.customer_id} onChange={pickEmployee} placeholder="Cari pegawai…" clearable />
                        </Field>
                        <Field label="Nama penitip" error={form.errors.name}>
                            <input className={inputClass} value={form.data.name} onChange={(e) => form.setData("name", e.target.value)} required />
                        </Field>
                        <Field label="Telepon"><input className={inputClass} value={form.data.phone} onChange={(e) => form.setData("phone", e.target.value)} /></Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Bank"><input className={inputClass} value={form.data.bank_name} onChange={(e) => form.setData("bank_name", e.target.value)} /></Field>
                            <Field label="No. rekening"><input className={inputClass} value={form.data.bank_account} onChange={(e) => form.setData("bank_account", e.target.value)} /></Field>
                        </div>
                        <Field label="Nama pemilik rekening"><input className={inputClass} value={form.data.account_holder} onChange={(e) => form.setData("account_holder", e.target.value)} /></Field>
                        <button type="submit" className={btn.primary} disabled={form.processing}>Simpan penitip</button>
                        <p className="text-xs text-slate-500">Nomor Instruksi Pembayaran: urut per tahun, mis. 30/{numberCode}/XI/26.</p>
                    </form>
                </Panel>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
