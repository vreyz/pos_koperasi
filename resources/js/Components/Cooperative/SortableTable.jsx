import React, { useMemo, useState } from "react";
import { IconArrowsSort, IconSortAscending, IconSortDescending } from "@tabler/icons-react";
import { inputBase, td, th } from "@/Components/Cooperative/ui";

/**
 * Tabel dengan urut (klik judul kolom) dan saring per kolom, semuanya di browser.
 *
 * columns: [{ key, label, value: (row) => string|number, render?: (row) => node,
 *             filter?: "text" | "number" | "select", options?: [{value,label}], align?: "right", className? }]
 *
 * Saringan angka menerima: 0, >0, >=100000, <50000, =100000, 100000-500000, kosong.
 */
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

export default function SortableTable({ columns, rows, rowKey, initialSort, initialFilters = {}, emptyText = "Tidak ada data.", footer }) {
    const [sort, setSort] = useState(initialSort || { key: columns[0].key, dir: "asc" });
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
        const col = columns.find((c) => c.key === sort.key);
        if (!col) return filtered;
        const dir = sort.dir === "asc" ? 1 : -1;
        return [...filtered].sort((a, b) => {
            const x = col.value(a);
            const y = col.value(b);
            if (typeof x === "number" || typeof y === "number") return ((Number(x) || 0) - (Number(y) || 0)) * dir;
            return String(x ?? "").localeCompare(String(y ?? ""), "id", { sensitivity: "base", numeric: true }) * dir;
        });
    }, [rows, columns, filters, sort]);

    const toggleSort = (key) =>
        setSort((s) => (s.key === key ? { key, dir: s.dir === "asc" ? "desc" : "asc" } : { key, dir: "asc" }));
    const hasFilters = Object.values(filters).some((v) => v !== "" && v != null);

    return (
        <div className="overflow-x-auto">
            <table className="w-full">
                <thead className="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        {columns.map((c) => (
                            <th key={c.key} className={`${th} ${c.align === "right" ? "text-right" : ""} ${c.className || ""}`}>
                                <button type="button" onClick={() => toggleSort(c.key)} className={`inline-flex items-center gap-1 uppercase hover:text-slate-800 dark:hover:text-white ${c.align === "right" ? "flex-row-reverse" : ""}`}>
                                    {c.label}
                                    {sort.key === c.key ? (
                                        sort.dir === "asc" ? <IconSortAscending size={14} /> : <IconSortDescending size={14} />
                                    ) : (
                                        <IconArrowsSort size={14} className="opacity-30" />
                                    )}
                                </button>
                            </th>
                        ))}
                    </tr>
                    <tr className="border-b border-slate-200 dark:border-slate-800">
                        {columns.map((c) => (
                            <th key={c.key} className={`px-2 pb-2 ${c.className || ""}`}>
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
                        <tr key={rowKey(row)} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            {columns.map((c) => (
                                <td key={c.key} className={`${td} ${c.align === "right" ? "text-right" : ""} ${c.className || ""}`}>
                                    {c.render ? c.render(row) : c.value(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {visible.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="p-10 text-center text-slate-500">{emptyText}</td>
                        </tr>
                    )}
                </tbody>
                {footer && visible.length > 0 && <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-700 dark:bg-slate-950">{footer(visible)}</tfoot>}
            </table>
            <div className="flex items-center justify-between px-4 py-3 text-xs text-slate-500">
                <span>Menampilkan {visible.length} dari {rows.length} baris</span>
                {hasFilters && <button type="button" className="text-primary-600 hover:underline" onClick={() => setFilters({})}>Hapus semua saringan</button>}
            </div>
        </div>
    );
}
