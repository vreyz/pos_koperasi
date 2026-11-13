import React, { useEffect, useMemo, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconPencilPlus, IconDeviceFloppy, IconX } from "@tabler/icons-react";
import { Field, PageHeader, Panel, btn, inputClass, useFlashToasts } from "@/Components/Cooperative/ui";
import { MoneyInput, SearchSelect, employeeOptions } from "@/Components/Cooperative/inputs";
import { AccountingTabs, DivisionBadge, idr } from "@/Components/Accounting/shared";

const pad = (n) => String(n).padStart(2, "0");
const today = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const thisMonth = () => today().slice(0, 7);
// Kejadian yang tidak butuh isian uang untuk dipratinjau (data diambil dari aplikasi / saldo akun).
const NO_AMOUNT_NEEDED = ["SP-01", "SP-02", "TB-03"];

function defaults(event) {
    const v = {};
    (event?.fields || []).forEach((f) => {
        if (f.default !== undefined) v[f.key] = f.default;
        if (f.type === "period") v[f.key] = thisMonth();
        if (f.type === "customers") v[f.key] = [];
    });
    return v;
}

function MultiCustomer({ options, value, onChange }) {
    const chosen = options.filter((o) => value.includes(o.value));
    return (
        <div>
            <SearchSelect options={options.filter((o) => !value.includes(o.value))} value="" onChange={(v) => v && onChange([...value, v])} placeholder="Tambah pegawai…" />
            {chosen.length > 0 && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {chosen.map((o) => (
                        <span key={o.value} className="inline-flex items-center gap-1 rounded-lg bg-danger-100 px-2 py-0.5 text-xs text-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
                            {o.label}
                            <button type="button" onClick={() => onChange(value.filter((x) => x !== o.value))}><IconX size={12} /></button>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Record({ divisions, events, accounts, employees, kantinShare, autoFrom, autoCodes, initialDivision, initialEvent }) {
    useFlashToasts();
    const accName = useMemo(() => Object.fromEntries(accounts.map((a) => [a.code, a.name])), [accounts]);
    const cashAccounts = accounts.filter((a) => a.is_cash && a.is_active);
    const people = useMemo(() => employeeOptions(employees), [employees]);
    const divisionKeys = Object.keys(divisions).filter((d) => events.some((e) => e.division === d));
    const firstEvent = events.find((e) => e.code === initialEvent);
    const [division, setDivision] = useState(firstEvent?.division || (divisionKeys.includes(initialDivision) ? initialDivision : divisionKeys[0]));
    const [code, setCode] = useState(firstEvent?.code || events.find((e) => e.division === division)?.code);
    const event = events.find((e) => e.code === code);
    const [date, setDate] = useState(today());
    const [note, setNote] = useState("");
    const [values, setValues] = useState(defaults(event));
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const timer = useRef(null);

    const pick = (c) => {
        const ev = events.find((e) => e.code === c);
        setCode(c);
        setValues(defaults(ev));
        setPreview(null);
        setError(null);
    };
    const pickDivision = (d) => {
        setDivision(d);
        pick(events.find((e) => e.division === d)?.code);
    };
    const set = (k, v) => setValues((old) => ({ ...old, [k]: v }));

    useEffect(() => {
        if (!event) return;
        clearTimeout(timer.current);
        const hasAmount = event.fields.some((f) => f.type === "money" && Number(values[f.key] || 0) > 0);
        if (!hasAmount && !NO_AMOUNT_NEEDED.includes(event.code)) {
            setPreview(null);
            setError(null);
            return;
        }
        timer.current = setTimeout(async () => {
            try {
                const { data } = await window.axios.post(route("accounting.record.preview"), { event: code, date, values, note });
                setPreview(data.entries);
                setError(null);
            } catch (e) {
                setPreview(null);
                const errs = e.response?.data?.errors;
                setError(errs ? Object.values(errs).flat().join(" ") : e.response?.data?.message || "Gagal membuat pratinjau.");
            }
        }, 350);
        return () => clearTimeout(timer.current);
    }, [code, date, JSON.stringify(values), note]);

    const save = () => {
        setSaving(true);
        router.post(route("accounting.record.store"), { event: code, date, values, note }, {
            preserveScroll: true,
            onSuccess: () => { setValues(defaults(event)); setNote(""); },
            onError: (errs) => setError(Object.values(errs).join(" ")),
            onFinish: () => setSaving(false),
        });
    };

    const renderField = (f) => {
        const v = values[f.key];
        switch (f.type) {
            case "money":
                return <MoneyInput value={v ?? ""} onChange={(x) => set(f.key, x)} />;
            case "cash":
                return (
                    <select className={inputClass} value={v ?? ""} onChange={(e) => set(f.key, e.target.value)}>
                        {cashAccounts.map((a) => <option key={a.code} value={a.code}>{a.code} {a.name}</option>)}
                    </select>
                );
            case "account":
                return (
                    <select className={inputClass} value={v ?? ""} onChange={(e) => set(f.key, e.target.value)}>
                        {f.options.map((c) => <option key={c} value={c}>{c} {accName[c] || ""}</option>)}
                    </select>
                );
            case "select":
                return (
                    <select className={inputClass} value={v ?? ""} onChange={(e) => set(f.key, e.target.value)}>
                        {Object.entries(f.options).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                    </select>
                );
            case "customer":
                return <SearchSelect options={people} value={v ?? ""} onChange={(x) => set(f.key, x)} placeholder="Cari nama / NIP…" clearable={!f.required} />;
            case "customers":
                return <MultiCustomer options={people} value={v || []} onChange={(x) => set(f.key, x)} />;
            case "period":
                return <input type="month" className={inputClass} value={v ?? ""} onChange={(e) => set(f.key, e.target.value)} />;
            default:
                return <input className={inputClass} value={v ?? ""} onChange={(e) => set(f.key, e.target.value)} />;
        }
    };

    return (
        <>
            <Head title="Catat Kejadian" />
            <PageHeader icon={IconPencilPlus} title="Catat Kejadian" subtitle="Pilih kejadian di divisi Anda; aplikasi menyusun jurnal debit/kredit secara otomatis." />
            <AccountingTabs />
            <div className="mb-3 flex flex-wrap gap-1.5">
                {divisionKeys.map((d) => (
                    <button
                        key={d}
                        type="button"
                        onClick={() => pickDivision(d)}
                        className={`rounded-xl px-3 py-1.5 text-sm font-medium transition-colors ${
                            d === division ? "bg-primary-600 text-white" : "bg-white text-slate-600 hover:bg-slate-100 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                        }`}
                    >
                        {divisions[d]}
                    </button>
                ))}
            </div>
            <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
                <Panel title="Kejadian">
                    <ul className="max-h-[70vh] overflow-y-auto py-1">
                        {events.filter((e) => e.division === division).map((e) => (
                            <li key={e.code}>
                                <button
                                    type="button"
                                    onClick={() => pick(e.code)}
                                    className={`flex w-full gap-2 px-3 py-1.5 text-left text-sm ${e.code === code ? "bg-primary-50 font-semibold text-primary-800 dark:bg-primary-900/30 dark:text-primary-200" : "hover:bg-slate-50 dark:hover:bg-slate-800/60"}`}
                                >
                                    <span className="font-mono text-xs text-slate-400">{e.code}</span>
                                    <span>{e.label}{autoCodes?.[e.code] && <span className="ml-1 rounded bg-sky-100 px-1 text-[10px] font-normal text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">otomatis</span>}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </Panel>
                {event && (
                    <div className="space-y-4">
                        <Panel title={<span className="flex items-center gap-2">{event.label} <DivisionBadge division={event.division} divisions={divisions} /></span>}>
                            <div className="space-y-3 p-4">
                                {autoCodes?.[event.code] && (
                                    <p className="rounded-xl bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-900/30 dark:text-sky-200">
                                        Mulai {new Date(autoFrom + "T00:00:00").toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" })} kejadian ini dijurnal otomatis dari menu <b>{autoCodes[event.code]}</b>. Isian di sini hanya untuk transaksi sebelum tanggal itu.
                                    </p>
                                )}
                                {event.hint && <p className="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">{event.hint}</p>}
                                {event.code === "TK-15" && (
                                    <p className="text-sm text-slate-600 dark:text-slate-300">
                                        Bagi hasil koperasi saat ini <b>{Number(kantinShare).toLocaleString("id-ID")}%</b> (ubah di COA &amp; Pengaturan).
                                    </p>
                                )}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Tanggal"><input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                                    {event.fields.map((f) => <Field key={f.key} label={f.label}>{renderField(f)}</Field>)}
                                    <Field label="Keterangan tambahan (opsional)"><input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} /></Field>
                                </div>
                            </div>
                        </Panel>
                        <Panel
                            title="Jurnal yang akan dibuat"
                            actions={
                                <button type="button" className={btn.primary} disabled={!preview || saving} onClick={save}>
                                    <IconDeviceFloppy size={16} /> {saving ? "Menyimpan…" : "Simpan jurnal"}
                                </button>
                            }
                        >
                            {error && <p className="px-4 py-3 text-sm text-danger-600">{error}</p>}
                            {!error && !preview && <p className="px-4 py-3 text-sm text-slate-500">Isi nilai untuk melihat jurnal.</p>}
                            {preview && preview.map((en, i) => {
                                const total = en.lines.reduce((s, l) => s + l.debit, 0);
                                return (
                                    <div key={i} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                        <p className="px-4 pt-2 text-sm font-medium">{preview.length > 1 ? `Jurnal ${i + 1}: ` : ""}{en.description}</p>
                                        <table className="w-full">
                                            <tbody>
                                                {en.lines.map((l, j) => (
                                                    <tr key={j}>
                                                        <td className={`py-0.5 pr-3 text-sm ${l.credit ? "pl-10" : "pl-4"}`}>
                                                            <span className="font-mono text-xs text-slate-500">{l.account}</span> {l.name}
                                                            {l.customer && <span className="text-xs text-slate-500"> · {l.customer}</span>}
                                                        </td>
                                                        <td className="w-36 px-3 py-0.5 text-right font-mono text-sm">{l.debit ? idr(l.debit) : ""}</td>
                                                        <td className="w-36 px-4 py-0.5 text-right font-mono text-sm">{l.credit ? idr(l.credit) : ""}</td>
                                                    </tr>
                                                ))}
                                                <tr className="border-t border-slate-100 text-sm font-semibold dark:border-slate-800">
                                                    <td className="px-4 py-1 text-right text-xs text-slate-500">Jumlah</td>
                                                    <td className="px-3 py-1 text-right font-mono">{idr(total)}</td>
                                                    <td className="px-4 py-1 text-right font-mono">{idr(total)}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                );
                            })}
                        </Panel>
                    </div>
                )}
            </div>
        </>
    );
}

Record.layout = (page) => <DashboardLayout children={page} />;
