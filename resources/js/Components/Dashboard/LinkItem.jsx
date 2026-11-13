import React from "react";
import { Link, usePage } from "@inertiajs/react";
import { isSuperAdmin } from "@/Utils/authorization";

export default function LinkItem({
    href,
    icon,
    access,
    title,
    sidebarOpen,
    ...props
}) {
    const { url } = usePage();
    const { auth } = usePage().props;

    const isActive = url.startsWith(href);
    const canAccess = isSuperAdmin(auth) || access === true;

    if (!canAccess) return null;

    // Sidebar selalu biru tua (seperti halaman login): menu aktif ditandai garis & ikon emas.
    const baseClasses = `
        flex items-center gap-3
        transition-all duration-200
        text-slate-300
    `;

    const activeClasses = isActive
        ? "bg-white/[0.07] text-white border-l-[3px] border-accent-500"
        : "hover:bg-white/5 hover:text-white border-l-[3px] border-transparent";

    if (sidebarOpen) {
        return (
            <Link
                href={href}
                className={`${baseClasses} ${activeClasses} px-4 py-2 text-sm font-medium`}
                {...props}
            >
                <span
                    className={
                        isActive ? "text-accent-400" : "text-slate-400"
                    }
                >
                    {icon}
                </span>
                <span className="truncate">{title}</span>
            </Link>
        );
    }

    // Collapsed sidebar
    return (
        <Link
            href={href}
            className={`
                w-full flex justify-center py-2.5
                transition-all duration-200
                ${
                    isActive
                        ? "text-accent-400 bg-white/[0.07]"
                        : "text-slate-400 hover:text-white hover:bg-white/5"
                }
            `}
            title={title}
            {...props}
        >
            {icon}
        </Link>
    );
}
