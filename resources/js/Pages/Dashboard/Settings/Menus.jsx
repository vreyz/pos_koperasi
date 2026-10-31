import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconLayoutSidebar } from "@tabler/icons-react";
import Menu, { menuPath } from "@/Utils/Menu";
import { PageHeader, Panel, btn, useFlashToasts } from "@/Components/Cooperative/ui";

const LOCKED = ["/dashboard", "/dashboard/settings/menus"];

export default function Menus() {
    useFlashToasts();
    const { hiddenMenus = [] } = usePage().props;
    const sections = Menu({ includeHidden: true });
    const [hidden, setHidden] = useState(hiddenMenus);
    const [saving, setSaving] = useState(false);

    const toggle = (path) => setHidden((h) => (h.includes(path) ? h.filter((p) => p !== path) : [...h, path]));
    const items = (detail) => (detail.subdetails ? detail.subdetails : [detail]);
    const setSection = (section, show) => {
        const paths = section.details.flatMap(items).map((d) => menuPath(d.href)).filter((p) => !LOCKED.includes(p));
        setHidden((h) => (show ? h.filter((p) => !paths.includes(p)) : [...new Set([...h, ...paths])]));
    };

    const save = () => {
        setSaving(true);
        router.put(route("settings.menus.update"), { hidden }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return (
        <>
            <Head title="Tampilan Menu" />
            <PageHeader
                icon={IconLayoutSidebar}
                title="Tampilan Menu"
                subtitle="Hilangkan centang pada menu yang tidak dipakai koperasi. Menu hanya disembunyikan dari sidebar untuk semua pengguna; data dan hak akses tidak berubah."
                actions={<button onClick={save} disabled={saving} className={btn.primary}>{saving ? "Menyimpan..." : `Simpan (${hidden.length} disembunyikan)`}</button>}
            />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {sections.map((section) => (
                    <Panel
                        key={section.title}
                        title={section.title}
                        actions={
                            <div className="flex gap-2 text-xs">
                                <button className="text-primary-600 hover:underline" onClick={() => setSection(section, true)}>Tampilkan semua</button>
                                <button className="text-slate-500 hover:underline" onClick={() => setSection(section, false)}>Sembunyikan semua</button>
                            </div>
                        }
                    >
                        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                            {section.details.flatMap(items).map((d) => {
                                const path = menuPath(d.href);
                                const locked = LOCKED.includes(path);
                                return (
                                    <li key={path}>
                                        <label className={`flex items-center gap-3 px-4 py-2 text-sm ${locked ? "opacity-60" : "cursor-pointer"}`}>
                                            <input type="checkbox" disabled={locked} checked={!hidden.includes(path)} onChange={() => toggle(path)} />
                                            {d.icon}
                                            <span className="text-slate-700 dark:text-slate-300">{d.title}</span>
                                            {locked && <span className="ml-auto text-xs text-slate-400">wajib</span>}
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>
                ))}
            </div>
        </>
    );
}

Menus.layout = (page) => <DashboardLayout children={page} />;
