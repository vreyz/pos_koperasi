import React, { useEffect } from "react";
import { usePage } from "@inertiajs/react";
import toast from "react-hot-toast";

export const rupiah = (value = 0) =>
    "Rp " + Math.round(Number(value || 0)).toLocaleString("id-ID");

export const percent = (value = 0, digits = 4) =>
    (Number(value || 0) * 100).toLocaleString("id-ID", { maximumFractionDigits: digits }) + "%";

export const MONTHS = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];

export function useFlashToasts() {
    const { flash } = usePage().props;
    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);
}

export function PageHeader({ icon: Icon, title, subtitle, actions }) {
    return (
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    {Icon && <Icon size={28} className="text-primary-500" />}
                    {title}
                </h1>
                {subtitle && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function Panel({ title, actions, children, className = "" }) {
    return (
        <div className={`overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 ${className}`}>
            {(title || actions) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-4 dark:border-slate-800">
                    <h3 className="font-semibold text-slate-800 dark:text-white">{title}</h3>
                    {actions}
                </div>
            )}
            {children}
        </div>
    );
}

export function Stat({ label, value, helper }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
            <p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{value}</p>
            {helper && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{helper}</p>}
        </div>
    );
}

const BADGE = {
    gray: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
    green: "bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400",
    amber: "bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400",
    red: "bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400",
    blue: "bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300",
};

export function Badge({ color = "gray", children }) {
    return <span className={`inline-flex items-center rounded-lg px-2 py-0.5 text-xs font-medium ${BADGE[color]}`}>{children}</span>;
}

export const btn = {
    primary: "inline-flex items-center gap-2 rounded-xl bg-primary-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-600 disabled:opacity-50",
    secondary: "inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800",
    danger: "inline-flex items-center gap-2 rounded-xl bg-danger-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-danger-600 disabled:opacity-50",
};

export const inputBase =
    "rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 dark:focus:ring-primary-900/40";

export const inputClass = "w-full " + inputBase;

export function Field({ label, error, hint, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{label}</span>
            {children}
            {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-danger-600">{error}</span>}
        </label>
    );
}

export const th = "whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400";
export const td = "whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-300";
