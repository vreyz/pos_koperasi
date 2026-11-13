import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconBuildingWarehouse, IconPrinter, IconTrash } from "@tabler/icons-react";
import { Badge, Field, PageHeader, Panel, Stat, btn, inputBase, inputClass, rupiah, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, SearchSelect } from "@/Components/Cooperative/inputs";
import { fmtDate } from "@/Components/Accounting/shared";

const fmtQty = (q) => Number(q || 0).toLocaleString("id-ID");

/** Satu penitip: barang titipan, titip/tarik, penjualan per periode, Instruksi Pembayaran. */
export default function Show({ consignor, summary, products, movements, payments, period, sales, nextNumber, today, warehouses, categories, otherProducts, cashAccounts }) {
    useFlashToasts();
    const defaultWh = warehouses[0]?.id || "";
    const productOpts = useMemo(() => products.map((p) => ({ value: p.id, label: p.title, sub: `stok ${fmtQty(p.stock)}` })), [products]);
    const otherOpts = useMemo(() => otherProducts.map((p) => ({ value: p.id, label: p.title, sub: p.barcode })), [otherProducts]);

    const info = useForm({ name: consignor.name, customer_id: consignor.customer_id, phone: consignor.phone || "", bank_name: consignor.bank_name || "", bank_account: consignor.bank_account || "", account_holder: consignor.account_holder || "", notes: consignor.notes || "", is_active: consignor.is_active });
    const add = useForm({ title: "", barcode: "", category_id: categories[0]?.id || "", buy_price: 0, sell_price: 0, qty: 0, warehouse_id: defaultWh, date: today });
    const mv = useForm({ product_id: "", warehouse_id: defaultWh, type: "in", qty: 1, date: today, note: "" });
    const attach = useForm({ product_id: "" });
    const ip = useForm({ number: nextNumber, date: today, period_from: period.from, period_to: period.to, signer: "" });
    const [pay, setPay] = useState({ id: null, paid_at: today, cash_account: "11102" });
    const [edit, setEdit] = useState(null);

    const reloadPeriod = (from, to) => router.get(route("consignments.show", consignor.id), { from, to }, { preserveScroll: true, preserveState: true, only: ["sales", "period"] });
    const setIpDate = async (date) => {
        ip.setData("date", date);
        try {
            const r = await fetch(route("consignments.next-number", { date, type: "consignment" }), { headers: { Accept: "application/json" } });
            const j = await r.json();
            ip.setData((d) => ({ ...d, date, number: j.number }));
        } catch { /* nomor tetap */ }
    };
    const totalPay = sales.reduce((s, r) => s + r.payment, 0);
    const totalSales = sales.reduce((s, r) => s + r.sales, 0);

    return (
        <>
            <Head title={`Konsinyasi - ${consignor.name}`} />
            <PageHeader
                icon={IconBuildingWarehouse}
                title={consignor.name}
                subtitle={<><Link className="text-primary-600 hover:underline" href={route("consignments.index")}>Konsinyasi</Link> · penitip {consignor.is_active ? "aktif" : "berhenti"}</>}
            />
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="Stok titipan" value={fmtQty(summary?.stock)} helper={`Nilai jual ${rupiah(summary?.stock_value)}`} />
                <Stat label="Total penjualan" value={rupiah(summary?.sales)} helper={`Komisi koperasi ${rupiah((summary?.sales || 0) - (summary?.owed || 0))}`} />
                <Stat label="Sudah dibayar" value={rupiah(summary?.paid)} />
                <Stat label="Hutang ke penitip" value={rupiah(summary?.balance)} helper={`Belum dibuat instruksi ${rupiah(summary?.unbilled)}`} />
            </div>

            <div className="grid gap-4 xl:grid-cols-[1fr_24rem]">
                <div className="space-y-4">
                    <Panel title="Barang titipan">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-slate-50 dark:bg-slate-800/50">
                                    <tr>
                                        <th className={th}>Barang</th><th className={`${th} text-right`}>Harga titip</th><th className={`${th} text-right`}>Harga jual</th>
                                        <th className={`${th} text-right`}>Komisi/unit</th><th className={`${th} text-right`}>Stok</th><th className={th}></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {products.map((p) => (
                                        <tr key={p.id}>
                                            <td className="px-3 py-1.5 text-sm">{p.title}<span className="block text-xs text-slate-500">{p.barcode}{p.warehouses.length ? " · " + p.warehouses.map((w) => `${w.name} ${fmtQty(w.stock)}`).join(", ") : ""}</span></td>
                                            {edit?.id === p.id ? (
                                                <>
                                                    <td className={td}><MoneyInput value={edit.buy_price} onChange={(v) => setEdit({ ...edit, buy_price: v })} className="w-32" /></td>
                                                    <td className={td}><MoneyInput value={edit.sell_price} onChange={(v) => setEdit({ ...edit, sell_price: v })} className="w-32" /></td>
                                                    <td className={`${td} text-right font-mono`}>{rupiah(edit.sell_price - edit.buy_price)}</td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className={`${td} text-right font-mono`}>{rupiah(p.buy_price)}</td>
                                                    <td className={`${td} text-right font-mono`}>{rupiah(p.sell_price)}</td>
                                                    <td className={`${td} text-right font-mono`}>{rupiah(p.sell_price - p.buy_price)}</td>
                                                </>
                                            )}
                                            <td className={`${td} text-right`}>{fmtQty(p.stock)}</td>
                                            <td className={`${td} text-right`}>
                                                {edit?.id === p.id ? (
                                                    <>
                                                        <button type="button" className="mr-2 text-xs font-medium text-primary-600" onClick={() => router.put(route("consignments.products.price", [consignor.id, p.id]), { buy_price: edit.buy_price, sell_price: edit.sell_price }, { preserveScroll: true, onSuccess: () => setEdit(null) })}>Simpan</button>
                                                        <button type="button" className="text-xs text-slate-500" onClick={() => setEdit(null)}>Batal</button>
                                                    </>
                                                ) : (
                                                    <>
                                                        <button type="button" className="mr-2 text-xs font-medium text-primary-600" onClick={() => setEdit({ id: p.id, buy_price: p.buy_price, sell_price: p.sell_price })}>Ubah harga</button>
                                                        <button type="button" className="text-xs text-slate-500" onClick={() => confirm(`Lepaskan ${p.title} dari daftar titipan? Penjualan berikutnya dianggap barang koperasi.`) && router.post(route("consignments.products.attach", consignor.id), { product_id: p.id, detach: true }, { preserveScroll: true })}>Lepas</button>
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {!products.length && <tr><td colSpan={6} className="p-4 text-center text-sm text-slate-500">Belum ada barang titipan.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <p className="px-3 py-2 text-xs text-slate-500">Harga titip = yang dibayar ke penitip per unit terjual. Komisi = harga jual − harga titip (pendapatan koperasi).</p>
                    </Panel>

                    <Panel title="Penjualan & Instruksi Pembayaran">
                        <div className="flex flex-wrap items-end gap-2 p-3">
                            <label className="text-xs text-slate-500">Periode
                                <input type="date" className={`${inputBase} ml-1`} value={ip.data.period_from} onChange={(e) => { ip.setData("period_from", e.target.value); reloadPeriod(e.target.value, ip.data.period_to); }} />
                            </label>
                            <label className="text-xs text-slate-500">s.d.
                                <input type="date" className={`${inputBase} ml-1`} value={ip.data.period_to} onChange={(e) => { ip.setData("period_to", e.target.value); reloadPeriod(ip.data.period_from, e.target.value); }} />
                            </label>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-slate-50 dark:bg-slate-800/50">
                                    <tr>
                                        <th className={th}>Nama barang</th><th className={`${th} text-right`}>Terjual</th><th className={th}>Satuan</th><th className={`${th} text-right`}>Sisa stok</th>
                                        <th className={`${th} text-right`}>Harga titip</th><th className={`${th} text-right`}>Harga jual</th><th className={`${th} text-right`}>Pembayaran</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {sales.map((r) => (
                                        <tr key={r.product_id}>
                                            <td className="px-3 py-1.5 text-sm">{r.title}</td>
                                            <td className={`${td} text-right`}>{fmtQty(r.qty)}</td>
                                            <td className={td}>{r.unit}</td>
                                            <td className={`${td} text-right`}>{fmtQty(r.stock)}</td>
                                            <td className={`${td} text-right font-mono`}>{rupiah(r.consign_price)}</td>
                                            <td className={`${td} text-right font-mono`}>{rupiah(r.sell_price)}</td>
                                            <td className={`${td} text-right font-mono`}>{rupiah(r.payment)}</td>
                                        </tr>
                                    ))}
                                    {!sales.length && <tr><td colSpan={7} className="p-4 text-center text-sm text-slate-500">Belum ada barang titipan terjual di periode ini.</td></tr>}
                                    {sales.length > 0 && (
                                        <tr className="font-semibold">
                                            <td className={td} colSpan={6}>Jumlah (penjualan {rupiah(totalSales)}, komisi {rupiah(totalSales - totalPay)})</td>
                                            <td className={`${td} text-right font-mono`}>{rupiah(totalPay)}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <form className="grid gap-2 border-t border-slate-100 p-3 sm:grid-cols-4 dark:border-slate-800" onSubmit={(e) => { e.preventDefault(); ip.post(route("consignments.payments.store", consignor.id), { preserveScroll: true }); }}>
                            <Field label="Tanggal instruksi"><input type="date" className={inputClass} value={ip.data.date} onChange={(e) => setIpDate(e.target.value)} /></Field>
                            <Field label="Nomor" error={ip.errors.number} hint="Urut per tahun; boleh diubah"><input className={inputClass} value={ip.data.number} onChange={(e) => ip.setData("number", e.target.value)} /></Field>
                            <Field label="Penanda tangan"><input className={inputClass} value={ip.data.signer} onChange={(e) => ip.setData("signer", e.target.value)} placeholder="Nama pengurus" /></Field>
                            <div className="flex items-end"><button type="submit" className={btn.primary} disabled={ip.processing || !sales.length}>Buat instruksi pembayaran</button></div>
                            {ip.errors.period_from && <p className="text-xs text-danger-600 sm:col-span-4">{ip.errors.period_from}</p>}
                        </form>
                    </Panel>

                    <Panel title="Instruksi pembayaran">
                        <table className="w-full">
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.map((p) => (
                                    <tr key={p.id}>
                                        <td className={`${td} font-mono text-xs`}>{p.number}</td>
                                        <td className={td}>{fmtDate(p.date)}<span className="block text-xs text-slate-500">{fmtDate(p.period_from)} s.d. {fmtDate(p.period_to)}</span></td>
                                        <td className={`${td} text-right font-mono`}>{rupiah(p.total)}</td>
                                        <td className={td}>{p.status === "paid" ? <Badge color="green">dibayar {fmtDate(p.paid_at)}</Badge> : <Badge color="amber">belum dibayar</Badge>}</td>
                                        <td className={`${td} text-right`}>
                                            <a className="mr-3 inline-flex items-center gap-1 text-xs font-medium text-primary-600" href={route("consignments.payments.print", p.id)} target="_blank" rel="noreferrer"><IconPrinter size={14} /> Cetak</a>
                                            {p.status !== "paid" && (
                                                <>
                                                    <button type="button" className="mr-3 text-xs font-medium text-success-700" onClick={() => setPay({ ...pay, id: p.id })}>Tandai dibayar</button>
                                                    <button type="button" className="text-xs text-danger-600" onClick={() => confirm(`Hapus ${p.number}?`) && router.delete(route("consignments.payments.destroy", p.id), { preserveScroll: true })}><IconTrash size={14} /></button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {!payments.length && <tr><td className="p-4 text-center text-sm text-slate-500">Belum ada instruksi pembayaran.</td></tr>}
                            </tbody>
                        </table>
                        {pay.id && (
                            <div className="flex flex-wrap items-end gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                                <Field label="Tanggal bayar"><input type="date" className={inputClass} value={pay.paid_at} onChange={(e) => setPay({ ...pay, paid_at: e.target.value })} /></Field>
                                <Field label="Dibayar dari">
                                    <select className={inputClass} value={pay.cash_account} onChange={(e) => setPay({ ...pay, cash_account: e.target.value })}>
                                        {Object.entries(cashAccounts).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select>
                                </Field>
                                <button type="button" className={btn.primary} onClick={() => router.post(route("consignments.payments.pay", pay.id), { paid_at: pay.paid_at, cash_account: pay.cash_account }, { preserveScroll: true, onSuccess: () => setPay({ ...pay, id: null }) })}>Simpan pembayaran</button>
                                <button type="button" className={btn.secondary} onClick={() => setPay({ ...pay, id: null })}>Batal</button>
                            </div>
                        )}
                    </Panel>

                    <Panel title="Riwayat titip & tarik">
                        <table className="w-full">
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {movements.map((m) => (
                                    <tr key={m.id}>
                                        <td className={td}>{fmtDate(m.date?.slice(0, 10))}</td>
                                        <td className={td}>{m.type === "in" ? <Badge color="green">titip</Badge> : <Badge color="amber">tarik</Badge>}</td>
                                        <td className="px-3 py-1.5 text-sm">{m.product?.title}<span className="block text-xs text-slate-500">{m.warehouse?.name}{m.note ? ` · ${m.note}` : ""}</span></td>
                                        <td className={`${td} text-right`}>{m.type === "in" ? "+" : "−"}{fmtQty(m.qty)}</td>
                                    </tr>
                                ))}
                                {!movements.length && <tr><td className="p-4 text-center text-sm text-slate-500">Belum ada catatan.</td></tr>}
                            </tbody>
                        </table>
                    </Panel>
                </div>

                <div className="space-y-4">
                    <Panel title="Titip masuk / tarik barang">
                        <form className="space-y-3 p-3" onSubmit={(e) => { e.preventDefault(); mv.post(route("consignments.moves.store", consignor.id), { preserveScroll: true, onSuccess: () => mv.reset("qty", "note") }); }}>
                            <div className="flex gap-2">
                                {[["in", "Titip masuk"], ["out", "Ditarik pemilik"]].map(([k, l]) => (
                                    <button key={k} type="button" onClick={() => mv.setData("type", k)} className={mv.data.type === k ? btn.primary : btn.secondary}>{l}</button>
                                ))}
                            </div>
                            <Field label="Barang" error={mv.errors.product_id}><SearchSelect options={productOpts} value={mv.data.product_id} onChange={(v) => mv.setData("product_id", v)} placeholder="Pilih barang titipan…" /></Field>
                            <Field label="Gudang/outlet">
                                <select className={inputClass} value={mv.data.warehouse_id} onChange={(e) => mv.setData("warehouse_id", e.target.value)}>
                                    {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                </select>
                            </Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Jumlah" error={mv.errors.qty}><input type="number" min="1" className={inputClass} value={mv.data.qty} onChange={(e) => mv.setData("qty", e.target.value)} /></Field>
                                <Field label="Tanggal"><input type="date" className={inputClass} value={mv.data.date} onChange={(e) => mv.setData("date", e.target.value)} /></Field>
                            </div>
                            <Field label="Catatan"><input className={inputClass} value={mv.data.note} onChange={(e) => mv.setData("note", e.target.value)} /></Field>
                            <button type="submit" className={btn.primary} disabled={mv.processing || !mv.data.product_id}>Simpan</button>
                            <p className="text-xs text-slate-500">Hanya mengubah stok. Tidak ada jurnal karena barang bukan milik koperasi.</p>
                        </form>
                    </Panel>

                    <Panel title="Tambah barang titipan baru">
                        <form className="space-y-3 p-3" onSubmit={(e) => { e.preventDefault(); add.post(route("consignments.products.store", consignor.id), { preserveScroll: true, onSuccess: () => add.reset() }); }}>
                            <Field label="Nama barang" error={add.errors.title}><input className={inputClass} value={add.data.title} onChange={(e) => add.setData("title", e.target.value)} required /></Field>
                            <Field label="Barcode (kosong = otomatis)" error={add.errors.barcode}><input className={inputClass} value={add.data.barcode} onChange={(e) => add.setData("barcode", e.target.value)} /></Field>
                            <Field label="Kategori">
                                <select className={inputClass} value={add.data.category_id} onChange={(e) => add.setData("category_id", e.target.value)}>
                                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Harga titip" error={add.errors.buy_price}><MoneyInput value={add.data.buy_price} onChange={(v) => add.setData("buy_price", v)} /></Field>
                                <Field label="Harga jual" error={add.errors.sell_price}><MoneyInput value={add.data.sell_price} onChange={(v) => add.setData("sell_price", v)} /></Field>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Jumlah dititip"><input type="number" min="0" className={inputClass} value={add.data.qty} onChange={(e) => add.setData("qty", e.target.value)} /></Field>
                                <Field label="Gudang/outlet">
                                    <select className={inputClass} value={add.data.warehouse_id} onChange={(e) => add.setData("warehouse_id", e.target.value)}>
                                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                </Field>
                            </div>
                            <button type="submit" className={btn.primary} disabled={add.processing}>Tambah barang</button>
                        </form>
                    </Panel>

                    <Panel title="Barang yang sudah ada jadi titipan">
                        <form className="space-y-3 p-3" onSubmit={(e) => { e.preventDefault(); attach.post(route("consignments.products.attach", consignor.id), { preserveScroll: true, onSuccess: () => attach.reset() }); }}>
                            <SearchSelect options={otherOpts} value={attach.data.product_id} onChange={(v) => attach.setData("product_id", v)} placeholder="Cari barang…" />
                            {attach.errors.product_id && <p className="text-xs text-danger-600">{attach.errors.product_id}</p>}
                            <button type="submit" className={btn.secondary} disabled={attach.processing || !attach.data.product_id}>Tandai sebagai titipan</button>
                            <p className="text-xs text-slate-500">Harga beli barang dipakai sebagai harga titip.</p>
                        </form>
                    </Panel>

                    <Panel title="Data penitip">
                        <form className="space-y-3 p-3" onSubmit={(e) => { e.preventDefault(); info.put(route("consignments.update", consignor.id), { preserveScroll: true }); }}>
                            <Field label="Nama" error={info.errors.name}><input className={inputClass} value={info.data.name} onChange={(e) => info.setData("name", e.target.value)} /></Field>
                            <Field label="Telepon"><input className={inputClass} value={info.data.phone} onChange={(e) => info.setData("phone", e.target.value)} /></Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Bank"><input className={inputClass} value={info.data.bank_name} onChange={(e) => info.setData("bank_name", e.target.value)} /></Field>
                                <Field label="No. rekening"><input className={inputClass} value={info.data.bank_account} onChange={(e) => info.setData("bank_account", e.target.value)} /></Field>
                            </div>
                            <Field label="Nama pemilik rekening"><input className={inputClass} value={info.data.account_holder} onChange={(e) => info.setData("account_holder", e.target.value)} /></Field>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!info.data.is_active} onChange={(e) => info.setData("is_active", e.target.checked)} /> Penitip aktif</label>
                            <button type="submit" className={btn.secondary} disabled={info.processing}>Simpan data penitip</button>
                        </form>
                    </Panel>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
