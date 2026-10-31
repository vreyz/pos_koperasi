import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { IconChevronDown, IconSearch, IconX } from "@tabler/icons-react";
import { inputBase, inputClass } from "@/Components/Cooperative/ui";

/** "1.234.567,5" ↔ 1234567.5 (format Indonesia: titik ribuan, koma desimal). */
export const formatNumberId = (value, decimals = 0) => {
    if (value === "" || value === null || value === undefined || Number.isNaN(Number(value))) return "";
    return Number(value).toLocaleString("id-ID", { minimumFractionDigits: 0, maximumFractionDigits: decimals });
};
export const parseNumberId = (text) => {
    const clean = String(text).replace(/[^0-9,-]/g, "").replace(",", ".");
    if (clean === "" || clean === "-") return "";
    const n = Number(clean);
    return Number.isNaN(n) ? "" : n;
};

/**
 * Isian angka gaya Indonesia. Uang: prefix "Rp", tanpa desimal. Persen: decimals=2, suffix "%".
 * onChange menerima angka (atau "" bila kosong).
 */
export function NumberInput({ value, onChange, decimals = 0, prefix, suffix, className = "", placeholder, ...rest }) {
    const [text, setText] = useState(formatNumberId(value, decimals));
    const focused = useRef(false);

    useEffect(() => {
        if (!focused.current) setText(formatNumberId(value, decimals));
    }, [value, decimals]);

    const handle = (e) => {
        let raw = e.target.value.replace(/[^0-9,-]/g, "");
        if (decimals === 0) raw = raw.replace(/,/g, "");
        const [intPart, ...fraction] = raw.split(",");
        const intNum = intPart.replace(/^(-?)0+(?=\d)/, "$1");
        const grouped = intNum === "" || intNum === "-" ? intNum : Number(intNum).toLocaleString("id-ID");
        const next = fraction.length ? `${grouped},${fraction.join("").slice(0, decimals)}` : grouped;
        setText(next);
        onChange(parseNumberId(next));
    };

    return (
        <div className={`relative ${className}`}>
            {prefix && <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500">{prefix}</span>}
            <input
                {...rest}
                inputMode={decimals ? "decimal" : "numeric"}
                className={`${inputClass} ${prefix ? "pl-10" : ""} ${suffix ? "pr-8" : ""} text-right`}
                value={text}
                placeholder={placeholder}
                onFocus={() => (focused.current = true)}
                onBlur={() => { focused.current = false; setText(formatNumberId(value, decimals)); }}
                onChange={handle}
            />
            {suffix && <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">{suffix}</span>}
        </div>
    );
}

export const MoneyInput = (props) => <NumberInput prefix="Rp" decimals={0} {...props} />;
export const PercentInput = (props) => <NumberInput suffix="%" decimals={2} {...props} />;

/** Opsi pegawai standar untuk SearchSelect. */
export const employeeOptions = (employees) =>
    employees.map((e) => ({
        value: e.id,
        label: e.name,
        sub: [e.nip, e.employee_type === "PPNPN" ? "PPNPN" : null, e.employment_status && e.employment_status !== "AKTIF" ? e.employment_status : null].filter(Boolean).join(" · "),
    }));

const normalize = (s) => String(s ?? "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

/**
 * Pilihan dengan pencarian untuk daftar panjang (pegawai, barang, dll).
 * options: [{ value, label, sub? }]; extra: opsi khusus di atas daftar (mis. "lewati", "buat baru").
 */
export function SearchSelect({ options, value, onChange, placeholder = "Cari…", extra = [], className = "", required, disabled, clearable = false }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState("");
    const [active, setActive] = useState(0);
    const box = useRef(null);
    const list = useRef(null);
    const [pos, setPos] = useState(null);
    const all = useMemo(() => [...extra, ...options], [extra, options]);
    const selected = all.find((o) => String(o.value) === String(value ?? ""));

    const results = useMemo(() => {
        const q = normalize(query).trim();
        const words = q.split(/\s+/).filter(Boolean);
        const match = (o) => words.every((w) => normalize(`${o.label} ${o.sub || ""}`).includes(w));
        return [...extra.filter((o) => !q || match(o)), ...options.filter(match).slice(0, 60)];
    }, [query, options, extra]);

    useEffect(() => {
        const close = (e) => box.current && !box.current.contains(e.target) && !list.current?.contains(e.target) && setOpen(false);
        document.addEventListener("mousedown", close);
        return () => document.removeEventListener("mousedown", close);
    }, []);

    // Di dalam modal, area di luar modal tidak bisa diklik → daftar tetap di tempat (absolute).
    const inDialog = open && !!box.current?.closest('[role="dialog"]');
    const portal = (node) => (inDialog ? node : createPortal(node, document.body));

    // Daftar digambar di <body> (posisi fixed) supaya tidak terpotong panel/tabel; buka ke atas bila ruang bawah sempit.
    useLayoutEffect(() => {
        if (!open || box.current?.closest('[role="dialog"]')) return;
        const place = () => {
            const r = box.current?.getBoundingClientRect();
            if (!r) return;
            const below = window.innerHeight - r.bottom;
            const up = below < 240 && r.top > below;
            setPos({ left: r.left, width: r.width, top: up ? undefined : r.bottom + 4, bottom: up ? window.innerHeight - r.top + 4 : undefined, maxHeight: Math.max(160, Math.min(288, (up ? r.top : below) - 12)) });
        };
        place();
        window.addEventListener("scroll", place, true);
        window.addEventListener("resize", place);
        return () => {
            window.removeEventListener("scroll", place, true);
            window.removeEventListener("resize", place);
        };
    }, [open]);

    const choose = (o) => {
        onChange(o ? o.value : "");
        setOpen(false);
        setQuery("");
    };
    const onKey = (e) => {
        if (e.key === "ArrowDown") { e.preventDefault(); setOpen(true); setActive((a) => Math.min(a + 1, results.length - 1)); }
        else if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
        else if (e.key === "Enter" && open) { e.preventDefault(); results[active] && choose(results[active]); }
        else if (e.key === "Escape") setOpen(false);
    };

    return (
        <div ref={box} className={`relative ${className}`}>
            <div className="relative">
                <IconSearch size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    className={`${inputBase} w-full pl-9 pr-14`}
                    disabled={disabled}
                    placeholder={selected ? "" : placeholder}
                    value={open ? query : selected ? selected.label + (selected.sub ? ` · ${selected.sub}` : "") : ""}
                    onFocus={() => { setOpen(true); setActive(0); }}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(0); }}
                    onKeyDown={onKey}
                />
                {/* menjaga validasi "required" bawaan form */}
                {required && <input tabIndex={-1} className="absolute h-0 w-0 opacity-0" value={value ?? ""} onChange={() => {}} required />}
                <div className="absolute inset-y-0 right-2 flex items-center gap-1 text-slate-400">
                    {clearable && value !== "" && value != null && !disabled && <button type="button" onClick={() => choose(null)} title="Kosongkan"><IconX size={14} /></button>}
                    <IconChevronDown size={16} />
                </div>
            </div>
            {open && !disabled && (inDialog || pos) && portal(
                <ul ref={list} style={inDialog ? undefined : { position: "fixed", ...pos }} className={`${inDialog ? "absolute mt-1 max-h-72 w-full" : ""} z-[9999] min-w-[16rem] overflow-auto rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900`}>
                    {results.map((o, i) => (
                        <li key={`${o.value}`}>
                            <button
                                type="button"
                                onMouseEnter={() => setActive(i)}
                                onClick={() => choose(o)}
                                className={`flex w-full flex-col px-3 py-1.5 text-left ${i === active ? "bg-primary-50 dark:bg-primary-900/30" : ""} ${String(o.value) === String(value ?? "") ? "font-semibold" : ""}`}
                            >
                                <span className="text-slate-800 dark:text-slate-100">{o.label}</span>
                                {o.sub && <span className="text-xs text-slate-500">{o.sub}</span>}
                            </button>
                        </li>
                    ))}
                    {results.length === 0 && <li className="px-3 py-2 text-slate-500">Tidak ditemukan</li>}
                    {options.length > 60 && results.length >= 60 && <li className="px-3 py-1 text-xs text-slate-400">Ketik untuk mempersempit…</li>}
                </ul>,
            )}
        </div>
    );
}
