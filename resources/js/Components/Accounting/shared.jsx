import React, { useState } from "react";
import { Link, router } from "@inertiajs/react";
import { inputBase, btn } from "@/Components/Cooperative/ui";
import { SearchSelect } from "@/Components/Cooperative/inputs";

/** Angka akuntansi: negatif dalam kurung, nol jadi "-". */
export const idr = (v) => {
    const n = Math.round(Number(v || 0));
    if (n === 0) return "-";
    const s = Math.abs(n).toLocaleString("id-ID");
    return n < 0 ? `(${s})` : s;
};

export const DIVISION_COLORS = {
    toko: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",
    pengadaan: "bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300",
    dana: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",
    barang: "bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300",
    simpanan: "bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300",
    umum: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
    tutup: "bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300",
};

export function DivisionBadge({ division, divisions }) {
    if (!division) return null;
    return (
        <span className={`inline-flex rounded-md px-1.5 py-0.5 text-[11px] font-medium ${DIVISION_COLORS[division] || DIVISION_COLORS.umum}`}>
            {divisions?.[division] || division}
        </span>
    );
}

const TABS = [
    ["accounting.journals", "Jurnal"],
    ["accounting.record", "Catat Kejadian"],
    ["accounting.ledger", "Buku Besar"],
    ["accounting.trial-balance", "Neraca Saldo"],
    ["accounting.reports", "Laporan Keuangan"],
    ["accounting.kantin", "Rekap Kantin"],
    ["accounting.accounts", "COA & Pengaturan"],
    ["accounting.opening", "Saldo Awal"],
];

export function AccountingTabs() {
    return (
        <div className="no-scrollbar mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800 print:hidden">
            {TABS.map(([name, label]) => {
                const active = route().current(name);
                return (
                    <Link
                        key={name}
                        href={route(name)}
                        className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                            active ? "border-accent-500 text-slate-900 dark:text-white" : "border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                        }`}
                    >
                        {label}
                    </Link>
                );
            })}
        </div>
    );
}

const pad = (n) => String(n).padStart(2, "0");
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Pilih periode: bulan cepat atau tanggal bebas. */
export function PeriodFilter({ routeName, filters, extra = {}, children }) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const go = (f, t) => router.get(route(routeName), { ...filters, ...extra, from: f, to: t }, { preserveState: true, preserveScroll: true });
    const month = (offset) => {
        const base = new Date(filters.from + "T00:00:00");
        const d1 = new Date(base.getFullYear(), base.getMonth() + offset, 1);
        const d2 = new Date(d1.getFullYear(), d1.getMonth() + 1, 0);
        go(iso(d1), iso(d2));
    };
    const year = () => {
        const y = new Date(filters.to + "T00:00:00").getFullYear();
        go(`${y}-01-01`, `${y}-12-31`);
    };
    return (
        <div className="mb-3 flex flex-wrap items-end gap-2 print:hidden">
            <button type="button" className={btn.secondary} onClick={() => month(-1)}>‹ Bulan lalu</button>
            <label className="text-xs text-slate-500">
                Dari
                <input type="date" className={`${inputBase} ml-1`} value={from} onChange={(e) => setFrom(e.target.value)} />
            </label>
            <label className="text-xs text-slate-500">
                s.d.
                <input type="date" className={`${inputBase} ml-1`} value={to} onChange={(e) => setTo(e.target.value)} />
            </label>
            <button type="button" className={btn.primary} onClick={() => go(from, to)}>Tampilkan</button>
            <button type="button" className={btn.secondary} onClick={() => month(1)}>Bulan depan ›</button>
            <button type="button" className={btn.secondary} onClick={year}>Setahun</button>
            {children}
        </div>
    );
}

export const accountOptions = (accounts, filter = () => true) =>
    accounts.filter(filter).map((a) => ({ value: a.code, label: `${a.code} ${a.name}`, sub: a.is_active === false ? "tidak aktif" : undefined }));

export function AccountSelect({ accounts, value, onChange, filter, placeholder = "Pilih akun…", className = "" }) {
    return <SearchSelect options={accountOptions(accounts, filter)} value={value} onChange={onChange} placeholder={placeholder} className={className} />;
}

export const fmtDate = (s) => {
    if (!s) return "";
    const d = new Date(s + "T00:00:00");
    return d.toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" });
};
