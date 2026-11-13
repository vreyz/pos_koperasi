import React from "react";
import { Head } from "@inertiajs/react";

/*
 * Formulir cetak pembiayaan (A4). Ukuran & jarak memakai CSS sendiri (mm/pt) di bawah, bukan kelas spasi Tailwind,
 * supaya tidak ikut tampilan padat aplikasi: Arial 11 pt, margin halaman 20 mm, jarak antarbagian seragam.
 * Tanpa titik-titik: isian langsung terisi data; pada formulir kosong diberi garis tipis untuk ditulis tangan.
 */
const rupiah = (v) => "Rp " + Math.round(Number(v || 0)).toLocaleString("id-ID");
const FEE_UNIT = 5000000; // biaya formulir per Rp 5 jt pembiayaan
const ROMAN = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"];

const CSS = `
@page { size: A4; margin: 20mm; }
.fp-sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto 16px; padding: 20mm; box-sizing: border-box;
  font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.35; color: #000; }
.fp-sheet * { box-sizing: border-box; }
.fp-sheet p { margin: 0; }
.fp-title { text-align: center; font-size: 12pt; font-weight: bold; text-decoration: underline; margin: 0; }
.fp-subtitle { text-align: center; font-size: 12pt; font-weight: bold; margin: 0; }
.fp-center { text-align: center; }
.fp-gap { height: 4mm; }
.fp-gap-s { height: 2mm; }
.fp-fields { width: 100%; border-collapse: collapse; }
.fp-fields td { padding: 0.6mm 0; vertical-align: top; }
.fp-fields td.l { width: 58mm; }
.fp-fields td.c { width: 5mm; }
.fp-blank { display: inline-block; min-width: 70mm; border-bottom: 0.3pt solid #555; height: 4.2mm; vertical-align: bottom; }
.fp-blank.s { min-width: 25mm; }
.fp-list { margin: 0; padding: 0; list-style: none; }
.fp-list > li { display: grid; grid-template-columns: 7mm 1fr; margin-top: 1.5mm; }
.fp-list > li:first-child { margin-top: 0; }
.fp-sublist { margin: 0; padding: 0; list-style: none; }
.fp-sublist > li { display: grid; grid-template-columns: 6mm 1fr; }
.fp-check { margin-right: 6mm; white-space: nowrap; }
.fp-signs { display: grid; grid-template-columns: 1fr 1fr; column-gap: 12mm; text-align: center; }
.fp-signs.three { grid-template-columns: 1fr 1fr 1fr; column-gap: 6mm; }
.fp-nowrap { white-space: nowrap; }
.fp-sign-space { height: 25mm; display: flex; align-items: center; justify-content: center; font-size: 8pt; color: #777; }
.fp-sign-name { font-weight: bold; text-decoration: underline; min-height: 4.5mm; }
.fp-approval { width: 100%; border-collapse: collapse; table-layout: fixed; }
.fp-approval th, .fp-approval td { border: 0.6pt solid #000; padding: 1.2mm 1.5mm; vertical-align: middle; }
.fp-approval th { font-weight: bold; text-align: center; }
.fp-approval td.row { height: 15mm; }
@media print {
  .fp-sheet { width: auto; min-height: auto; margin: 0; padding: 0; break-after: page; }
  .fp-sheet:last-child { break-after: auto; }
  .fp-toolbar { display: none !important; }
}
`;

const longDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "");
const longDay = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" }) : "");
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
// "#Sepuluh Juta Rupiah#": huruf besar di awal setiap kata saja.
const words = (n) => "#" + terbilang(n).replace(/\s+/g, " ").trim().replace(/(^|\s)\S/g, (c) => c.toUpperCase()) + " Rupiah#";

/** Nilai isian: langsung data; kosong → garis tipis (formulir kosong untuk ditulis tangan). */
const V = ({ v, short }) => (v || v === 0 ? <>{v}</> : <span className={`fp-blank${short ? " s" : ""}`} />);

function Fields({ rows }) {
    return (
        <table className="fp-fields"><tbody>
            {rows.map(([label, value]) => (
                <tr key={label}><td className="l">{label}</td><td className="c">:</td><td><V v={value} /></td></tr>
            ))}
        </tbody></table>
    );
}

function Header({ title, number, code, appliedAt }) {
    const d = appliedAt ? new Date(appliedAt) : new Date();
    return (
        <>
            <p className="fp-title">{title}</p>
            <p className="fp-center">
                Nomor : {number || <><span className="fp-blank s" />/KOP/{code}/{ROMAN[d.getMonth()]}/{d.getFullYear()}</>}
            </p>
            <div className="fp-gap" />
            <p>Yth. Ketua Koperasi</p>
            <p>di tempat</p>
            <div className="fp-gap" />
            <p>Dengan hormat,</p>
            <p>Yang bertanda tangan di bawah ini:</p>
            <div className="fp-gap-s" />
        </>
    );
}

function Applicant({ f }) {
    const c = f?.customer;
    return (
        <Fields
            rows={[
                ["Nama", c?.name],
                ["NIP", c?.nip],
                ["Jabatan / Peringkat Jabatan", c && [c.position, c.grade].filter(Boolean).join(" / ")],
                ["Bidang / Seksi", c?.work_unit],
                ["Nama Bank", f?.bank_name],
                ["Nomor Rekening", f?.bank_account],
                ["Nomor Telepon", f?.phone],
                ["Nama Keluarga", f?.family_name],
                ["Status Keluarga", f?.family_relation],
                ["Nomor Telepon Keluarga", f?.family_phone],
            ]}
        />
    );
}

/** Tiga tanda tangan sebaris (Keluarga · Atasan Langsung · Pemohon) supaya halaman 1 muat satu lembar A4. */
function Signatures({ f, settings }) {
    const c = f?.customer;
    return (
        <div className="fp-signs three">
            <div>
                <p>&nbsp;</p>
                <p>Menyetujui,</p>
                <p>Keluarga ({f?.family_relation || "Suami/Istri"})</p>
                <div className="fp-sign-space" />
                <p className="fp-sign-name"><V v={f?.family_name} short /></p>
            </div>
            <div>
                <p>&nbsp;</p>
                <p>Mengetahui,</p>
                <p>Atasan Langsung</p>
                <div className="fp-sign-space" />
                <p className="fp-sign-name"><span className="fp-blank s" /></p>
                <p>NIP <span className="fp-blank s" /></p>
            </div>
            <div>
                <p className="fp-nowrap">{settings.coop_city}, {f?.applied_at ? longDate(f.applied_at) : <span className="fp-blank s" />}</p>
                <p>&nbsp;</p>
                <p>Pemohon,</p>
                <div className="fp-sign-space">Meterai Rp10.000</div>
                <p className="fp-sign-name"><V v={c?.name} short /></p>
                <p>NIP <V v={c?.nip} short /></p>
            </div>
        </div>
    );
}

function Approval({ f, settings }) {
    const rows = [
        ["Diperiksa oleh", "Divisi Pembiayaan", f?.checked_signer || f?.checker?.name, f?.checked_at],
        ["Direview dan disetujui oleh", "Kepala Unit Usaha", f?.reviewed_signer || f?.reviewer?.name || settings.coop_business_head, f?.reviewed_at],
        ["Disetujui oleh", "Ketua Koperasi", f?.approved_signer || f?.approver?.name || settings.coop_chair, f?.approved_at],
    ];
    return (
        <table className="fp-approval">
            <colgroup><col style={{ width: "24%" }} /><col style={{ width: "18%" }} /><col style={{ width: "24%" }} /><col style={{ width: "20%" }} /><col style={{ width: "14%" }} /></colgroup>
            <thead>
                <tr><th colSpan={5}>Kolom Persetujuan Permohonan</th></tr>
                <tr>{["Jabatan", "Pengurus", "Nama Pengurus", "Tanda Tangan", "Tanggal"].map((h) => <th key={h}>{h}</th>)}</tr>
            </thead>
            <tbody>
                {rows.map(([jabatan, pengurus, nama, tgl]) => (
                    <tr key={jabatan}>
                        <td className="row">{jabatan}</td>
                        <td>{pengurus}</td>
                        <td>{nama || ""}</td>
                        <td />
                        <td className="fp-center">{tgl ? new Date(tgl).toLocaleDateString("id-ID") : ""}</td>
                    </tr>
                ))}
                <tr><td className="row">Alasan (bila ditolak)</td><td colSpan={4}>{f?.rejection_reason || ""}</td></tr>
            </tbody>
        </table>
    );
}

function Terms({ items }) {
    return (
        <ol className="fp-list">
            {items.filter(Boolean).map((item, i) => (
                <li key={i}><span>{i + 1}.</span><div>{item}</div></li>
            ))}
        </ol>
    );
}

function Sub({ items }) {
    return (
        <ol className="fp-sublist">
            {items.map((item, i) => <li key={i}><span>{"abcdefgh"[i]}.</span><div>{item}</div></li>)}
        </ol>
    );
}

function DanaForm({ f, settings, ppnpn }) {
    const max = Number(ppnpn ? settings.financing_max_ppnpn : settings.financing_max_pegawai);
    const options = [5000000, 10000000, 15000000].filter((v) => v <= max);
    const fee = Number(ppnpn ? settings.financing_fee_ppnpn : settings.financing_fee_pegawai);
    const bank = settings.coop_bank || "rekening Koperasi";
    const contact = (ppnpn ? settings.coop_contact_ppnpn : settings.coop_contact_pegawai) || "Bendahara Koperasi";
    const payWays = <Sub items={[`transfer bank ke rekening ${bank};`, `menyampaikan konfirmasi berupa bukti pembayaran melalui ${contact}.`]} />;
    return (
        <>
            <section className="fp-sheet">
                <Header title="PERMOHONAN FASILITAS PEMBIAYAAN DANA" number={f?.number} code="FPD" appliedAt={f?.applied_at} />
                <Applicant f={f} />
                <div className="fp-gap" />
                <p>dengan ini mengajukan permohonan Fasilitas Pembiayaan Dana Koperasi sebagai berikut:</p>
                <div className="fp-gap-s" />
                <Terms
                    items={[
                        <table className="fp-fields" key="t"><tbody>
                            <tr><td className="l" style={{ width: "51mm" }}>Jumlah Pembiayaan</td><td className="c">:</td><td>{options.map((v) => <span key={v} className="fp-check">{f && f.principal === v ? "☑" : "☐"} {rupiah(v)}</span>)}</td></tr>
                            <tr><td className="l" style={{ width: "51mm" }}>Jangka Waktu Pembiayaan</td><td className="c">:</td><td>{f?.tenor ? `${f.tenor} bulan` : <><span className="fp-blank s" /> bulan</>}</td></tr>
                        </tbody></table>,
                        "Menyetujui syarat dan ketentuan sebagaimana tercantum dalam Lampiran yang tidak terpisahkan dari formulir Fasilitas Pembiayaan Dana ini.",
                        "Telah menyerahkan surat pernyataan kesediaan untuk melakukan penyelesaian kewajiban kepada Koperasi.",
                    ]}
                />
                <div className="fp-gap" />
                <Signatures f={f} settings={settings} />
            </section>

            <section className="fp-sheet">
                <p className="fp-subtitle">SYARAT DAN KETENTUAN</p>
                <p className="fp-subtitle">PERMOHONAN FASILITAS PEMBIAYAAN DANA</p>
                <div className="fp-gap" />
                <Terms
                    items={[
                        "Fasilitas Pembiayaan Dana diberikan sehubungan dengan penyerahan sejumlah uang sebagai pinjaman;",
                        "Pembiayaan dana dilakukan oleh Koperasi kepada Pemohon;",
                        ...(ppnpn
                            ? [
                                  "Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;",
                                  <>Biaya formulir sebesar {rupiah(fee)} per {rupiah(FEE_UNIT)} pembiayaan, dibayarkan setelah berkas permohonan disetujui oleh Tim Pembiayaan Koperasi dan sebelum pencairan dana, melalui:{payWays}</>,
                                  "Fasilitas Pembiayaan Dana dapat diberikan kepada Pemohon setelah melakukan pembayaran biaya sebagaimana dimaksud pada angka 4;",
                                  `Fasilitas Pembiayaan Dana diberikan setinggi-tingginya sebesar ${rupiah(max)};`,
                              ]
                            : [
                                  <>Biaya formulir sebesar {rupiah(fee)} per {rupiah(FEE_UNIT)} pembiayaan, dapat dibayarkan secara:{payWays}</>,
                                  `Fasilitas Pembiayaan Dana diberikan setinggi-tingginya sebesar ${rupiah(max)};`,
                                  "Fasilitas Pembiayaan Dana dapat diberikan kepada Pemohon setelah melakukan pembayaran biaya formulir sebagaimana dimaksud pada angka 3;",
                                  "Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;",
                              ]),
                        `Pembayaran kembali pembiayaan dapat dilakukan secara angsuran pada bulan berikutnya dengan jangka waktu pembayaran 1 s.d. ${settings.financing_tenor_dana_max} bulan setelah dana diterima oleh Pemohon;`,
                        `Pembayaran angsuran pengembalian Fasilitas Pembiayaan Dana dilakukan melalui mekanisme autodebet pada rekening pembayaran penghasilan Pemohon${ppnpn ? "; apabila tidak berhasil, Pemohon wajib melakukan transfer ke rekening yang sudah ditentukan dan berkoordinasi dengan Bendahara" : ""};`,
                        <>
                            Dalam hal keanggotaan koperasi Pemohon berakhir disebabkan:
                            <Sub
                                items={[
                                    "mutasi keluar (pindah tugas), sisa angsuran pembayaran wajib dilunasi sebelum Pemohon pindah tugas;",
                                    `selain mutasi keluar, sisa angsuran pembayaran wajib dilunasi sebelum Pemohon keluar dari keanggotaan ${settings.coop_name || "Koperasi"};`,
                                ]}
                            />
                        </>,
                        "Pelunasan Fasilitas Pembiayaan Dana dapat dilakukan sebelum jangka waktu pembayaran yang disepakati berakhir.",
                    ]}
                />
                <div className="fp-gap" />
                <Approval f={f} settings={settings} />
            </section>
        </>
    );
}

function BarangForm({ f, settings }) {
    return (
        <>
            <section className="fp-sheet">
                <Header title="PERMOHONAN FASILITAS PEMBIAYAAN BARANG" number={f?.number} code="FPB" appliedAt={f?.applied_at} />
                <Applicant f={f} />
                <div className="fp-gap" />
                <p>dengan ini mengajukan permohonan Fasilitas Pembiayaan Barang Koperasi sebagai berikut:</p>
                <div className="fp-gap-s" />
                <Terms
                    items={[
                        <table className="fp-fields" key="t"><tbody>
                            {[
                                ["Nama / jenis barang", f?.item_name],
                                ["Harga barang", f ? rupiah(f.principal) : null],
                                [`Bagi hasil (${f?.margin_percent ?? settings.financing_margin_barang}%)`, f ? rupiah(f.margin_amount) : null],
                                ["Jangka waktu", f?.tenor ? `${f.tenor} bulan` : null],
                                ["Angsuran per bulan", f ? rupiah(f.installment) : null],
                            ].map(([l, v]) => <tr key={l}><td className="l" style={{ width: "51mm" }}>{l}</td><td className="c">:</td><td><V v={v} /></td></tr>)}
                        </tbody></table>,
                        "Menyetujui syarat dan ketentuan sebagaimana tercantum dalam Lampiran yang tidak terpisahkan dari formulir Fasilitas Pembiayaan Barang ini.",
                        "Telah menyerahkan surat pernyataan kesediaan untuk melakukan penyelesaian kewajiban kepada Koperasi.",
                    ]}
                />
                <div className="fp-gap" />
                <Signatures f={f} settings={settings} />
            </section>
            <section className="fp-sheet">
                <p className="fp-subtitle">SYARAT DAN KETENTUAN</p>
                <p className="fp-subtitle">PERMOHONAN FASILITAS PEMBIAYAAN BARANG</p>
                <div className="fp-gap" />
                <Terms
                    items={[
                        "Fasilitas Pembiayaan Barang diberikan dalam bentuk pembelian barang oleh Koperasi untuk Pemohon yang dibayar kembali secara angsuran;",
                        `Harga yang wajib dibayar Pemohon adalah harga barang ditambah bagi hasil sebesar ${settings.financing_margin_barang}% dari harga barang;`,
                        `Biaya lain sebesar ${settings.financing_admin_barang}% dari harga barang dipotong pada saat pencairan;`,
                        `Pembayaran kembali dilakukan secara angsuran mulai bulan berikutnya selama ${settings.financing_tenor_barang} bulan, atau lebih cepat sesuai kesepakatan;`,
                        "Pembayaran angsuran dilakukan melalui mekanisme autodebet pada rekening pembayaran penghasilan Pemohon;",
                        "Melampirkan pindaian berwarna KTP dan Kartu Keluarga terbaru Pemohon;",
                        `Dalam hal Pemohon mutasi atau keluar dari keanggotaan ${settings.coop_name || "Koperasi"}, sisa angsuran wajib dilunasi sebelum Pemohon pindah tugas/keluar;`,
                        "Pelunasan dapat dilakukan sebelum jangka waktu pembayaran yang disepakati berakhir.",
                    ]}
                />
                <div className="fp-gap" />
                <Approval f={f} settings={settings} />
            </section>
        </>
    );
}

function Disbursement({ f, settings }) {
    const c = f.customer;
    const kind = f.type === "barang" ? "Pembiayaan Barang" : "Pembiayaan Dana";
    return (
        <section className="fp-sheet">
            <p className="fp-title">BUKTI PENCAIRAN {f.type === "barang" ? "PEMBIAYAAN BARANG" : "DANA"}</p>
            <p className="fp-center">{settings.coop_name}</p>
            <div className="fp-gap" />
            <Fields
                rows={[
                    ["Nomor formulir", f.number],
                    ["Tanggal", longDay(f.disbursed_at)],
                    ["Jumlah", rupiah(f.transferred_amount ?? f.principal)],
                    ["Terbilang", words(f.transferred_amount ?? f.principal)],
                    ["Keperluan", `Pencairan ${kind}${f.type === "barang" && f.item_name ? ` (${f.item_name})` : ""} a.n. ${c.name}`],
                    ["Pembayaran", f.bank_name ? `Transfer ke ${f.bank_name} No. Rek. ${f.bank_account || "-"} a.n. ${c.name}` : null],
                    ["Nilai pembiayaan", `${rupiah(f.principal)}${f.margin_amount ? ` + bagi hasil ${rupiah(f.margin_amount)}` : ""}, ${f.tenor} × ${rupiah(f.installment)}`],
                    [f.type === "barang" ? "Biaya lain" : "Biaya formulir", rupiah(f.admin_fee)],
                ]}
            />
            <div className="fp-gap" />
            <div className="fp-gap" />
            <div className="fp-signs">
                <div>
                    <p>&nbsp;</p>
                    <p>Menyetujui,</p>
                    <p>Ketua Koperasi</p>
                    <div className="fp-sign-space" />
                    <p className="fp-sign-name"><V v={f.approved_signer || f.approver?.name || settings.coop_chair} /></p>
                </div>
                <div>
                    <p>{settings.coop_city}, {longDate(f.disbursed_at)}</p>
                    <p>Penerima,</p>
                    <p>&nbsp;</p>
                    <div className="fp-sign-space" />
                    <p className="fp-sign-name">{c.name}</p>
                </div>
            </div>
        </section>
    );
}

export default function Print({ doc, type, financing, settings: rules, coop = {}, ppnpn }) {
    // Identitas koperasi dari Profil Toko & SK Pengurus (bukan isian terpisah).
    const settings = { ...rules, coop_name: coop.name, coop_city: coop.city || "", coop_chair: coop.chair, coop_business_head: coop.business_head };
    return (
        <div className="light-surface min-h-screen bg-gray-200 py-6 text-black print:bg-white print:py-0">
            <Head title={doc === "pencairan" ? "Bukti Pencairan" : "Formulir Pembiayaan"} />
            <style>{CSS}</style>
            <div className="fp-toolbar mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 print:hidden">
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
