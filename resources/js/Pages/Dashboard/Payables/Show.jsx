import React, { useEffect, useState, useRef } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconArrowLeft,
    IconCreditCard,
    IconCash,
    IconPrinter,
} from "@tabler/icons-react";
import toast from "react-hot-toast";
import { useAuthorization } from "@/Utils/authorization";

    const formatCurrency = (value = 0) =>
        new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            minimumFractionDigits: 0,
        }).format(value);

    const formatDate = (value) => {
        if (!value) return "-";
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return value;
        return d.toLocaleDateString("id-ID", {
            day: "2-digit",
            month: "short",
            year: "numeric",
        });
    };

export default function PayableShow({ payable, bankAccounts = [] }) {
    const { flash, storeProfile } = usePage().props;
    const { can } = useAuthorization();
    const [showForm, setShowForm] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const printRef = useRef(null);
    const { data, setData, post, processing, reset, errors } = useForm({
        amount: "",
        paid_at: new Date().toISOString().slice(0, 10),
        method: "cash",
        bank_account_id: "",
        note: "",
    });
    const canPayPayable = can("payables-pay");

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const statusBadge = (value) => {
        const base = "px-2 py-1 text-xs font-semibold rounded-full";
        switch (value) {
            case "paid":
                return (
                    <span className={`${base} bg-success-100 text-success-700`}>
                        Lunas
                    </span>
                );
            case "partial":
                return (
                    <span className={`${base} bg-primary-100 text-primary-700`}>
                        Parsial
                    </span>
                );
            case "overdue":
                return (
                    <span className={`${base} bg-rose-100 text-rose-700`}>
                        Jatuh Tempo
                    </span>
                );
            default:
                return (
                    <span className={`${base} bg-amber-100 text-amber-700`}>
                        Belum Lunas
                    </span>
                );
        }
    };

    const submitPayment = (e) => {
        e.preventDefault();
        post(route("payables.pay", payable.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const handlePrint = () => {
        window.print();
    };

    return (
        <>
            <Head title={`Hutang ${payable.document_number}`} />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route("payables.index")}
                            className="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                        >
                            <IconArrowLeft size={18} />
                            Kembali
                        </Link>
                        <div>
                            <p className="text-xs text-slate-500">Dokumen</p>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                                {payable.document_number}
                            </h1>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <div>{statusBadge(payable.status)}</div>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div
                        ref={printRef}
                        className="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-4 print:border-0 print:shadow-none"
                    >
                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p className="text-slate-500">Supplier</p>
                                <p className="font-semibold text-slate-800 dark:text-white">
                                    {payable.supplier?.name || "-"}
                                </p>
                                {payable.supplier?.phone && (
                                    <p className="text-xs text-slate-500">
                                        {payable.supplier.phone}
                                    </p>
                                )}
                            </div>
                            <div className="text-right">
                                <p className="text-slate-500">Jatuh Tempo</p>
                                <p className="font-semibold text-slate-800 dark:text-white">
                                    {formatDate(payable.due_date)}
                                </p>
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                <p className="text-xs text-slate-500">Total</p>
                                <p className="text-lg font-bold text-slate-900 dark:text-white">
                                    {formatCurrency(payable.total)}
                                </p>
                            </div>
                            <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                <p className="text-xs text-slate-500">Terbayar</p>
                                <p className="text-lg font-bold text-success-600">
                                    {formatCurrency(payable.paid)}
                                </p>
                            </div>
                            <div className="p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800">
                                <p className="text-xs text-amber-700">Sisa</p>
                                <p className="text-lg font-bold text-amber-700">
                                    {formatCurrency(payable.remaining)}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                Riwayat Pembayaran
                            </p>
                            {payable.status !== "paid" && canPayPayable && (
                                <button
                                    onClick={() => setShowForm(!showForm)}
                                    className="px-3 py-2 rounded-xl text-sm font-semibold bg-primary-500 hover:bg-primary-600 text-white transition-colors"
                                >
                                    Tambah Pembayaran
                                </button>
                            )}
                        </div>

                        <div className="space-y-2">
                                        {payable.payments?.length ? (
                                            payable.payments.map((pay) => (
                                                <div
                                                    key={pay.id}
                                                    className="p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 flex items-center justify-between"
                                                >
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-800 dark:text-white">
                                                            {formatCurrency(pay.amount)}
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {formatDate(pay.paid_at)} • {pay.method || "metode"}
                                                            {pay.bank_account && ` • ${pay.bank_account.bank_name}`}
                                                        </p>
                                                        {pay.note && (
                                                            <p className="text-xs text-slate-500 mt-1">
                                                                {pay.note}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <span className="text-xs text-slate-500">
                                                        {pay.user?.name || "-"}
                                        </span>
                                    </div>
                                ))
                            ) : (
                                <div className="text-sm text-slate-500">
                                    Belum ada pembayaran.
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 print:hidden">
                        <p className="text-sm font-semibold text-slate-800 dark:text-white mb-3">
                            Detail Hutang
                        </p>
                        <div className="space-y-2 text-sm text-slate-600 dark:text-slate-400">
                            <div className="flex justify-between">
                                <span>Nomor</span>
                                <span className="font-semibold text-slate-800 dark:text-white">
                                    {payable.document_number}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span>Jatuh Tempo</span>
                                <span>{formatDate(payable.due_date)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Status</span>
                                <span>{statusBadge(payable.status)}</span>
                            </div>
                        </div>

                        {showForm && canPayPayable && (
                            <form onSubmit={submitPayment} className="mt-4 space-y-3">
                                <div>
                                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Nominal
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        value={data.amount}
                                        onChange={(e) => setData("amount", e.target.value)}
                                        className="w-full h-11 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                        required
                                    />
                                    {errors.amount && (
                                        <p className="text-xs text-danger-500 mt-1">
                                            {errors.amount}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Tanggal Bayar
                                    </label>
                                    <input
                                        type="date"
                                        value={data.paid_at}
                                        onChange={(e) => setData("paid_at", e.target.value)}
                                        className="w-full h-11 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                        required
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setData("method", "cash")}
                                        className={`h-11 rounded-xl border-2 flex items-center justify-center gap-2 text-sm font-semibold ${
                                            data.method === "cash"
                                                ? "border-primary-500 bg-primary-50 text-primary-700"
                                                : "border-slate-200 dark:border-slate-700"
                                        }`}
                                    >
                                        <IconCash size={16} />
                                        Tunai (kas toko)
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setData("method", "bank_transfer")}
                                        className={`h-11 rounded-xl border-2 flex items-center justify-center gap-2 text-sm font-semibold ${
                                            data.method === "bank_transfer"
                                                ? "border-primary-500 bg-primary-50 text-primary-700"
                                                : "border-slate-200 dark:border-slate-700"
                                        }`}
                                    >
                                        <IconCreditCard size={16} />
                                        Transfer (BSI)
                                    </button>
                                </div>
                                {data.method === "bank_transfer" && (
                                    <div>
                                        <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                            Rekening
                                        </label>
                                        <select
                                            value={data.bank_account_id}
                                            onChange={(e) =>
                                                setData("bank_account_id", e.target.value)
                                            }
                                            className="w-full h-11 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                        >
                                            <option value="">Pilih rekening</option>
                                            {bankAccounts.map((bank) => (
                                                <option key={bank.id} value={bank.id}>
                                                    {bank.bank_name} - {bank.account_number}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                <div>
                                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Catatan (opsional)
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={data.note}
                                        onChange={(e) => setData("note", e.target.value)}
                                        className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                        placeholder="Catatan pembayaran"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full h-11 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold flex items-center justify-center gap-2 transition-colors disabled:opacity-50"
                                >
                                    Simpan Pembayaran
                                </button>
                            </form>
                        )}

                        <div className="mt-4">
                            <button
                                onClick={() => setShowPreview(true)}
                                className="w-full h-11 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold flex items-center justify-center gap-2"
                            >
                                <IconPrinter size={18} />
                                Preview / PDF
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {showPreview && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl relative overflow-hidden">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-800">
                            <div>
                                <p className="text-xs text-slate-500">Preview Hutang</p>
                                <p className="text-sm font-semibold text-slate-800 dark:text-white">
                                    {payable.document_number}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <a
                                    href={route("pdf.payables.show", payable.id)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold"
                                >
                                    <IconPrinter size={16} />
                                    PDF / Cetak
                                </a>
                                <button
                                    onClick={() => setShowPreview(false)}
                                    className="text-sm px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300"
                                >
                                    Tutup
                                </button>
                            </div>
                        </div>
                        <div className="p-6 bg-slate-50 dark:bg-slate-900">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 print-area">
                        <div className="flex items-start justify-between gap-4">
                                    <div className="flex items-center gap-3">
                                        <div className="w-12 h-12 border border-slate-200 rounded-md flex items-center justify-center overflow-hidden">
                                            {storeProfile?.logo ? (
                                                <img
                                                    src={storeProfile.logo}
                                                    alt={storeProfile.name}
                                                    className="max-w-full max-h-full object-contain"
                                                />
                                            ) : (
                                                <span className="font-bold text-primary-600">
                                                    {storeProfile?.name?.[0] || "T"}
                                                </span>
                                            )}
                                        </div>
                                        <div>
                                            <p className="text-lg font-bold text-slate-900 dark:text-white">
                                                {storeProfile?.name}
                                            </p>
                                            {storeProfile?.address && (
                                                <p className="text-xs text-slate-500">{storeProfile.address}</p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs text-slate-500">Dokumen</p>
                                <p className="text-lg font-bold text-slate-900 dark:text-white">
                                    {payable.document_number}
                                </p>
                                <p className="text-xs text-slate-500">
                                    Jatuh tempo: {formatDate(payable.due_date)}
                                </p>
                            </div>
                        </div>

                                <div className="grid grid-cols-2 gap-4 text-sm mt-4">
                                    <div>
                                        <p className="text-slate-500">Supplier</p>
                                        <p className="font-semibold text-slate-800 dark:text-white">
                                            {payable.supplier?.name || "-"}
                                        </p>
                                        {payable.supplier?.phone && (
                                            <p className="text-xs text-slate-500">
                                                {payable.supplier.phone}
                                            </p>
                                        )}
                                    </div>
                                    <div className="text-right">
                                        <p className="text-slate-500">Status</p>
                                        <p className="font-semibold text-slate-800 dark:text-white">
                                            {statusBadge(payable.status)}
                                        </p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-3 gap-3 mt-4">
                                    <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                        <p className="text-xs text-slate-500">Total</p>
                                        <p className="text-lg font-bold text-slate-900 dark:text-white">
                                            {formatCurrency(payable.total)}
                                        </p>
                                    </div>
                                    <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                        <p className="text-xs text-slate-500">Terbayar</p>
                                        <p className="text-lg font-bold text-success-600">
                                            {formatCurrency(payable.paid)}
                                        </p>
                                    </div>
                                    <div className="p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800">
                                        <p className="text-xs text-amber-700">Sisa</p>
                                        <p className="text-lg font-bold text-amber-700">
                                            {formatCurrency(payable.remaining)}
                                        </p>
                                    </div>
                                </div>

                                <div className="mt-4">
                                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">
                                        Riwayat Pembayaran
                                    </p>
                                    <div className="space-y-2 text-sm">
                                        {payable.payments?.length ? (
                                            payable.payments.map((pay) => (
                                                <div
                                                    key={pay.id}
                                                    className="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700"
                                                >
                                                    <div>
                                                        <p className="font-semibold text-slate-800 dark:text-white">
                                                            {formatCurrency(pay.amount)}
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {formatDate(pay.paid_at)} • {pay.method || "metode"}
                                                            {pay.bank_account && ` • ${pay.bank_account.bank_name}`}
                                                        </p>
                                                    </div>
                                                    <span className="text-xs text-slate-500">
                                                        {pay.user?.name || "-"}
                                                    </span>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="text-xs text-slate-500">
                                                Belum ada pembayaran.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    .print-area, .print-area * { visibility: visible; }
                    .print-area {
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 100%;
                    }
                }
            `}</style>
        </>
    );
}

PayableShow.layout = (page) => <DashboardLayout children={page} />;
