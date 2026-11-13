import React, { useMemo, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
    IconChevronDown,
    IconChevronUp,
    IconCornerDownRight,
} from "@tabler/icons-react";
import { isSuperAdmin } from "@/Utils/authorization";

export default function LinkItemDropdown({ icon, title, data, access, sidebarOpen, ...props }) {
    const { url } = usePage();
    const { auth } = usePage().props;
    const superAdmin = isSuperAdmin(auth);

    const visibleItems = useMemo(
        () => data.filter((item) => superAdmin || item.permissions === true),
        [data, superAdmin]
    );

    // Auto-open dropdown when current URL is inside this submenu
    const [isOpen, setIsOpen] = useState(() =>
        visibleItems.some((item) => url === item.href)
    );

    const canRenderParent = superAdmin || access === true || visibleItems.length > 0;

    if (!canRenderParent || visibleItems.length === 0) {
        return null;
    }

    // Sidebar selalu biru tua: teks terang, sorotan putih transparan, penanda emas.
    const buttonClass = sidebarOpen
        ? "min-w-full flex items-center font-medium gap-x-3 px-4 py-2 capitalize hover:cursor-pointer text-sm justify-between text-slate-300 hover:bg-white/5 hover:text-white"
        : "min-w-full flex justify-center py-2.5 hover:cursor-pointer text-slate-400 hover:bg-white/5 hover:text-white";

    return (
        <>
            <button className={buttonClass} onClick={() => setIsOpen(!isOpen)}>
                {sidebarOpen ? (
                    <>
                        <div className="flex items-center gap-x-3.5">
                            {icon}
                            {title}
                        </div>
                        {isOpen ? (
                            <IconChevronUp size={18} strokeWidth={1.5} />
                        ) : (
                            <IconChevronDown size={18} strokeWidth={1.5} />
                        )}
                    </>
                ) : !isOpen ? (
                    icon
                ) : (
                    <IconChevronDown size={20} strokeWidth={1.5} />
                )}
            </button>

            {isOpen &&
                visibleItems.map((item, index) => (
                    <Link
                        key={index}
                        href={item.href}
                        onClick={() => {
                            // Collapse submenu after navigating on mobile
                            if (window.innerWidth < 768) {
                                setIsOpen(false);
                            }
                        }}
                        className={`${
                            url === item.href
                                ? "border-l-[3px] border-accent-500 bg-white/[0.07] text-white"
                                : "border-l-[3px] border-transparent text-slate-400"
                        } ${
                            sidebarOpen
                                ? "min-w-full flex items-center font-medium gap-x-3 pl-6 pr-4 py-1.5 capitalize hover:cursor-pointer text-sm line-clamp-1 hover:bg-white/5 hover:text-white"
                                : "min-w-full flex justify-center py-2.5 hover:cursor-pointer hover:bg-white/5 hover:text-white"
                        }`}
                        {...props}
                    >
                        {sidebarOpen ? (
                            <>
                                <IconCornerDownRight
                                    size={18}
                                    strokeWidth={1.5}
                                />{" "}
                                {item.title}
                            </>
                        ) : (
                            item.icon
                        )}
                    </Link>
                ))}
        </>
    );
}
