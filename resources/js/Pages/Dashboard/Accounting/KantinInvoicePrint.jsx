import React from "react";
import InvoiceSheet, { longDate, rp } from "@/Components/Cooperative/InvoiceSheet";

const pct = (v) => Number(v || 0).toLocaleString("id-ID", { maximumFractionDigits: 2 }) + "%";

/** Cetak invoice kantin mingguan (nomor NN/BC-KKJ/BULAN/YYYY). */
export default function KantinInvoicePrint({ invoice, fee, coop }) {
    const from = longDate(invoice.period_from);
    const to = longDate(invoice.period_to);
    const sameMonth = String(invoice.period_from).slice(0, 7) === String(invoice.period_to).slice(0, 7);
    const period = sameMonth ? `${Number(String(invoice.period_from).slice(8, 10))} s.d ${to}` : `${from} s.d ${to}`;
    return (
        <InvoiceSheet
            title="INVOICE"
            coop={coop}
            number={invoice.number}
            date={invoice.date}
            payee={{ name: invoice.payee_name, bank: invoice.payee_bank, account: invoice.payee_account }}
            signer={invoice.signer}
            copies={["Bendahara", "Penjaga Kantin"]}
        >
            <table className="iv-table">
                <thead>
                    <tr><th>KETERANGAN</th><th>JUMLAH</th><th>FEE</th><th>JUMLAH PEMBAYARAN</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Penjualan Kantin Tanggal {period}</td>
                        <td className="r">{rp(invoice.sales)}</td>
                        <td className="ctr">{pct(invoice.fee_percent)}</td>
                        <td className="r">{rp(invoice.sales - fee)}</td>
                    </tr>
                    {(invoice.deductions || []).map((d, i) => (
                        <tr key={i}>
                            <td>{d.label}</td>
                            <td className="r">{rp(d.amount)}</td>
                            <td className="ctr">0%</td>
                            <td className="r">({rp(d.amount)})</td>
                        </tr>
                    ))}
                    <tr className="iv-total">
                        <td colSpan={3} className="r">Jumlah :</td>
                        <td className="r">{rp(invoice.total)}</td>
                    </tr>
                </tbody>
            </table>
        </InvoiceSheet>
    );
}
