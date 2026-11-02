import React, { useMemo, useState } from "react";
import { IconArrowsSort, IconSortAscending, IconSortDescending } from "@tabler/icons-react";
import { inputBase, td, th } from "@/Components/Cooperative/ui";

/**
 * Tabel dengan urut (klik judul kolom) dan saring per kolom, semuanya di browser.
 * Urut bertingkat: klik = urutan utama, Shift+klik = tambah urutan berikutnya (mis. Nama lalu Nilai).
 *
 * columns: [{ key, label, value: (row) => string|number, render?: (row) => node,
 *             filter?: "text" | "number" | "select", options?: [{value,label}], align?: "right", className? }]
 * initialSort: { key, dir } atau daftar [{ key, dir }, ...]
 * onRowClick / isSelected: baris bisa diklik (mis. memilih SK) dan ditandai terpilih.
 *
 * Saringan angka menerima: 0, >0, >=100000, <50000, =100000, 100000-500000, kosong.
 */
const MAX_SORTS = 3;

function compare(x, y) {
    if (typeof x === "number" || typeof y === "number") return (Number(x) || 0) - (Number(y) || 0);
    return String(x ?? "").localeCompare(String(y ?? ""), "id", { sensitivity: "base", numeric: true });
}
export function matchNumber(value, expr) {
    const raw = String(expr).replace(/\s|\./g, "").replace(",", ".").toLowerCase();
    if (raw === "") return true;
    const v = Number(value || 0);
    if (raw === "kosong" || raw === "-") return v === 0;
    const range = raw.match(/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/);
    if (range) return v >= Number(range[1]) && v <= Number(range[2]);
    const cmp = raw.match(/^(>=|<=|>|<|=)?(-?\d+(?:\.\d+)?)$/);
    if (!cmp) return true;
    const n = Number(cmp[2]);
    switch (cmp[1]) {
        case ">": return v > n;
        case "<": return v < n;
        case ">=": return v >= n;
        case "<=": return v <= n;
        default: return v === n;
    }
}

export default function SortableTable({ columns, rows, rowKey, initialSort, initialFilters = {}, emptyText = "Tidak ada data.", footer, onRowClick, isSelected }) {
    const [sorts, setSorts] = useState(() => {
        const initial = initialSort || { key: columns[0].key, dir: "asc" };
        return Array.isArray(initial) ? initial : [initial];
    });
    const [filters, setFilters] = useState(initialFilters);

    const visible = useMemo(() => {
        const active = columns.filter((c) => c.filter && (filters[c.key] ?? "") !== "");
        const filtered = rows.filter((row) =>
            active.every((c) => {
                const f = filters[c.key];
                const v = c.value(row);
                if (c.filter === "number") return matchNumber(v, f);
                if (c.filter === "select") return String(v) === String(f);
                return String(v ?? "").toLowerCase().includes(String(f).toLowerCase());
            })
        );
        const keys = sorts
            .map((s) => ({ col: columns.find((c) => c.key === s.key), dir: s.dir === "asc" ? 1 : -1 }))
            .filter((s) => s.col);
        if (keys.length === 0) return filtered;
        return [...filtered].sort((a, b) => {
            for (const { col, dir } of keys) {
                const result = compare(col.value(a), col.value(b));
                if (result !== 0) return result * dir;
            }
            return 0;
        });
    }, [rows, columns, filters, sorts]);

    const toggleSort = (key, addLevel) =>
        setSorts((current) => {
            const index = current.findIndex((s) => s.key === key);
            const flip = (s) => ({ ...s, dir: s.dir === "asc" ? "desc" : "asc" });
            if (!addLevel) {
                return index === 0 ? [flip(current[0])] : [{ key, dir: "asc" }];
            }
            if (index >= 0) return current.map((s, i) => (i === index ? flip(s) : s));
            return [...current, { key, dir: "asc" }].slice(-MAX_SORTS);
        });
    const hasFilters = Object.values(filters).some((v) => v !== "" && v != null);
    const label = (key) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <div className="overflow-x-auto">
            <table className="w-full">
                <thead className="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        {columns.map((c) => {
                            const level = sorts.findIndex((s) => s.key === c.key);
                            const active = sorts[level];
                            return (
                                <th key={c.key} className={`${th} ${c.align === "right" ? "text-right" : ""} ${c.className || ""}`}>
                                    <button
                                        type="button"
                                        onClick={(e) => toggleSort(c.key, e.shiftKey || e.ctrlKey || e.metaKey)}
                                        title="Klik: urutkan. Shift+klik: tambah urutan berikutnya."
                                        className={`inline-flex items-center gap-1 uppercase hover:text-slate-800 dark:hover:text-white ${active ? "text-slate-800 dark:text-white" : ""} ${c.align === "right" ? "flex-row-reverse" : ""}`}
                                    >
                                        {c.label}
                                        {active ? (
                                            <span className="inline-flex items-center text-accent-600 dark:text-accent-400">
                                                {active.dir === "asc" ? <IconSortAscending size={14} /> : <IconSortDescending size={14} />}
                                                {sorts.length > 1 && <sup className="text-[9px] font-bold">{level + 1}</sup>}
                                            </span>
                                        ) : (
                                            <IconArrowsSort size={14} className="opacity-30" />
                                        )}
                                    </button>
                                </th>
                            );
                        })}
                    </tr>
                    <tr className="border-b border-slate-200 dark:border-slate-800">
                        {columns.map((c) => (
                            <th key={c.key} className={`px-2 pb-1.5 ${c.className || ""}`}>
                                {c.filter === "select" ? (
                                    <select className={inputBase + " w-full py-1 pr-7 text-xs"} value={filters[c.key] ?? ""} onChange={(e) => setFilters({ ...filters, [c.key]: e.target.value })}>
                                        <option value="">Semua</option>
                                        {(c.options || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                    </select>
                                ) : c.filter ? (
                                    <input
                                        className={inputBase + ` w-full py-1 text-xs ${c.filter === "number" ? "text-right" : ""}`}
                                        placeholder={c.filter === "number" ? ">0" : "saring…"}
                                        title={c.filter === "number" ? "Contoh: >0, 0, >=100000, 50000-100000, kosong" : "Ketik untuk menyaring"}
                                        value={filters[c.key] ?? ""}
                                        onChange={(e) => setFilters({ ...filters, [c.key]: e.target.value })}
                                    />
                                ) : null}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {visible.map((row) => (
                        <tr
                            key={rowKey(row)}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            className={`${onRowClick ? "cursor-pointer" : ""} ${
                                isSelected?.(row)
                                    ? "bg-accent-50 shadow-[inset_3px_0_0_0] shadow-accent-500 dark:bg-accent-500/10"
                                    : "hover:bg-slate-50 dark:hover:bg-slate-800/40"
                            }`}
                        >
                            {columns.map((c) => (
                                <td key={c.key} className={`${td} ${c.align === "right" ? "text-right" : ""} ${c.className || ""}`}>
                                    {c.render ? c.render(row) : c.value(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {visible.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="p-8 text-center text-slate-500">{emptyText}</td>
                        </tr>
                    )}
                </tbody>
                {footer && visible.length > 0 && <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-700 dark:bg-slate-950">{footer(visible)}</tfoot>}
            </table>
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs text-slate-500">
                <span>
                    Menampilkan {visible.length} dari {rows.length} baris · urut:{" "}
                    {sorts.map((s) => `${label(s.key)} ${s.dir === "asc" ? "↑" : "↓"}`).join(", lalu ")}
                    <span className="hidden sm:inline"> · Shift+klik judul kolom untuk menambah urutan</span>
                </span>
                <span className="flex gap-3">
                    {sorts.length > 1 && <button type="button" className="text-primary-600 hover:underline dark:text-primary-300" onClick={() => setSorts([sorts[0]])}>Urut 1 kolom saja</button>}
                    {hasFilters && <button type="button" className="text-primary-600 hover:underline dark:text-primary-300" onClick={() => setFilters({})}>Hapus semua saringan</button>}
                </span>
            </div>
        </div>
    );
}
