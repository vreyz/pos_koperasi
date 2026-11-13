import React, { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconDownload, IconFileText, IconPencil, IconPlus, IconTrash, IconUpload, IconUsersGroup, IconX } from "@tabler/icons-react";
import Swal from "sweetalert2";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, PageHeader, Panel, btn, inputClass, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "");
const dayBefore = (date) => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/** SK diurutkan dari yang terlama; periode berakhir sehari sebelum SK berikutnya berlaku (atau tanggal "berlaku sampai" bila diisi). */
function withPeriods(decrees) {
    const ordered = [...decrees].sort((a, b) => a.effective_from.localeCompare(b.effective_from) || a.id - b.id);
    return ordered.map((d, i) => ({
        ...d,
        seq: i + 1,
        period_until: d.effective_until || (ordered[i + 1] ? dayBefore(ordered[i + 1].effective_from) : null),
    }));
}

async function openPdf(file) {
    const pdfjs = await import("pdfjs-dist/legacy/build/pdf.mjs");
    // ?worker: Vite menyimpan worker sebagai file .js (aman untuk MIME type Apache/Laragon).
    const { default: PdfWorker } = await import("pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker");
    pdfjs.GlobalWorkerOptions.workerPort ??= new PdfWorker();
    return pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
}

/** PDF hasil scan (tanpa teks): halaman dirender jadi gambar PNG untuk dibaca OCR di server. */
async function pdfPages(pdf) {
    const blobs = [];
    for (let p = 1; p <= Math.min(pdf.numPages, 10); p++) {
        const page = await pdf.getPage(p);
        const viewport = page.getViewport({ scale: 2 });
        const canvas = document.createElement("canvas");
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        const ctx = canvas.getContext("2d");
        ctx.fillStyle = "#fff";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        blobs.push(await new Promise((resolve) => canvas.toBlob(resolve, "image/png")));
    }
    return blobs;
}

/** Teks PDF dibaca di browser (pdf.js dimuat hanya saat dibutuhkan). */
async function pdfText(pdf) {
    const lines = [];
    for (let p = 1; p <= Math.min(pdf.numPages, 20); p++) {
        const content = await (await pdf.getPage(p)).getTextContent();
        let line = "";
        let lastY = null;
        for (const item of content.items) {
            const y = Math.round(item.transform[5]);
            if (lastY !== null && Math.abs(y - lastY) > 3) {
                lines.push(line);
                line = "";
            }
            line += (line && !line.endsWith(" ") ? " " : "") + item.str;
            lastY = y;
            if (item.hasEOL) {
                lines.push(line);
                line = "";
                lastY = null;
            }
        }
        lines.push(line);
    }
    return lines.join("\n");
}

const blankForm = () => ({ id: null, number: "", decree_date: "", effective_from: "", effective_until: "", notes: "", file: null, file_name: "", members: [] });

export default function Index({ decrees, currentId, positions, employees }) {
    useFlashToasts();
    const { can } = useAuthorization();
    const manage = can("board-manage");
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [reading, setReading] = useState(false);
    const [readInfo, setReadInfo] = useState(null);
    const [saving, setSaving] = useState(false);
    const empOptions = useMemo(() => employeeOptions(employees), [employees]);
    const rows = useMemo(() => withPeriods(decrees), [decrees]);
    const [selectedId, setSelectedId] = useState(currentId ?? rows[rows.length - 1]?.id ?? null);
    const selected = rows.find((d) => d.id === selectedId);

    const openNew = () => { setForm(blankForm()); setErrors({}); setReadInfo(null); };
    const openEdit = (d) => {
        setForm({ ...blankForm(), ...d, decree_date: d.decree_date || "", effective_until: d.effective_until || "", notes: d.notes || "", file: null, members: d.members.map((m) => ({ customer_id: m.customer_id, position: m.position || "" })) });
        setErrors({});
        setReadInfo(null);
    };

    const pickFile = async (file) => {
        if (!file) return;
        const next = { ...form, file };
        setForm(next);
        const ext = file.name.split(".").pop().toLowerCase();
        if (!["pdf", "docx", "jpg", "jpeg", "png"].includes(ext)) {
            setReadInfo({ ok: false, text: "File .doc lama tidak bisa dibaca otomatis. Simpan ulang sebagai .docx/PDF, atau isi daftar pengurus secara manual." });
            return;
        }
        setReading(true);
        try {
            const body = new FormData();
            if (ext !== "pdf") body.append("file", file); // Word dibaca server; foto/scan dibaca OCR di server
            else {
                const pdf = await openPdf(file);
                const text = await pdfText(pdf);
                if (text.replace(/\s/g, "").length >= 200) body.append("text", text);
                else (await pdfPages(pdf)).forEach((blob, i) => body.append("pages[]", blob, `halaman-${i + 1}.png`)); // PDF hasil scan
            }
            const { data } = await window.axios.post(route("board.preview"), body);
            const manual = data.members.filter((m) => !m.customer_id).length;
            const check = data.members.filter((m) => m.customer_id && m.check).length;
            const scanNote = data.ocr ? " SK hasil scan dibaca dengan OCR: periksa nomor, tanggal, dan ejaan nama." : "";
            if (data.ocr === false) {
                setReadInfo({ ok: false, text: "SK ini hasil scan, tetapi OCR (Tesseract) belum terpasang di server. Isi daftar pengurus secara manual." });
            } else if (!data.members.length) {
                setReadInfo({ ok: false, text: "Tidak ada nama pegawai yang terbaca dari SK. Isi daftar pengurus secara manual." });
            } else {
                setReadInfo({
                    ok: manual === 0 && !data.ocr,
                    text: `${data.members.length} pengurus terbaca dari SK` + (check ? `, ${check} dicocokkan ke nama mirip (periksa)` : "") + (manual ? `, ${manual} belum ketemu di Data Pegawai (pilih manual atau hapus barisnya)` : "") + "." + scanNote,
                });
            }
            setForm({
                ...next,
                number: next.number || data.number || "",
                decree_date: next.decree_date || data.decree_date || "",
                effective_from: next.effective_from || data.decree_date || "",
                members: data.members.length ? data.members.map((m) => ({ customer_id: m.customer_id || "", position: m.position || "", sk_name: m.sk_name, sk_position: m.sk_position, check: m.check })) : next.members,
            });
        } catch (e) {
            setReadInfo({ ok: false, text: "SK gagal dibaca otomatis. Isi daftar pengurus secara manual." });
        } finally {
            setReading(false);
        }
    };

    const setMember = (i, patch) => setForm({ ...form, members: form.members.map((m, j) => (j === i ? { ...m, ...patch } : m)) });

    const save = (e) => {
        e.preventDefault();
        setSaving(true);
        const payload = { number: form.number, decree_date: form.decree_date, effective_from: form.effective_from, effective_until: form.effective_until, notes: form.notes, members: form.members, ...(form.file ? { file: form.file } : {}) };
        const options = { forceFormData: true, preserveScroll: true, onError: setErrors, onSuccess: () => setForm(null), onFinish: () => setSaving(false) };
        form.id ? router.post(route("board.update", form.id), { ...payload, _method: "put" }, options) : router.post(route("board.store"), payload, options);
    };

    const remove = (d) =>
        Swal.fire({ title: "Hapus SK ini?", text: "File SK dan daftar pengurusnya ikut terhapus.", icon: "warning", showCancelButton: true, confirmButtonText: "Hapus", cancelButtonText: "Batal" }).then(
            (r) => r.isConfirmed && router.delete(route("board.destroy", d.id), { preserveScroll: true }),
        );

    const columns = [
        { key: "seq", label: "No", value: (d) => d.seq },
        { key: "number", label: "Nomor SK", filter: "text", value: (d) => d.number || "", render: (d) => <span className="font-medium text-slate-900 dark:text-white">{d.number || "-"}</span> },
        { key: "decree_date", label: "Tanggal SK", value: (d) => d.decree_date || "", render: (d) => fmtDate(d.decree_date) || "-" },
        {
            key: "effective_from",
            label: "Periode",
            value: (d) => d.effective_from,
            render: (d) => (
                <span className="inline-flex items-center gap-2">
                    {fmtDate(d.effective_from)} – {d.period_until ? fmtDate(d.period_until) : "sekarang"}
                    {d.id === currentId && <Badge color="green">Berlaku</Badge>}
                </span>
            ),
        },
        { key: "count", label: "Pengurus", align: "right", value: (d) => d.members.length, render: (d) => `${d.members.length} orang` },
        {
            key: "actions",
            label: "",
            value: () => "",
            render: (d) => (
                <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                    {d.file_name && <a href={route("board.file", d.id)} target="_blank" className={btn.secondary} title={d.file_name}><IconFileText size={16} /> SK</a>}
                    {manage && <button onClick={() => openEdit(d)} className={btn.secondary} title="Ubah"><IconPencil size={16} /></button>}
                    {manage && <button onClick={() => remove(d)} className={btn.secondary + " text-danger-600"} title="Hapus"><IconTrash size={16} /></button>}
                </div>
            ),
        },
    ];

    const memberColumns = [
        { key: "no", label: "No", value: (m) => m.no },
        { key: "position", label: "Jabatan", filter: "text", value: (m) => m.position || "-" },
        { key: "name", label: "Nama", filter: "text", value: (m) => m.customer?.name || "", render: (m) => <span className="font-medium text-slate-900 dark:text-white">{m.customer?.name}</span> },
        { key: "nip", label: "NIP", filter: "text", value: (m) => m.customer?.nip || "", render: (m) => <span className="font-mono text-slate-700 dark:text-slate-200">{m.customer?.nip || "-"}</span> },
    ];

    return (
        <>
            <Head title="Pengurus Koperasi" />
            <PageHeader
                icon={IconUsersGroup}
                title="Pengurus Koperasi"
                subtitle="Upload SK pengurus: nama pengurus dibaca otomatis dari SK (PDF/Word), file SK disimpan. Masa berlaku SK dipakai untuk menghitung bulan bertugas di SHU Pengurus Koperasi."
                actions={
                    <>
                        {decrees.length > 0 && <a href={route("board.export")} className={btn.secondary}><IconDownload size={18} /> Unduh Excel</a>}
                        {manage && <button onClick={openNew} className={btn.primary}><IconUpload size={18} /> Upload SK</button>}
                    </>
                }
            />

            {form && (
                <Panel title={form.id ? "Ubah SK pengurus" : "Upload SK pengurus baru"} className="mb-6">
                    <form onSubmit={save} className="space-y-4 p-4">
                        <Field label="File SK (PDF, Word, atau foto)" error={errors.file} hint={form.id && form.file_name ? `File tersimpan: ${form.file_name}. Pilih file baru hanya bila ingin mengganti.` : "PDF/Word dibaca otomatis; foto/scan diisi manual."}>
                            <input type="file" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png" onChange={(e) => pickFile(e.target.files[0])} className={inputClass} required={!form.id} />
                        </Field>
                        {reading && <p className="text-sm text-slate-500">Membaca SK…</p>}
                        {readInfo && <p className={`text-sm ${readInfo.ok ? "text-success-600" : "text-warning-600"}`}>{readInfo.text}</p>}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Nomor SK" error={errors.number}><input className={inputClass} value={form.number} onChange={(e) => setForm({ ...form, number: e.target.value })} /></Field>
                            <Field label="Tanggal SK" error={errors.decree_date}><input type="date" className={inputClass} value={form.decree_date} onChange={(e) => setForm({ ...form, decree_date: e.target.value })} /></Field>
                            <Field label="Berlaku mulai" error={errors.effective_from} tip="Bulan bertugas di SHU dihitung mulai tanggal ini.">
                                <input type="date" required className={inputClass} value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} />
                            </Field>
                            <Field label="Berlaku sampai" error={errors.effective_until} tip="Kosongkan bila berlaku sampai ada SK pengurus berikutnya.">
                                <input type="date" className={inputClass} value={form.effective_until} onChange={(e) => setForm({ ...form, effective_until: e.target.value })} />
                            </Field>
                        </div>

                        <div>
                            <p className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">Daftar pengurus {errors.members && <span className="text-danger-600">— {errors.members}</span>}</p>
                            <datalist id="board-positions">{positions.map((p) => <option key={p} value={p} />)}</datalist>
                            <div className="space-y-2">
                                {form.members.map((m, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_auto] items-center gap-2 sm:grid-cols-[12rem_1fr_auto]">
                                        <input list="board-positions" className={inputClass + " col-span-2 sm:col-span-1"} value={m.position} onChange={(e) => setMember(i, { position: e.target.value })} placeholder="Jabatan" />
                                        <SearchSelect options={empOptions} value={m.customer_id} onChange={(v) => setMember(i, { customer_id: Number(v), check: false })} placeholder="Cari nama / NIP…" required />
                                        <button type="button" onClick={() => setForm({ ...form, members: form.members.filter((_, j) => j !== i) })} className="text-danger-600" title="Hapus"><IconX size={16} /></button>
                                        {m.sk_position && m.sk_position !== m.position && (
                                            <span className="col-span-full -mt-1 text-xs text-slate-500">Di SK: {m.sk_position}</span>
                                        )}
                                        {m.sk_name && (!m.customer_id || m.check) && (
                                            <span className={`col-span-full text-xs ${m.customer_id ? "text-warning-600" : "text-danger-600"}`}>
                                                Di SK tertulis "{m.sk_name}" — {m.customer_id ? "dicocokkan ke nama mirip, periksa" : "belum ketemu di Data Pegawai, pilih pegawainya atau hapus baris ini"}.
                                            </span>
                                        )}
                                        {errors[`members.${i}.customer_id`] && <span className="col-span-full text-xs text-danger-600">{errors[`members.${i}.customer_id`]}</span>}
                                    </div>
                                ))}
                                <button type="button" onClick={() => setForm({ ...form, members: [...form.members, { customer_id: "", position: "" }] })} className="flex items-center gap-1 text-sm text-primary-600 hover:underline"><IconPlus size={14} /> Tambah pengurus</button>
                            </div>
                        </div>

                        <Field label="Catatan" error={errors.notes}><textarea rows={2} className={inputClass} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setForm(null)} className={btn.secondary}>Batal</button>
                            <button type="submit" disabled={saving || reading} className={btn.primary}>{saving ? "Menyimpan..." : "Simpan SK & pengurus"}</button>
                        </div>
                    </form>
                </Panel>
            )}

            <Panel title={`SK pengurus (${decrees.length}) — klik SK untuk melihat daftar pengurusnya`} className="mb-4">
                <SortableTable
                    rows={rows}
                    rowKey={(d) => d.id}
                    columns={columns}
                    initialSort={{ key: "seq", dir: "asc" }}
                    onRowClick={(d) => setSelectedId(d.id)}
                    isSelected={(d) => d.id === selectedId}
                    emptyText="Belum ada SK. Klik Upload SK."
                />
            </Panel>

            {selected && (
                <Panel
                    key={selected.id}
                    title={`Pengurus — SK ${selected.number || "-"} (${fmtDate(selected.effective_from)} – ${selected.period_until ? fmtDate(selected.period_until) : "sekarang"})`}
                >
                    <SortableTable
                        rows={selected.members.map((m, i) => ({ ...m, no: i + 1 }))}
                        rowKey={(m) => m.id}
                        columns={memberColumns}
                        initialSort={{ key: "no", dir: "asc" }}
                        emptyText="SK ini belum punya daftar pengurus."
                    />
                </Panel>
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
