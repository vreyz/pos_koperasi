import React from "react";
import { usePage } from "@inertiajs/react";
import { IconLayoutGrid } from "@tabler/icons-react";
import LinkItem from "@/Components/Dashboard/LinkItem";
import LinkItemDropdown from "@/Components/Dashboard/LinkItemDropdown";
import Menu from "@/Utils/Menu";

export default function Sidebar({ sidebarOpen }) {
    const { auth, storeProfile, appVersion } = usePage().props;
    const menuNavigation = Menu();

    const storeName = storeProfile?.name || "KASIR";
    const storeLogo = storeProfile?.logo || null;
    // Inisial seperti lambang di halaman login: "Koperasi Kaban Jawara" → "KJ".
    const words = storeName.replace(/^koperasi\s+/i, "").split(/\s+/).filter(Boolean);
    const storeInitial =
        ((words[0]?.[0] ?? "") + (words[1]?.[0] ?? "")).toUpperCase() ||
        auth?.user?.name?.charAt(0)?.toUpperCase() ||
        "K";
    const emblem = "shrink-0 rounded-full bg-accent-500 ring-2 ring-accent-500 ring-offset-2 ring-offset-primary-950 flex items-center justify-center";

    return (
        <div
            className={`
                ${sidebarOpen ? "translate-x-0 w-[260px]" : "-translate-x-full w-[260px]"}
                md:translate-x-0 ${sidebarOpen ? "md:w-[260px]" : "md:w-[80px]"}
                fixed md:relative inset-y-0 left-0 z-40
                flex h-screen flex-col overflow-hidden md:sticky md:top-0 md:self-stretch md:shrink-0
                border-r border-white/5
                kawung-navy text-slate-300
                transition-all duration-300 ease-in-out
            `}
        >
            {/* Logo */}
            <div className="flex items-center justify-center h-14 border-b border-white/10">
                {sidebarOpen ? (
                    <div className="flex w-full min-w-0 items-center gap-3 px-4">
                        {storeLogo ? (
                            <img
                                src={storeLogo}
                                alt={storeName}
                                className="w-9 h-9 shrink-0 rounded-full object-cover ring-2 ring-accent-500"
                            />
                        ) : (
                            <div className={`w-9 h-9 ${emblem}`}>
                                <span className="text-primary-950 font-extrabold text-xs tracking-tight">
                                    {storeInitial}
                                </span>
                            </div>
                        )}
                        {/* Nama panjang (mis. "Koperasi Kaban Jawara") turun ke 2 baris, tidak terpotong. */}
                        <span title={storeName} className={`min-w-0 font-bold leading-tight text-white line-clamp-2 break-words ${storeName.length > 14 ? "text-sm" : "text-lg"}`}>
                            {storeName}
                        </span>
                    </div>
                ) : (
                    storeLogo ? (
                        <img
                            src={storeLogo}
                            alt={storeName}
                            className="w-8 h-8 rounded-full object-cover ring-2 ring-accent-500"
                        />
                    ) : (
                        <div className={`w-8 h-8 ${emblem}`}>
                            <span className="text-primary-950 font-extrabold text-[11px]">
                                {storeInitial}
                            </span>
                        </div>
                    )
                )}
            </div>

            {/* Navigation */}
            <nav className="dashboard-scrollbar min-h-0 flex-1 overflow-y-auto py-2">
                {menuNavigation.map((section, index) => {
                    const hasPermission = section.details.some(
                        (detail) => detail.permissions === true
                    );
                    if (!hasPermission) return null;

                    return (
                        <div key={index} className="mb-1">
                            {/* Section Title */}
                            {sidebarOpen && (
                                <div className="px-4 pt-2 pb-1">
                                    <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-accent-500/90">
                                        {section.title}
                                    </span>
                                </div>
                            )}

                            {/* Menu Items */}
                            <div
                                className={
                                    sidebarOpen
                                        ? ""
                                        : "flex flex-col items-center"
                                }
                            >
                                {section.details.map((detail, idx) => {
                                    if (!detail.permissions) return null;

                                    if (detail.hasOwnProperty("subdetails")) {
                                        return (
                                            <LinkItemDropdown
                                                key={idx}
                                                title={detail.title}
                                                icon={detail.icon}
                                                data={detail.subdetails}
                                                access={detail.permissions}
                                                sidebarOpen={sidebarOpen}
                                            />
                                        );
                                    }

                                    return (
                                        <LinkItem
                                            key={idx}
                                            title={detail.title}
                                            icon={detail.icon}
                                            href={detail.href}
                                            access={detail.permissions}
                                            sidebarOpen={sidebarOpen}
                                        />
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </nav>

            {/* Version/Footer */}
            {sidebarOpen && (
                <div className="px-4 py-2 border-t border-white/10">
                    <p className="text-[10px] text-slate-500 text-center">
                        Point of Sales {appVersion}
                    </p>
                </div>
            )}
        </div>
    );
}
