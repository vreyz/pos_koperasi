import React from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconReportMoney, IconPrinter } from "@tabler/icons-react";
import { Badge, PageHeader, Panel, btn, useFlashToasts } from "@/Components/Cooperative/ui";
import { AccountingTabs, PeriodFilter, idr, fmtDate } from "@/Components/Accounting/shared";

const TABS = [["neraca", "Neraca"], ["phu", "PHU per Divisi"], ["ekuitas", "Perubahan Ekuitas"], ["kas", "Arus Kas"]];
const cell = "min-w-[14rem] px-3 py-1 text-sm";
const num = "px-3 py-1 text-right font-mono text-sm";

function Check({ value, label }) {
    return <Badge color={value === 0 ? "green" : "red"}>{value === 0 ? `${label}: cocok` : `${label}: selisih ${idr(value)}`}</Badge>;
}

function BalanceSheet({ balance, prior, filters }) {
    const side = (key, title) => {
        const priorMap = Object.fromEntries(prior.sections[key].map((r) => [r.line, r.amount]));
        return (
            <>
                <tr className="bg-slate-50 dark:bg-slate-800/40"><td colSpan={3} className="px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">{title}</td></tr>
                {balance.sections[key].map((r) => (
                    (r.amount !== 0 || priorMap[r.line]) ? (
                        <tr key={r.line}>
                            <td className={`${cell} pl-6`}>{r.line}{r.line === "Dana Cadangan Tahun Berjalan" ? " (termasuk SHU berjalan)" : ""}</td>
                            <td className={num}>{idr(r.amount)}</td>
                            <td className={`${num} text-slate-500`}>{idr(priorMap[r.line])}</td>
                        </tr>
                    ) : null
                ))}
                <tr className="font-semibold">
                    <td className={cell}>Jumlah {title.toLowerCase()}</td>
                    <td className={num}>{idr(balance.totals[key])}</td>
                    <td className={`${num} text-slate-500`}>{idr(prior.totals[key])}</td>
                </tr>
            </>
        );
    };
    return (
        <table className="w-full">
            <thead>
                <tr className="text-xs text-slate-500">
                    <th className="px-3 py-1 text-left font-medium">Pos</th>
                    <th className="w-44 px-3 py-1 text-right font-medium">{fmtDate(filters.to)}</th>
                    <th className="w-44 px-3 py-1 text-right font-medium">Awal periode</th>
                </tr>
            </thead>
            <tbody>
                {side("aset", "Aset")}
                {side("kewajiban", "Kewajiban")}
                {side("ekuitas", "Ekuitas")}
                <tr className="border-t-2 border-slate-300 font-bold dark:border-slate-600">
                    <td className={cell}>Jumlah kewajiban dan ekuitas</td>
                    <td className={num}>{idr(balance.totals.kewajiban + balance.totals.ekuitas)}</td>
                    <td className={`${num} text-slate-500`}>{idr(prior.totals.kewajiban + prior.totals.ekuitas)}</td>
                </tr>
            </tbody>
        </table>
    );
}

function IncomeStatement({ income }) {
    const divs = Object.entries(income.divisions);
    const row = (label, values, bold = false, key) => (
        <tr key={key || label} className={bold ? "border-t border-slate-200 font-semibold dark:border-slate-700" : ""}>
            <td className={`${cell} ${bold ? "" : "pl-6"}`}>{label}</td>
            {divs.map(([d]) => <td key={d} className={num}>{idr(values[d])}</td>)}
            <td className={`${num} font-semibold`}>{idr(values.total)}</td>
        </tr>
    );
    const section = (name) => income.rows.filter((r) => r.section === name && Object.values(r.values).some((v) => v !== 0));
    const head = (label) => <tr className="bg-slate-50 dark:bg-slate-800/40"><td colSpan={divs.length + 2} className="px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">{label}</td></tr>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[60rem]">
                <thead>
                    <tr className="text-xs text-slate-500">
                        <th className="px-3 py-1 text-left font-medium">Pos</th>
                        {divs.map(([d, label]) => <th key={d} className="w-36 px-3 py-1 text-right font-medium">{label}</th>)}
                        <th className="w-36 px-3 py-1 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    {head("Hasil usaha per divisi")}
                    {section("usaha").map((r) => row(r.line, r.values))}
                    {row("Hasil usaha kotor", income.gross, true)}
                    {section("lain").length > 0 && head("Pendapatan lain-lain")}
                    {section("lain").map((r) => row(r.line, r.values))}
                    {head("Beban usaha (umum koperasi, tidak dialokasikan)")}
                    {section("beban").map((r) => row(r.line, r.values))}
                    {row("SHU sebelum pajak", income.before_tax, true)}
                    {section("pajak").map((r) => row(r.line, r.values))}
                    <tr className="border-t-2 border-slate-300 font-bold dark:border-slate-600">
                        <td className={cell}>SISA HASIL USAHA</td>
                        {divs.map(([d]) => <td key={d} className={num}>{idr(income.shu[d])}</td>)}
                        <td className={num}>{idr(income.shu.total)}</td>
                    </tr>
                </tbody>
            </table>
            <p className="px-3 py-2 text-xs text-slate-500">Pendapatan positif, beban dalam kurung. Beban usaha tidak dibagi ke divisi.</p>
        </div>
    );
}

function Equity({ equity }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[64rem]">
                <thead>
                    <tr className="text-xs text-slate-500">
                        <th className="px-3 py-1 text-left font-medium">Uraian</th>
                        {equity.columns.map((c) => <th key={c} className="w-32 px-2 py-1 text-right font-medium">{c}</th>)}
                        <th className="w-36 px-3 py-1 text-right font-medium">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    {equity.rows.map((r, i) => (
                        <tr key={r.label} className={i === 0 || i === equity.rows.length - 1 ? "border-t border-slate-200 font-semibold dark:border-slate-700" : ""}>
                            <td className={cell}>{r.label}</td>
                            {equity.columns.map((c) => <td key={c} className="px-2 py-1 text-right font-mono text-sm">{idr(r.values[c])}</td>)}
                            <td className={num}>{idr(r.total)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

const GROUP_LABEL = { operasi: "Aktivitas operasi", investasi: "Aktivitas investasi", pendanaan: "Aktivitas pendanaan" };

function CashFlow({ cash, filters }) {
    return (
        <table className="w-full">
            <tbody>
                {cash.groups.map((g) => (
                    <React.Fragment key={g.group}>
                        <tr className="bg-slate-50 dark:bg-slate-800/40"><td colSpan={2} className="px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">Arus kas dari {GROUP_LABEL[g.group].toLowerCase()}</td></tr>
                        {g.rows.length === 0 && <tr><td className={`${cell} pl-6 text-slate-500`}>Tidak ada</td><td /></tr>}
                        {g.rows.map((r) => <tr key={r.label}><td className={`${cell} pl-6`}>{r.label}</td><td className={`${num} w-44`}>{idr(r.amount)}</td></tr>)}
                        <tr className="font-semibold"><td className={cell}>Arus kas bersih {GROUP_LABEL[g.group].toLowerCase()}</td><td className={num}>{idr(g.subtotal)}</td></tr>
                    </React.Fragment>
                ))}
                <tr className="border-t-2 border-slate-300 font-bold dark:border-slate-600"><td className={cell}>Kenaikan (penurunan) kas</td><td className={num}>{idr(cash.change)}</td></tr>
                <tr><td className={cell}>Kas dan setara kas {fmtDate(filters.from)}</td><td className={num}>{idr(cash.opening)}</td></tr>
                <tr className="font-bold"><td className={cell}>Kas dan setara kas {fmtDate(filters.to)}</td><td className={num}>{idr(cash.closing)}</td></tr>
            </tbody>
        </table>
    );
}

export default function Reports({ filters, balance, balancePrior, income, equity, cash, store }) {
    useFlashToasts();
    const tab = filters.tab || "neraca";
    const setTab = (t) => router.get(route("accounting.reports"), { ...filters, tab: t }, { preserveState: true, preserveScroll: true });
    const title = { neraca: `Neraca per ${fmtDate(filters.to)}`, phu: "Perhitungan Hasil Usaha", ekuitas: "Laporan Perubahan Ekuitas", kas: "Laporan Arus Kas" }[tab];

    return (
        <>
            <Head title="Laporan Keuangan" />
            <PageHeader
                icon={IconReportMoney}
                title="Laporan Keuangan"
                subtitle="Dihitung langsung dari jurnal."
                actions={<button type="button" className={btn.secondary} onClick={() => window.print()}><IconPrinter size={16} /> Cetak</button>}
            />
            <AccountingTabs />
            <PeriodFilter routeName="accounting.reports" filters={filters}>
                <Check value={balance.totals.selisih} label="Neraca" />
                <Check value={equity.selisih} label="Ekuitas" />
                <Check value={cash.selisih} label="Arus kas" />
            </PeriodFilter>
            <div className="mb-3 flex flex-wrap gap-1.5 print:hidden">
                {TABS.map(([k, label]) => (
                    <button key={k} type="button" onClick={() => setTab(k)} className={`rounded-xl px-3 py-1.5 text-sm font-medium ${k === tab ? "bg-primary-600 text-white" : "bg-white text-slate-600 hover:bg-slate-100 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"}`}>
                        {label}
                    </button>
                ))}
            </div>
            <Panel>
                <div className="px-4 pt-4 text-center">
                    <p className="text-sm font-semibold uppercase tracking-wide">{store}</p>
                    <p className="text-lg font-bold">{title}</p>
                    {tab !== "neraca" && <p className="text-sm text-slate-500">{fmtDate(filters.from)} s.d. {fmtDate(filters.to)}</p>}
                </div>
                <div className="p-2">
                    {tab === "neraca" && <BalanceSheet balance={balance} prior={balancePrior} filters={filters} />}
                    {tab === "phu" && <IncomeStatement income={income} />}
                    {tab === "ekuitas" && <Equity equity={equity} />}
                    {tab === "kas" && <CashFlow cash={cash} filters={filters} />}
                </div>
            </Panel>
        </>
    );
}

Reports.layout = (page) => <DashboardLayout children={page} />;
