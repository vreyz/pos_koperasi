import React, { useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconToolsKitchen2, IconPrinter, IconTrash, IconPlus } from "@tabler/icons-react";
import { Badge, Field, MONTHS, PageHeader, Panel, Stat, btn, inputBase, inputClass, th, td, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput } from "@/Components/Cooperative/inputs";
import { AccountingTabs, idr, fmtDate } from "@/Components/Accounting/shared";

const pct = (a, b) => (b ? ((100 * a) / b).toLocaleString("id-ID", { maximumFractionDigits: 2 }) + "%" : "-");

/**
 * Rekap kantin: penjualan (uang muka), bagi hasil koperasi, bagian penjaga, transfer ke penjaga, sisa hutang,
 * dan porsi aktual per bulan. Persen bagi hasil bisa diubah di sini.
 */
export default function Kantin({ year, opening, months, payments, kantinShare, kantinWarehouses, autoFrom, invoices = [], nextInvoiceNumber, lastInvoice, today }) {
    useFlashToasts();
    const [share, setShare] = useState(kantinShare);
    const tot = months.reduce((s, m) => ({ sales: s.sales + m.sales, share: s.share + m.share, keeper: s.keeper + m.keeper, paid: s.paid + m.paid }), { sales: 0, share: 0, keeper: 0, paid: 0 });
    const last = months.length ? months[months.length - 1].balance : opening;
    const goYear = (y) => router.get(route("accounting.kantin"), { year: y }, { preserveScroll: true });
    const saveShare = () => router.put(route("accounting.settings"), { kantin_share_percent: share }, { preserveScroll: true });

    return (
        <>
            <Head title="Rekap Kantin" />
            <PageHeader
                icon={IconToolsKitchen2}
                title={`Rekap Kantin ${year}`}
                subtitle="Penjualan kantin dicatat sebagai uang muka, lalu dibagi: bagi hasil koperasi dan bagian penjaga kantin (hutang). Bagian penjaga ditransfer berkala."
                actions={<button type="button" className={btn.secondary} onClick={() => window.print()}><IconPrinter size={16} /> Cetak</button>}
            />
            <AccountingTabs />
            <div className="mb-3 flex flex-wrap items-center gap-2 print:hidden">
                <button type="button" className={btn.secondary} onClick={() => goYear(year - 1)}>‹ {year - 1}</button>
                <button type="button" className={btn.secondary} onClick={() => goYear(year + 1)}>{year + 1} ›</button>
                <Link className={btn.primary} href={route("accounting.record", { division: "toko", event: "TK-15" })}>Catat penjualan kantin (rekap)</Link>
                <Link className={btn.primary} href={route("accounting.record", { division: "toko", event: "TK-16" })}>Catat transfer ke penjaga</Link>
            </div>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <Stat label="Penjualan kantin" value={idr(tot.sales)} />
                <Stat label="Bagi hasil koperasi" value={idr(tot.share)} helper={`Porsi aktual ${pct(tot.share, tot.sales)}`} />
                <Stat label="Bagian penjaga" value={idr(tot.keeper)} />
                <Stat label="Sudah ditransfer" value={idr(tot.paid)} />
                <Stat label="Sisa hutang ke penjaga" value={idr(last)} helper={opening ? `Saldo awal tahun ${idr(opening)}` : undefined} />
                <Panel title="Persen bagi hasil" className="print:hidden">
                    <div className="flex items-center gap-2 p-3">
                        <input type="number" min="0" max="100" step="0.01" className={`${inputBase} w-24`} value={share} onChange={(e) => setShare(e.target.value)} aria-label="Persen bagi hasil koperasi" />
                        <span className="text-sm">%</span>
                        <button type="button" className={btn.secondary} onClick={saveShare} disabled={String(share) === String(kantinShare)}>Simpan</button>
                    </div>
                    <p className="px-3 pb-3 text-xs text-slate-500">Berlaku untuk jurnal kantin berikutnya. Jurnal lama tidak berubah.</p>
                </Panel>
            </div>

            <Panel className="mb-4 light-surface print:border-0" title="Per bulan">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 dark:bg-slate-800/50">
                            <tr>
                                <th className={th}>Bulan</th>
                                <th className={`${th} text-right`}>Penjualan</th>
                                <th className={`${th} text-right`}>Bagi hasil</th>
                                <th className={`${th} text-right`}>Porsi aktual</th>
                                <th className={`${th} text-right`}>Bagian penjaga</th>
                                <th className={`${th} text-right`}>Ditransfer</th>
                                <th className={`${th} text-right`}>Sisa hutang</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {months.map((m) => {
                                const empty = !m.sales && !m.share && !m.keeper && !m.paid;
                                return (
                                    <tr key={m.month} className={empty ? "text-slate-400" : ""}>
                                        <td className={td}>{MONTHS[m.month - 1]} {year}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.sales)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.share)}</td>
                                        <td className={`${td} text-right font-mono`}>{pct(m.share, m.sales)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.keeper)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.paid)}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(m.balance)}</td>
                                    </tr>
                                );
                            })}
                            <tr className="font-semibold">
                                <td className={td}>Jumlah</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.sales)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.share)}</td>
                                <td className={`${td} text-right font-mono`}>{pct(tot.share, tot.sales)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.keeper)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(tot.paid)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(last)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p className="px-3 py-2 text-xs text-slate-500">
                    Porsi aktual = bagi hasil ÷ penjualan. Sumber: jurnal kantin (TK-15) dan transfer ke penjaga (TK-16).
                </p>
            </Panel>

            <KantinInvoices invoices={invoices} nextNumber={nextInvoiceNumber} last={lastInvoice} today={today} share={kantinShare} autoFrom={autoFrom} />

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Transfer ke penjaga kantin">
                    {payments.length ? (
                        <table className="w-full">
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.map((p) => (
                                    <tr key={p.id}>
                                        <td className={td}>{fmtDate(p.date)}</td>
                                        <td className={`${td} font-mono text-xs`}>{p.number}</td>
                                        <td className="px-3 py-1.5 text-sm text-slate-600 dark:text-slate-300">{p.description}</td>
                                        <td className={`${td} text-right font-mono`}>{idr(p.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="p-3 text-sm text-slate-500">Belum ada transfer di tahun ini.</p>
                    )}
                </Panel>
                <Panel title="Cara kerja">
                    <ul className="list-disc space-y-1 p-3 pl-7 text-sm text-slate-600 dark:text-slate-300">
                        <li>
                            Outlet kantin:{" "}
                            {kantinWarehouses.length ? kantinWarehouses.map((n) => <Badge key={n} color="green">{n}</Badge>) : <Badge color="amber">belum dipilih</Badge>}{" "}
                            <Link className="text-primary-600 hover:underline" href={route("accounting.accounts")}>ubah</Link>
                        </li>
                        <li>Mulai {fmtDate(autoFrom)}, penjualan kasir di outlet kantin dijurnal otomatis tiap hari (uang muka, lalu bagi hasil {Number(kantinShare).toLocaleString("id-ID")}% dan hutang penjaga).</li>
                        <li>Sebelum tanggal itu, atau bila kantin mencatat di Excel, isi rekap mingguan lewat tombol "Catat penjualan kantin (rekap)".</li>
                        <li>Saat bagian penjaga ditransfer (dari BSI atau kas), catat dengan tombol "Catat transfer ke penjaga" supaya sisa hutang berkurang.</li>
                    </ul>
                </Panel>
            </div>
        </>
    );
}

const addDays = (iso, n) => {
    const d = new Date(iso + "T00:00:00");
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/** Invoice kantin mingguan: penjualan − fee koperasi − potongan = dibayar ke penjaga (nomor NN/BC-KKJ/BULAN/YYYY). */
function KantinInvoices({ invoices, nextNumber, last, today, share, autoFrom }) {
    const from = last?.period_to ? addDays(String(last.period_to).slice(0, 10), 1) : today;
    const form = useForm({
        number: nextNumber, date: today, period_from: from, period_to: addDays(from, 4), sales: 0, fee_percent: share, deductions: [],
        payee_name: last?.payee_name || "", payee_bank: last?.payee_bank || "", payee_account: last?.payee_account || "", signer: last?.signer || "",
    });
    const [pay, setPay] = useState({ id: null, paid_at: today, cash_account: "11102" });
    const d = form.data;
    const fee = Math.round((Number(d.sales) * Number(d.fee_percent || 0)) / 100);
    const total = Number(d.sales) - fee - d.deductions.reduce((s, x) => s + Number(x.amount || 0), 0);
    const setDed = (i, k, v) => form.setData("deductions", d.deductions.map((x, j) => (j === i ? { ...x, [k]: v } : x)));
    const setDate = async (date) => {
        form.setData("date", date);
        try {
            const r = await fetch(route("consignments.next-number", { date, type: "kantin" }), { headers: { Accept: "application/json" } });
            const j = await r.json();
            form.setData((x) => ({ ...x, date, number: j.number }));
        } catch { /* nomor tetap */ }
    };

    return (
        <Panel className="mb-4" title="Invoice kantin">
            <form className="grid gap-3 p-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); form.post(route("accounting.kantin.invoices.store"), { preserveScroll: true }); }}>
                <Field label="Tanggal invoice"><input type="date" className={inputClass} value={d.date} onChange={(e) => setDate(e.target.value)} /></Field>
                <Field label="Nomor" error={form.errors.number} hint="Urut per tahun; boleh diubah"><input className={inputClass} value={d.number} onChange={(e) => form.setData("number", e.target.value)} /></Field>
                <Field label="Penjualan dari" error={form.errors.period_from}><input type="date" className={inputClass} value={d.period_from} onChange={(e) => form.setData("period_from", e.target.value)} /></Field>
                <Field label="s.d." error={form.errors.period_to}><input type="date" className={inputClass} value={d.period_to} onChange={(e) => form.setData("period_to", e.target.value)} /></Field>
                <Field label="Penjualan kantin (kotor)" error={form.errors.sales}><MoneyInput value={d.sales} onChange={(v) => form.setData("sales", v)} /></Field>
                <Field label="Fee koperasi (%)"><input type="number" step="0.01" min="0" max="100" className={inputClass} value={d.fee_percent} onChange={(e) => form.setData("fee_percent", e.target.value)} /></Field>
                <Field label="Fee"><div className={`${inputClass} bg-slate-50 text-right dark:bg-slate-900`}>{idr(fee)}</div></Field>
                <Field label="Dibayar ke penjaga"><div className={`${inputClass} bg-slate-50 text-right font-semibold dark:bg-slate-900`}>{idr(total)}</div></Field>
                <div className="space-y-2 md:col-span-4">
                    {d.deductions.map((x, i) => (
                        <div key={i} className="grid items-end gap-2 md:grid-cols-[1fr_12rem_auto]">
                            <Field label={`Potongan ${i + 1}`}><input className={inputClass} value={x.label} onChange={(e) => setDed(i, "label", e.target.value)} placeholder="mis. Potongan hutang kantin 2025" /></Field>
                            <Field label="Jumlah"><MoneyInput value={x.amount} onChange={(v) => setDed(i, "amount", v)} /></Field>
                            <button type="button" className={btn.secondary} onClick={() => form.setData("deductions", d.deductions.filter((_, j) => j !== i))}><IconTrash size={16} /></button>
                        </div>
                    ))}
                    {form.errors.deductions && <p className="text-xs text-danger-600">{form.errors.deductions}</p>}
                    <button type="button" className={btn.secondary} onClick={() => form.setData("deductions", [...d.deductions, { label: "", amount: 0, account: "11205" }])}><IconPlus size={16} /> Tambah potongan</button>
                    <p className="text-xs text-slate-500">Potongan mengurangi piutang ke ibu/penjaga kantin (akun 11205) saat invoice dibayar.</p>
                </div>
                <Field label="Rekening tujuan: nama"><input className={inputClass} value={d.payee_name} onChange={(e) => form.setData("payee_name", e.target.value)} /></Field>
                <Field label="Bank"><input className={inputClass} value={d.payee_bank} onChange={(e) => form.setData("payee_bank", e.target.value)} /></Field>
                <Field label="No. rekening"><input className={inputClass} value={d.payee_account} onChange={(e) => form.setData("payee_account", e.target.value)} /></Field>
                <Field label="Penanda tangan"><input className={inputClass} value={d.signer} onChange={(e) => form.setData("signer", e.target.value)} /></Field>
                <div className="md:col-span-4"><button type="submit" className={btn.primary} disabled={form.processing}>Buat invoice</button></div>
            </form>
            <div className="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
                <table className="w-full">
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {invoices.map((v) => (
                            <tr key={v.id}>
                                <td className={`${td} font-mono text-xs`}>{v.number}</td>
                                <td className={td}>{fmtDate(String(v.date).slice(0, 10))}<span className="block text-xs text-slate-500">{fmtDate(String(v.period_from).slice(0, 10))} s.d. {fmtDate(String(v.period_to).slice(0, 10))}</span></td>
                                <td className={`${td} text-right font-mono`}>{idr(v.sales)}</td>
                                <td className={`${td} text-right font-mono`}>{idr(v.total)}</td>
                                <td className={td}>{v.status === "paid" ? <Badge color="green">dibayar</Badge> : <Badge color="amber">belum dibayar</Badge>}</td>
                                <td className={`${td} text-right`}>
                                    <a className="mr-3 inline-flex items-center gap-1 text-xs font-medium text-primary-600" href={route("accounting.kantin.invoices.print", v.id)} target="_blank" rel="noreferrer"><IconPrinter size={14} /> Cetak</a>
                                    {v.status !== "paid" && (
                                        <>
                                            <button type="button" className="mr-3 text-xs font-medium text-success-700" onClick={() => setPay({ ...pay, id: v.id })}>Tandai dibayar</button>
                                            <button type="button" className="text-xs text-danger-600" onClick={() => confirm(`Hapus ${v.number}?`) && router.delete(route("accounting.kantin.invoices.destroy", v.id), { preserveScroll: true })}><IconTrash size={14} /></button>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {!invoices.length && <tr><td className="p-4 text-center text-sm text-slate-500">Belum ada invoice kantin di tahun ini.</td></tr>}
                    </tbody>
                </table>
            </div>
            {pay.id && (
                <div className="flex flex-wrap items-end gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                    <Field label="Tanggal bayar"><input type="date" className={inputClass} value={pay.paid_at} onChange={(e) => setPay({ ...pay, paid_at: e.target.value })} /></Field>
                    <Field label="Dibayar dari">
                        <select className={inputClass} value={pay.cash_account} onChange={(e) => setPay({ ...pay, cash_account: e.target.value })}>
                            <option value="11102">Rekening BSI (transfer)</option>
                            <option value="11104">Kas toko (tunai)</option>
                        </select>
                    </Field>
                    <button type="button" className={btn.primary} onClick={() => router.post(route("accounting.kantin.invoices.pay", pay.id), { paid_at: pay.paid_at, cash_account: pay.cash_account }, { preserveScroll: true, onSuccess: () => setPay({ ...pay, id: null }) })}>Simpan pembayaran</button>
                    <button type="button" className={btn.secondary} onClick={() => setPay({ ...pay, id: null })}>Batal</button>
                    <p className="w-full text-xs text-slate-500">Mulai {fmtDate(autoFrom)} pembayaran invoice otomatis menjadi jurnal (hutang penjaga lunas, potongan, kas keluar). Sebelumnya catat lewat "Catat transfer ke penjaga".</p>
                </div>
            )}
        </Panel>
    );
}


Kantin.layout = (page) => <DashboardLayout children={page} />;
