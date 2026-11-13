import React from "react";
import { Head } from "@inertiajs/react";

/*
 * Lembar cetak A4 untuk invoice kantin & instruksi pembayaran konsinyasi (format koperasi):
 * nama koperasi, judul di tengah, KEPADA/NO/TGL, tabel rincian, jumlah, rekening tujuan, tanda tangan, pembagian lembar.
 */
const CSS = `
@page { size: A4; margin: 18mm; }
.iv-sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto 16px; padding: 18mm; box-sizing: border-box;
  font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.35; color: #000; }
.iv-sheet * { box-sizing: border-box; }
.iv-sheet p { margin: 0; }
.iv-coop { font-weight: bold; font-size: 12pt; }
.iv-title { text-align: center; font-weight: bold; font-size: 13pt; margin: 6mm 0 5mm; letter-spacing: 0.5pt; }
.iv-fields td { padding: 0.5mm 0; vertical-align: top; }
.iv-fields td.l { width: 32mm; } .iv-fields td.c { width: 5mm; }
.iv-table { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.iv-table th, .iv-table td { border: 0.6pt solid #000; padding: 1.3mm 1.6mm; vertical-align: middle; }
.iv-table th { font-weight: bold; text-align: center; background: #f2f2f2; }
.iv-table td.r { text-align: right; white-space: nowrap; } .iv-table td.ctr { text-align: center; }
.iv-total td { font-weight: bold; }
.iv-gap { height: 5mm; }
.iv-sign { width: 70mm; margin-left: auto; text-align: center; }
.iv-sign-space { height: 24mm; }
.iv-sign-name { font-weight: bold; text-decoration: underline; min-height: 4.5mm; }
.iv-copies { margin-top: 10mm; font-size: 9pt; }
@media print { .iv-sheet { width: auto; min-height: auto; margin: 0; padding: 0; } .iv-toolbar { display: none !important; } }
`;

export const rp = (v) => "Rp " + Math.round(Number(v || 0)).toLocaleString("id-ID");
export const longDate = (d) => (d ? new Date(String(d).slice(0, 10) + "T00:00:00").toLocaleDateString("id-ID", { day: "2-digit", month: "long", year: "numeric" }) : "");

export default function InvoiceSheet({ title, coop = {}, number, date, children, payee, signer, signerTitle, copies = [] }) {
    return (
        <div className="light-surface min-h-screen bg-gray-200 py-6 text-black print:bg-white print:py-0">
            <Head title={`${title} ${number}`} />
            <style>{CSS}</style>
            <div className="iv-toolbar mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 print:hidden">
                <button onClick={() => window.close()} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">Tutup</button>
                <button onClick={() => window.print()} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white">Cetak / simpan PDF</button>
            </div>
            <div className="iv-sheet">
                <p className="iv-coop">{(coop.name || "KOPERASI").toUpperCase()}</p>
                <p className="iv-title">{title}</p>
                <table className="iv-fields">
                    <tbody>
                        <tr><td className="l">KEPADA</td><td className="c">:</td><td>BENDAHARA KOPERASI</td></tr>
                        <tr><td className="l">NO</td><td className="c">:</td><td>{number}</td></tr>
                        <tr><td className="l">TANGGAL</td><td className="c">:</td><td>{longDate(date).toUpperCase()}</td></tr>
                    </tbody>
                </table>
                {children}
                <div className="iv-gap" />
                <p>Pembayaran melalui transfer ke rekening :</p>
                <table className="iv-fields">
                    <tbody>
                        <tr><td className="l">Nama</td><td className="c">:</td><td>{payee?.name || ""}</td></tr>
                        <tr><td className="l">Nama Bank</td><td className="c">:</td><td>{payee?.bank || ""}</td></tr>
                        <tr><td className="l">No. Rekening</td><td className="c">:</td><td>{payee?.account || ""}</td></tr>
                    </tbody>
                </table>
                <div className="iv-gap" />
                <div className="iv-sign">
                    <p>{coop.city ? `${coop.city}, ` : ""}{longDate(date)}</p>
                    {signerTitle && <p>{signerTitle}</p>}
                    <div className="iv-sign-space" />
                    <p className="iv-sign-name">{signer || ""}</p>
                </div>
                {copies.length > 0 && (
                    <div className="iv-copies">
                        {copies.map((c, i) => <p key={i}>Lembar {i + 1}: {c}</p>)}
                    </div>
                )}
            </div>
        </div>
    );
}
