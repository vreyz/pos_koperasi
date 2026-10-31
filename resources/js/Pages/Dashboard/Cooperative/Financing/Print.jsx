import React from "react";
import { Head } from "@inertiajs/react";

const rupiah = (v) => "Rp " + Math.round(Number(v || 0)).toLocaleString("id-ID");
const DOTS = "………………………………………";

export function terbilang(n) {
    const w = ["", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan", "sepuluh", "sebelas"];
    n = Math.floor(Math.abs(Number(n) || 0));
    if (n < 12) return w[n];
    if (n < 20) return terbilang(n - 10) + " belas";
    if (n < 100) return terbilang(Math.floor(n / 10)) + " puluh " + terbilang(n % 10);
    if (n < 200) return "seratus " + terbilang(n - 100);
    if (n < 1000) return terbilang(Math.floor(n / 100)) + " ratus " + terbilang(n % 100);
    if (n < 2000) return "seribu " + terbilang(n - 1000);
    if (n < 1e6) return terbilang(Math.floor(n / 1000)) + " ribu " + terbilang(n % 1000);
    if (n < 1e9) return terbilang(Math.floor(n / 1e6)) + " juta " + terbilang(n % 1e6);
    return terbilang(Math.floor(n / 1e9)) + " miliar " + terbilang(n % 1e9);
}
const words = (n) => {
    const t = terbilang(n).replace(/\s+/g, " ").trim();
    return "#" + t.charAt(0).toUpperCase() + t.slice(1).replace(/\b\w/g, (c) => c.toUpperCase()) + " Rupiah#";
};
const longDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" }) : DOTS);

function Row({ label, value }) {
    return (
        <tr>
            <td className="w-56 py-1 align-top">{label}</td>
            <td className="w-4 py-1 align-top">:</td>
            <td className="py-1">{value || DOTS}</td>
        </tr>
    );
}

function Approval({ f, settings }) {
    const cells = [
        ["Diperiksa oleh", "Divisi Pembiayaan", f?.checker?.name, f?.checked_at],
        ["Direview dan Disetujui oleh", "Kepala Unit Usaha", f?.reviewer?.name || settings.coop_business_head, f?.reviewed_at],
        ["Disetujui oleh", "Ketua Koperasi", f?.approver?.name || settings.coop_chair, f?.approved_at],
    ];
    return (
        <table className="mt-4 w-full border-collapse text-xs">
            <thead>
                <tr><th colSpan={5} className="border border-black p-1">Kolom Persetujuan Permohonan</th></tr>
                <tr>{["Jabatan", "Pengurus", "Nama Pengurus", "Tanda Tangan Pengurus", "Tanggal"].map((h) => <th key={h} className="border border-black p-1">{h}</th>)}</tr>
            </thead>
            <tbody>
                {cells.map(([jabatan, pengurus, nama, tgl]) => (
                    <tr key={jabatan} className="h-12">
                        <td className="border border-black p-1">{jabatan}</td>
                        <td className="border border-black p-1">{pengurus}</td>
                        <td className="border border-black p-1">{nama || ""}</td>
                        <td className="border border-black p-1"></td>
                        <td className="border border-black p-1">{tgl ? new Date(tgl).toLocaleDateString("id-ID") : ""}</td>
                    </tr>
                ))}
                <tr className="h-12"><td className="border border-black p-1">Alasan</td><td colSpan={4} className="border border-black p-1">{f?.rejection_reason || ""}</td></tr>
            </tbody>
        </table>
    );
}

function Signatures({ settings, customer }) {
    return (
        <div className="mt-8 grid grid-cols-2 gap-8 text-center text-sm">
            <div>
                <p>&nbsp;</p>
                <p>Keluarga/Suami/Istri</p>
                <p>Pemohon,</p>
                <div className="my-6 text-xs text-gray-500">Meterai Rp10.000</div>
                <p>{DOTS}</p>
            </div>
            <div>
                <p>{settings.coop_city}, {DOTS}</p>
                <p>&nbsp;</p>
                <p>Pemohon,</p>
                <div className="my-6 text-xs text-gray-500">Meterai Rp10.000</div>
                <p className="font-semibold underline">{customer?.name || DOTS}</p>
                <p>NIP {customer?.nip || DOTS}</p>
            </div>
            <div className="col-span-2">
                <p>Mengetahui :</p>
                <p>Atasan Langsung,</p>
                <div className="h-16" />
                <p>{DOTS}</p>
                <p>NIP {DOTS}</p>
            </div>
        </div>
    );
}

function DanaForm({ f, settings, ppnpn }) {
    const c = f?.customer;
    const max = Number(ppnpn ? settings.financing_max_ppnpn : settings.financing_max_pegawai);
    const options = [5000000, 10000000, 15000000].filter((v) => v <= max);
    const fee = Number(ppnpn ? settings.financing_fee_ppnpn : settings.financing_fee_pegawai);
    const year = new Date(f?.applied_at || Date.now()).getFullYear();
    return (
        <>
            <section className="page">
                <h1 className="text-center text-base font-bold underline">PERMOHONAN FASILITAS PEMBIAYAAN DANA</h1>
                <p className="mb-4 text-center">Nomor : {f?.number || `          /KOP/FPD/${year}`}</p>
                <p>Yth. Ketua Koperasi<br />Di tempat</p>
                <p className="mt-3">Dengan hormat,<br />Yang bertanda tangan di bawah ini :</p>
                <table className="mt-2 w-full text-sm"><tbody>
                    <Row label="Nama / NIP" value={c && `${c.name}${c.nip ? ` / ${c.nip}` : ""}`} />
                    <Row label="Jabatan / Peringkat Jabatan" value={c && [c.position, c.grade].filter(Boolean).join(" / ")} />
                    <Row label="Bidang / Seksi" value={c?.work_unit} />
                    <Row label="Nama Bank" value={f?.bank_name} />
                    <Row label="Nomor Rekening" value={f?.bank_account} />
                    <Row label="Nomor Telepon" value={f?.phone} />
                    <Row label="Nama Keluarga" value={f?.family_name} />
                    <Row label="Status Keluarga" value={f?.family_relation} />
                    <Row label="Nomor Telepon Keluarga" value={f?.family_phone} />
                </tbody></table>
                <p className="mt-3">dengan ini mengajukan permohonan Fasilitas Pembiayaan Dana Koperasi sebagai berikut :</p>
                <ol className="ml-5 mt-2 list-decimal space-y-2 text-sm">
                    <li>
                        <table className="w-full"><tbody>
                            <tr><td className="w-52">Jumlah Pembiayaan</td><td className="w-4">:</td><td>{options.map((v) => <span key={v} className="mr-5">{f ? (f.principal === v ? "☑" : "☐") : "☐"} {rupiah(v)}</span>)}</td></tr>
                            <tr><td>Jangka Waktu Pembiayaan</td><td>:</td><td>{f?.tenor || "……….."} bulan</td></tr>
                        </tbody></table>
                    </li>
                    <li>Menyetujui syarat dan ketentuan sebagaimana tercantum dalam Lampiran yang tidak terpisahkan dari formulir Fasilitas Pembiayaan Dana ini.</li>
                    <li>Telah menyerahkan surat pernyataan kesediaan untuk melakukan penyelesaian kewajiban kepada Koperasi.</li>
                </ol>
                <Signatures settings={settings} customer={c} />
            </section>

            <section className="page">
                <h2 className="text-center font-bold">SYARAT DAN KETENTUAN<br />PERMOHONAN FASILITAS PEMBIAYAAN DANA</h2>
                <ol className="ml-5 mt-4 list-decimal space-y-1 text-sm">
                    <li>Fasilitas Pembiayaan Dana diberikan sehubungan dengan penyerahan sejumlah uang sebagai pinjaman;</li>
                    <li>Pembiayaan dana dilakukan oleh Koperasi kepada Pemohon;</li>
                    {ppnpn ? (
                        <>
                            <li>Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;</li>
                            <li>Biaya untuk peminjaman adalah sebesar {rupiah(fee)}, per pembiayaan {rupiah(settings.financing_fee_unit)}, dibayarkan setelah berkas permohonan disetujui oleh Tim Pembiayaan Koperasi dan sebelum pencairan dana, melalui:
                                <ol className="ml-5 list-[lower-alpha]"><li>transfer bank ke rekening {settings.coop_bank || "...................................."};</li><li>menyampaikan konfirmasi berupa bukti pembayaran melalui {settings.coop_contact_ppnpn || "...................................."}.</li></ol>
                            </li>
                            <li>Fasilitas Pembiayaan Dana dapat diberikan kepada Pemohon setelah melakukan pembayaran biaya sebagaimana dimaksud pada poin 4;</li>
                            <li>Fasilitas Pembiayaan Dana diberikan setinggi-tingginya sebesar {rupiah(max)};</li>
                        </>
                    ) : (
                        <>
                            <li>Setiap 1 (satu) Formulir Permohonan Pembiayaan Dana yang diajukan digunakan untuk melakukan peminjaman dana sebesar {rupiah(settings.financing_fee_unit)};</li>
                            <li>Biaya untuk pembelian 1 (satu) formulir sebesar {rupiah(fee)}, dapat dibayarkan secara:
                                <ol className="ml-5 list-[lower-alpha]"><li>transfer bank ke rekening {settings.coop_bank || "...................................."};</li><li>menyampaikan konfirmasi berupa bukti pembayaran melalui {settings.coop_contact_pegawai || "...................................."}.</li></ol>
                            </li>
                            <li>Fasilitas Pembiayaan Dana diberikan setinggi-tingginya sebesar {rupiah(max)};</li>
                            <li>Fasilitas Pembiayaan Dana dapat diberikan kepada Pemohon setelah melakukan pembayaran biaya pembelian formulir sebagaimana dimaksud pada poin 4;</li>
                            <li>Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;</li>
                        </>
                    )}
                    <li>Pembayaran Kembali pembiayaan dapat dilakukan secara angsuran pada bulan berikutnya dengan jangka waktu pembayaran dari 1 bulan s.d. {settings.financing_tenor_dana_max} bulan setelah dana diterima oleh pemohon;</li>
                    <li>Pembayaran angsuran pengembalian Fasilitas Pembiayaan Dana dilakukan melalui mekanisme autodebet pada rekening pembayaran penghasilan Pemohon{ppnpn ? "; apabila tidak berhasil maka Pemohon diwajibkan untuk melakukan transfer ke rekening yang sudah ditentukan dan berkoordinasi dengan bendahara" : ""};</li>
                    <li>Dalam hal keanggotaan koperasi Pemohon berakhir disebabkan:
                        <ol className="ml-5 list-[lower-alpha]">
                            <li>Mutasi keluar {settings.coop_office}, sisa angsuran pembayaran wajib dilunasi sebelum Pemohon pindah tugas;</li>
                            <li>Selain mutasi keluar {settings.coop_office}, sisa angsuran pembayaran wajib dilunasi sebelum Pemohon keluar dari {settings.coop_office};</li>
                        </ol>
                    </li>
                    <li>Pelunasan Fasilitas Pembiayaan Dana dapat dilakukan sebelum jangka waktu pembayaran yang disepakati berakhir.</li>
                </ol>
                <Approval f={f} settings={settings} />
            </section>
        </>
    );
}

function BarangForm({ f, settings }) {
    const c = f?.customer;
    const year = new Date(f?.applied_at || Date.now()).getFullYear();
    return (
        <>
            <section className="page">
                <h1 className="text-center text-base font-bold underline">PERMOHONAN FASILITAS PEMBIAYAAN BARANG</h1>
                <p className="mb-4 text-center">Nomor : {f?.number || `          /KOP/FPB/${year}`}</p>
                <p>Yth. Ketua Koperasi<br />Di tempat</p>
                <p className="mt-3">Dengan hormat,<br />Yang bertanda tangan di bawah ini :</p>
                <table className="mt-2 w-full text-sm"><tbody>
                    <Row label="Nama / NIP" value={c && `${c.name}${c.nip ? ` / ${c.nip}` : ""}`} />
                    <Row label="Jabatan / Peringkat Jabatan" value={c && [c.position, c.grade].filter(Boolean).join(" / ")} />
                    <Row label="Bidang / Seksi" value={c?.work_unit} />
                    <Row label="Nama Bank" value={f?.bank_name} />
                    <Row label="Nomor Rekening" value={f?.bank_account} />
                    <Row label="Nomor Telepon" value={f?.phone} />
                    <Row label="Nama Keluarga" value={f?.family_name} />
                    <Row label="Status Keluarga" value={f?.family_relation} />
                    <Row label="Nomor Telepon Keluarga" value={f?.family_phone} />
                </tbody></table>
                <p className="mt-3">dengan ini mengajukan permohonan Fasilitas Pembiayaan Barang Koperasi sebagai berikut :</p>
                <ol className="ml-5 mt-2 list-decimal space-y-2 text-sm">
                    <li>
                        <table className="w-full"><tbody>
                            <tr><td className="w-52">Nama / jenis barang</td><td className="w-4">:</td><td>{f?.item_name || DOTS}</td></tr>
                            <tr><td>Harga barang</td><td>:</td><td>{f ? rupiah(f.principal) : DOTS}</td></tr>
                            <tr><td>Bagi hasil ({f?.margin_percent ?? settings.financing_margin_barang}%)</td><td>:</td><td>{f ? rupiah(f.margin_amount) : DOTS}</td></tr>
                            <tr><td>Jangka waktu</td><td>:</td><td>{f?.tenor || "……….."} bulan</td></tr>
                            <tr><td>Angsuran per bulan</td><td>:</td><td>{f ? rupiah(f.installment) : DOTS}</td></tr>
                        </tbody></table>
                    </li>
                    <li>Menyetujui syarat dan ketentuan sebagaimana tercantum dalam Lampiran yang tidak terpisahkan dari formulir Fasilitas Pembiayaan Barang ini.</li>
                    <li>Telah menyerahkan surat pernyataan kesediaan untuk melakukan penyelesaian kewajiban kepada Koperasi.</li>
                </ol>
                <Signatures settings={settings} customer={c} />
            </section>
            <section className="page">
                <h2 className="text-center font-bold">SYARAT DAN KETENTUAN<br />PERMOHONAN FASILITAS PEMBIAYAAN BARANG</h2>
                <ol className="ml-5 mt-4 list-decimal space-y-1 text-sm">
                    <li>Fasilitas Pembiayaan Barang diberikan dalam bentuk pembelian barang oleh Koperasi untuk Pemohon yang dibayar kembali secara angsuran;</li>
                    <li>Harga yang wajib dibayar Pemohon adalah harga barang ditambah bagi hasil sebesar {settings.financing_margin_barang}% dari harga barang;</li>
                    <li>Biaya lain sebesar {settings.financing_admin_barang}% dari harga barang dipotong pada saat pencairan;</li>
                    <li>Pembayaran kembali dilakukan secara angsuran mulai bulan berikutnya selama {settings.financing_tenor_barang} bulan, atau lebih cepat sesuai kesepakatan;</li>
                    <li>Pembayaran angsuran dilakukan melalui mekanisme autodebet pada rekening pembayaran penghasilan Pemohon;</li>
                    <li>Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;</li>
                    <li>Dalam hal Pemohon mutasi atau keluar dari {settings.coop_office}, sisa angsuran wajib dilunasi sebelum Pemohon pindah tugas/keluar;</li>
                    <li>Pelunasan dapat dilakukan sebelum jangka waktu pembayaran yang disepakati berakhir.</li>
                </ol>
                <Approval f={f} settings={settings} />
            </section>
        </>
    );
}

function Disbursement({ f, settings }) {
    const c = f.customer;
    return (
        <section className="page">
            <h1 className="text-center text-base font-bold underline">BUKTI PENCAIRAN {f.type === "barang" ? "PEMBIAYAAN BARANG" : "DANA"}</h1>
            <p className="mb-6 text-center">{settings.coop_name}</p>
            <table className="w-full text-sm"><tbody>
                <Row label="Nomor formulir" value={f.number} />
                <Row label="Tanggal" value={longDate(f.disbursed_at)} />
                <Row label="Jumlah" value={rupiah(f.transferred_amount ?? f.principal)} />
                <Row label="Terbilang" value={words(f.transferred_amount ?? f.principal)} />
                <Row label="Keperluan" value={`Pencairan ${f.type === "barang" ? "Pembiayaan Barang" : "Dana"} dalam rangka Permohonan ${f.type === "barang" ? `Pembiayaan Barang (${f.item_name})` : "Pembiayaan Dana"} a.n. ${c.name}`} />
                <Row label="Pembayaran" value={f.bank_name ? `transfer ke ${f.bank_name} No Rek. ${f.bank_account || DOTS} a.n. ${c.name}` : null} />
                <Row label="Nilai pembiayaan" value={`${rupiah(f.principal)}${f.margin_amount ? ` + bagi hasil ${rupiah(f.margin_amount)}` : ""}, ${f.tenor} × ${rupiah(f.installment)}`} />
                <Row label={f.type === "barang" ? "Biaya lain" : "Biaya formulir"} value={rupiah(f.admin_fee)} />
            </tbody></table>
            <div className="mt-12 grid grid-cols-2 gap-8 text-center text-sm">
                <div><p>Menyetujui,</p><p>Ketua Koperasi</p><div className="h-20" /><p className="font-semibold underline">{f.approver?.name || settings.coop_chair || DOTS}</p></div>
                <div><p>{settings.coop_city}, {longDate(f.disbursed_at)}</p><p>Penerima,</p><div className="h-20" /><p className="font-semibold underline">{c.name}</p></div>
            </div>
        </section>
    );
}

export default function Print({ doc, type, financing, settings, ppnpn }) {
    return (
        <div className="min-h-screen bg-gray-100 py-6 text-black print:bg-white print:py-0">
            <Head title={doc === "pencairan" ? "Bukti Pencairan" : "Formulir Pembiayaan"} />
            <style>{`@page { size: A4; margin: 18mm 16mm; } .page { background: white; width: 210mm; min-height: 297mm; margin: 0 auto 16px; padding: 18mm 16mm; font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.4; } @media print { .page { width: auto; min-height: auto; margin: 0; padding: 0; break-after: page; } }`}</style>
            <div className="mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 print:hidden">
                <button onClick={() => window.close()} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">Tutup</button>
                <button onClick={() => window.print()} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white">Cetak / simpan PDF</button>
            </div>
            {doc === "pencairan" ? (
                <Disbursement f={financing} settings={settings} />
            ) : type === "barang" ? (
                <BarangForm f={financing} settings={settings} />
            ) : (
                <DanaForm f={financing} settings={settings} ppnpn={ppnpn} />
            )}
        </div>
    );
}
