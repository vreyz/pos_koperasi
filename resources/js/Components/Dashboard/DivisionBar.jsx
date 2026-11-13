import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Link, usePage } from "@inertiajs/react";
import { IconChevronDown, IconBuildingStore, IconBuildingBank, IconCash, IconPackage, IconPigMoney, IconCalculator } from "@tabler/icons-react";
import hasAnyPermission from "@/Utils/Permission";

const safeRoute = (name, params) => {
    try {
        return route(name, params);
    } catch {
        return null;
    }
};

/** Menu divisi koperasi di bagian atas: setiap divisi membuka halaman kerjanya + catat kejadian. */
function useDivisions() {
    const rec = (division) => ({ title: "Catat kejadian", href: safeRoute("accounting.record", { division }), perm: ["accounting-create"] });
    const jur = (division) => ({ title: "Jurnal divisi", href: safeRoute("accounting.journals", { division }), perm: ["accounting-access"] });
    return [
        {
            key: "toko", title: "Toko & Kantin", icon: IconBuildingStore,
            match: ["/dashboard/transactions", "/dashboard/goods-receivings", "/dashboard/stock-opnames", "/dashboard/products", "/dashboard/consignments"],
            items: [
                { title: "Kasir (POS)", href: safeRoute("transactions.index"), perm: ["transactions-access"] },
                { title: "Riwayat penjualan", href: safeRoute("transactions.history"), perm: ["transactions-access"] },
                { title: "Penerimaan barang", href: safeRoute("goods-receivings.index"), perm: ["goods-receivings-access"] },
                { title: "Stock opname", href: safeRoute("stock-opnames.index"), perm: ["stock-opnames-access"] },
                { title: "Produk", href: safeRoute("products.index"), perm: ["products-access"] },
                { title: "Rekap & bayar penjaga kantin", href: safeRoute("accounting.kantin"), perm: ["accounting-access"] },
                { title: "Konsinyasi (barang titipan)", href: safeRoute("consignments.index"), perm: ["products-access"] },
                rec("toko"), jur("toko"),
            ],
        },
        {
            key: "pengadaan", title: "Pengadaan Kantor", icon: IconBuildingBank, match: [],
            items: [rec("pengadaan"), jur("pengadaan")],
        },
        {
            key: "dana", title: "Pembiayaan Dana", icon: IconCash, match: ["/dashboard/financing/dana", "/dashboard/financing-reconcile"],
            items: [
                { title: "Daftar pembiayaan dana", href: safeRoute("financing.index", "dana"), perm: ["financing-access"] },
                { title: "Pengajuan baru", href: safeRoute("financing.create", "dana"), perm: ["financing-create"] },
                { title: "Cocokkan dengan potongan gaji", href: safeRoute("financing.reconcile"), perm: ["financing-access"] },
                rec("dana"), jur("dana"),
            ],
        },
        {
            key: "barang", title: "Pembiayaan Barang", icon: IconPackage, match: ["/dashboard/financing/barang"],
            items: [
                { title: "Daftar pembiayaan barang", href: safeRoute("financing.index", "barang"), perm: ["financing-access"] },
                { title: "Pengajuan baru", href: safeRoute("financing.create", "barang"), perm: ["financing-create"] },
                rec("barang"), jur("barang"),
            ],
        },
        {
            key: "simpanan", title: "Simpanan & Potongan", icon: IconPigMoney, match: ["/dashboard/savings", "/dashboard/deductions"],
            items: [
                { title: "Simpanan anggota", href: safeRoute("savings.index"), perm: ["savings-access"] },
                { title: "Angsuran & potongan gaji", href: safeRoute("deductions.index"), perm: ["deductions-access"] },
                rec("simpanan"), jur("simpanan"),
            ],
        },
        {
            key: "akuntansi", title: "Akuntansi", icon: IconCalculator, match: ["/dashboard/accounting"],
            items: [
                { title: "Jurnal", href: safeRoute("accounting.journals"), perm: ["accounting-access"] },
                { title: "Catat kejadian", href: safeRoute("accounting.record"), perm: ["accounting-create"] },
                { title: "Jurnal umum", href: safeRoute("accounting.manual"), perm: ["accounting-manage"] },
                { title: "Buku besar", href: safeRoute("accounting.ledger"), perm: ["accounting-access"] },
                { title: "Neraca saldo", href: safeRoute("accounting.trial-balance"), perm: ["accounting-access"] },
                { title: "Laporan keuangan", href: safeRoute("accounting.reports"), perm: ["accounting-access"] },
                { title: "Rekap kantin", href: safeRoute("accounting.kantin"), perm: ["accounting-access"] },
                { title: "COA & pengaturan", href: safeRoute("accounting.accounts"), perm: ["accounting-access"] },
                { title: "Saldo awal", href: safeRoute("accounting.opening"), perm: ["accounting-access"] },
            ],
        },
    ]
        .map((d) => ({ ...d, items: d.items.filter((i) => i.href && hasAnyPermission(i.perm)) }))
        .filter((d) => d.items.length > 0);
}

export default function DivisionBar() {
    const { url } = usePage();
    const divisions = useDivisions();
    const [open, setOpen] = useState(null);
    const [pos, setPos] = useState({ left: 0, top: 0 });
    const menuRef = useRef(null);

    useEffect(() => setOpen(null), [url]);
    useEffect(() => {
        if (!open) return;
        const close = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target) && !e.target.closest?.("[data-division-btn]")) setOpen(null);
        };
        const esc = (e) => e.key === "Escape" && setOpen(null);
        document.addEventListener("mousedown", close);
        document.addEventListener("keydown", esc);
        window.addEventListener("resize", () => setOpen(null), { once: true });
        return () => { document.removeEventListener("mousedown", close); document.removeEventListener("keydown", esc); };
    }, [open]);

    if (divisions.length === 0) return null;
    const params = new URLSearchParams(url.split("?")[1] || "");
    const isActive = (d) => d.match.some((m) => url.startsWith(m)) || (url.startsWith("/dashboard/accounting") && params.get("division") === d.key);
    const current = divisions.find((d) => d.key === open);

    return (
        <nav className="border-b border-slate-200 bg-white/90 dark:border-slate-800 dark:bg-slate-900/90 print:hidden">
            <div className="no-scrollbar flex gap-1 overflow-x-auto px-3 md:px-5">
                {divisions.map((d) => {
                    const Icon = d.icon;
                    const active = isActive(d) && !(d.key === "akuntansi" && params.get("division"));
                    return (
                        <button
                            key={d.key}
                            type="button"
                            data-division-btn
                            onClick={(e) => {
                                const r = e.currentTarget.getBoundingClientRect();
                                setPos({ left: Math.min(r.left, window.innerWidth - 240), top: r.bottom + 4 });
                                setOpen(open === d.key ? null : d.key);
                            }}
                            className={`flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                                active || open === d.key
                                    ? "border-accent-500 text-slate-900 dark:text-white"
                                    : "border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                            }`}
                        >
                            <Icon size={16} strokeWidth={1.75} />
                            {d.title}
                            <IconChevronDown size={14} className={`transition-transform ${open === d.key ? "rotate-180" : ""}`} />
                        </button>
                    );
                })}
            </div>
            {current &&
                createPortal(
                    <div
                        ref={menuRef}
                        style={{ left: pos.left, top: pos.top }}
                        className="fixed z-50 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                    >
                        {current.items.map((i) => (
                            <Link key={i.title} href={i.href} className="block px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                                {i.title}
                            </Link>
                        ))}
                    </div>,
                    document.body,
                )}
        </nav>
    );
}
