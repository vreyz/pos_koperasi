import React, { useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconDownload, IconFileText, IconPencil, IconPlus, IconTrash, IconUpload, IconUsersGroup, IconX } from "@tabler/icons-react";
import Swal from "sweetalert2";
import SortableTable from "@/Components/Cooperative/SortableTable";
import { useAuthorization } from "@/Utils/authorization";
import { Badge, Field, PageHeader, Panel, btn, inputClass, td, th, useFlashToasts } from "@/Components/Cooperative/ui";
import { SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "");
const period = (d) => `${fmtDate(d.effective_from)} – ${d.effective_until ? fmtDate(d.effective_until) : "sampai SK berikutnya"}`;

/** Teks PDF dibaca di browser (pdf.js dimuat hanya saat dibutuhkan). */
async function pdfText(file) {
    const pdfjs = await import("pdfjs-dist/legacy/build/pdf.mjs");
    // ?worker: Vite menyimpan worker sebagai file .js (aman untuk MIME type Apache/Laragon).
    const { default: PdfWorker } = await import("pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker");
    pdfjs.GlobalWorkerOptions.workerPort ??= new PdfWorker();
    const pdf = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
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
    const current = decrees.find((d) => d.id === currentId);

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
        if (!["pdf", "docx"].includes(ext)) {
            setReadInfo({ ok: false, text: "File gambar/scan tidak bisa dibaca otomatis. Isi daftar pengurus di bawah secara manual." });
            return;
        }
        setReading(true);
        try {
            const body = new FormData();
            if (ext === "docx") body.append("file", file);
            else body.append("text", await pdfText(file));
            const { data } = await window.axios.post(route("board.preview"), body);
            if (!data.members.length) {
                setReadInfo({ ok: false, text: "Tidak ada nama pegawai yang terbaca dari SK (mungkin hasil scan). Isi daftar pengurus secara manual." });
            } else {
                setReadInfo({ ok: true, text: `${data.members.length} nama pengurus terbaca dari SK. Periksa nama & jabatannya sebelum menyimpan.` });
            }
            setForm({
                ...next,
                number: next.number || data.number || "",
                decree_date: next.decree_date || data.decree_date || "",
                effective_from: next.effective_from || data.decree_date || "",
                members: data.members.length ? data.members.map((m) => ({ customer_id: m.customer_id, position: m.position || "" })) : next.members,
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
        { key: "number", label: "Nomor SK", filter: "text", value: (d) => d.number || "", render: (d) => <><div className="font-medium">{d.number || "-"}</div><div className="text-xs text-slate-500">{fmtDate(d.decree_date)}</div></> },
        { key: "effective_from", label: "Masa berlaku", value: (d) => d.effective_from, render: (d) => <>{period(d)} {d.id === currentId && <Badge color="green">Berlaku</Badge>}</> },
        {
            key: "members",
            label: "Pengurus",
            filter: "text",
            value: (d) => d.members.map((m) => `${m.position || ""} ${m.customer?.name}`).join(", "),
            render: (d) => <div className="text-xs leading-5">{d.members.map((m) => <div key={m.id}><span className="text-slate-500">{m.position || "Pengurus"}:</span> {m.customer?.name}</div>)}</div>,
        },
        {
            key: "actions",
            label: "",
            value: () => "",
            render: (d) => (
                <div className="flex justify-end gap-2">
                    {d.file_name && <a href={route("board.file", d.id)} target="_blank" className={btn.secondary} title={d.file_name}><IconFileText size={16} /> SK</a>}
                    {manage && <button onClick={() => openEdit(d)} className={btn.secondary}><IconPencil size={16} /></button>}
                    {manage && <button onClick={() => remove(d)} className={btn.secondary + " text-danger-600"}><IconTrash size={16} /></button>}
                </div>
            ),
        },
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
                                        <SearchSelect options={empOptions} value={m.customer_id} onChange={(v) => setMember(i, { customer_id: Number(v) })} placeholder="Cari nama / NIP…" required />
                                        <button type="button" onClick={() => setForm({ ...form, members: form.members.filter((_, j) => j !== i) })} className="text-danger-600" title="Hapus"><IconX size={16} /></button>
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

            {current && (
                <Panel title={`Pengurus saat ini — SK ${current.number || ""}`} className="mb-6">
                    <table className="w-full text-sm">
                        <thead><tr><th className={th}>Jabatan</th><th className={th}>Nama</th><th className={th}>NIP</th></tr></thead>
                        <tbody>
                            {current.members.map((m) => (
                                <tr key={m.id}><td className={td}>{m.position || "-"}</td><td className={td}>{m.customer?.name}</td><td className={td}>{m.customer?.nip || "-"}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>
            )}

            <Panel title={`Riwayat SK pengurus (${decrees.length})`}>
                <SortableTable rows={decrees} rowKey={(d) => d.id} columns={columns} initialSort={{ key: "effective_from", dir: "desc" }} emptyText="Belum ada SK. Klik Upload SK." />
            </Panel>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
