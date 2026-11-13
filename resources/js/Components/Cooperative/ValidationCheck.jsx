import React, { useState } from "react";
import { IconAlertCircle, IconAlertTriangle, IconCircleCheck, IconInfoCircle } from "@tabler/icons-react";

const LEVELS = {
    error: { icon: IconAlertCircle, box: "border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-900/60 dark:bg-danger-950/40 dark:text-danger-200" },
    warning: { icon: IconAlertTriangle, box: "border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/40 dark:text-warning-200" },
    info: { icon: IconInfoCircle, box: "border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" },
    ok: { icon: IconCircleCheck, box: "border-success-200 bg-success-50 text-success-800 dark:border-success-900/60 dark:bg-success-950/40 dark:text-success-200" },
};

/**
 * Satu hasil validasi impor ({level, title, details, names?}). Bila ada `names` dan `picker`,
 * rincian diganti pilihan pegawai per nama (picker(key) → elemen).
 */
export default function ValidationCheck({ check, picker }) {
    const withPicker = Boolean(picker && check.names?.length);
    const [open, setOpen] = useState(check.level === "error" || withPicker);
    const level = LEVELS[check.level] || LEVELS.info;
    const Icon = level.icon;
    return (
        <div className={`rounded-lg border px-3 py-2 text-sm ${level.box}`}>
            <div className="flex items-start gap-2">
                <Icon size={18} className="mt-0.5 shrink-0" />
                <div className="flex-1">
                    {check.title}
                    {check.details.length > 0 && (
                        <button type="button" onClick={() => setOpen(!open)} className="ml-2 text-xs underline opacity-80">
                            {open ? "sembunyikan" : `lihat rincian (${check.details.length})`}
                        </button>
                    )}
                    {open && withPicker && <div className="mt-2 space-y-1.5">{check.names.map((key) => picker(key))}</div>}
                    {open && !withPicker && check.details.length > 0 && (
                        <ul className="mt-1 max-h-60 list-disc overflow-y-auto pl-5 text-xs opacity-90">
                            {check.details.map((d, i) => <li key={i}>{d}</li>)}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
