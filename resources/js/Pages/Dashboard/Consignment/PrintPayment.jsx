import React from "react";
import InvoiceSheet, { rp } from "@/Components/Cooperative/InvoiceSheet";

const qty = (q) => Number(q || 0).toLocaleString("id-ID");

/** Cetak Instruksi Pembayaran konsinyasi (nomor NN/IP-NAP/BULAN/YY). */
export default function PrintPayment({ payment, coop }) {
    const c = payment.consignor || {};
    return (
        <InvoiceSheet
            title="INSTRUKSI PEMBAYARAN"
            coop={coop}
            number={payment.number}
            date={payment.date}
            payee={{ name: c.account_holder || c.name, bank: c.bank_name, account: c.bank_account }}
            signer={payment.signer}
            copies={["Bendahara", "Koperasi"]}
        >
            <table className="iv-table">
                <thead>
                    <tr>
                        <th>NAMA BARANG</th><th>JUMLAH BARANG TERJUAL</th><th>SATUAN</th><th>SISA STOK</th>
                        <th>HARGA TITIP SATUAN</th><th>HARGA JUAL SATUAN</th><th>PEMBAYARAN</th>
                    </tr>
                </thead>
                <tbody>
                    {payment.items.map((r) => (
                        <tr key={r.product_id}>
                            <td>{r.title}</td>
                            <td className="ctr">{qty(r.qty)}</td>
                            <td className="ctr">{r.unit}</td>
                            <td className="ctr">{qty(r.stock)}</td>
                            <td className="r">{rp(r.consign_price)}</td>
                            <td className="r">{rp(r.sell_price)}</td>
                            <td className="r">{rp(r.payment)}</td>
                        </tr>
                    ))}
                    <tr className="iv-total">
                        <td colSpan={6} className="r">Jumlah :</td>
                        <td className="r">{rp(payment.total)}</td>
                    </tr>
                </tbody>
            </table>
        </InvoiceSheet>
    );
}
