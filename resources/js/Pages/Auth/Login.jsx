import { useEffect, useState } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import AuthBotGuardFields from "@/Components/AuthBotGuardFields";
import {
    IconMail,
    IconLock,
    IconEye,
    IconEyeOff,
    IconLoader2,
    IconArrowRight,
    IconPigMoney,
    IconShoppingBag,
    IconChartPie,
    IconMapPin,
} from "@tabler/icons-react";

// Motif kawung (batik) sebagai pola latar, digambar inline agar tidak perlu file gambar.
const KAWUNG = `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='56' height='56' viewBox='0 0 56 56'%3E%3Cg fill='none' stroke='%23d4a843' stroke-width='1.2'%3E%3Cellipse cx='28' cy='14' rx='7' ry='12'/%3E%3Cellipse cx='28' cy='42' rx='7' ry='12'/%3E%3Cellipse cx='14' cy='28' rx='12' ry='7'/%3E%3Cellipse cx='42' cy='28' rx='12' ry='7'/%3E%3Ccircle cx='28' cy='28' r='2'/%3E%3C/g%3E%3C/svg%3E")`;

const PILLAR_ICONS = [IconPigMoney, IconShoppingBag, IconChartPie];

function initials(name) {
    const words = name
        .replace(/^koperasi\s+/i, "")
        .split(/\s+/)
        .filter(Boolean);
    return (words[0]?.[0] ?? "K") + (words[1]?.[0] ?? "");
}

function Emblem({ store, size = "lg" }) {
    const box = size === "lg" ? "w-16 h-16 text-xl" : "w-11 h-11 text-sm";
    if (store.logo) {
        return (
            <img
                src={store.logo}
                alt=""
                className={`${box} rounded-full object-cover ring-2 ring-[#d4a843] bg-white`}
            />
        );
    }
    return (
        <div
            className={`${box} rounded-full ring-2 ring-[#d4a843] ring-offset-4 ring-offset-[#0b1f3a] bg-[#d4a843] text-[#0b1f3a] font-extrabold tracking-tight flex items-center justify-center uppercase`}
        >
            {initials(store.name)}
        </div>
    );
}

export default function Login({ status, canResetPassword, canRegister, botGuard }) {
    const { t } = useTranslation();
    const store = usePage().props.storeProfile ?? { name: "Koperasi" };
    const honeypotField = botGuard?.honeypot_field || "company_website";
    const tokenField = botGuard?.token_field || "bot_guard_token";
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        password: "",
        remember: false,
        [honeypotField]: "",
        [tokenField]: botGuard?.token || "",
    });
    const [showPassword, setShowPassword] = useState(false);
    const pillars = t("auth.login.features", { returnObjects: true });

    useEffect(() => {
        return () => reset("password");
    }, []);

    const submit = (e) => {
        e.preventDefault();
        post(route("login"));
    };

    const inputClass = (hasError) =>
        `w-full h-12 pl-11 pr-4 rounded-lg border bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white placeholder-slate-400 transition-colors focus:outline-none focus:ring-0 ${
            hasError
                ? "border-danger-500"
                : "border-slate-200 dark:border-slate-700 focus:border-[#0b1f3a] dark:focus:border-[#d4a843] focus:bg-white dark:focus:bg-slate-800"
        }`;

    return (
        <>
            <Head title={t("auth.login.title")} />

            <div className="relative min-h-screen bg-[#0b1f3a] font-['Plus_Jakarta_Sans',sans-serif] overflow-hidden">
                <div
                    className="absolute inset-0 opacity-[0.07] pointer-events-none"
                    style={{ backgroundImage: KAWUNG }}
                />
                <div className="absolute -top-40 -left-40 w-[32rem] h-[32rem] rounded-full bg-[#d4a843]/10 blur-3xl pointer-events-none" />

                <div className="relative min-h-screen grid content-start lg:content-normal lg:grid-cols-[1.1fr_1fr]">
                    {/* Identitas koperasi */}
                    <section className="flex flex-col justify-between px-6 pt-10 pb-6 sm:px-12 lg:px-16 lg:py-14 text-white">
                        <div className="flex items-center gap-4">
                            <Emblem store={store} size="sm" />
                            <div className="leading-tight">
                                <p className="text-[11px] uppercase tracking-[0.25em] text-[#d4a843]">
                                    {t("auth.login.kicker")}
                                </p>
                                <p className="font-bold text-lg">{store.name}</p>
                            </div>
                        </div>

                        <div className="hidden lg:block max-w-xl py-12">
                            <div className="w-12 h-1 bg-[#d4a843] mb-8" />
                            <h2 className="text-4xl xl:text-5xl font-extrabold leading-[1.1] tracking-tight">
                                {t("auth.login.heroTitle")}
                            </h2>
                            <p className="mt-6 text-lg text-slate-300 leading-relaxed">
                                {t("auth.login.heroDescription")}
                            </p>
                            <ul className="mt-10 grid grid-cols-3 gap-4">
                                {pillars.map((label, i) => {
                                    const Icon = PILLAR_ICONS[i % PILLAR_ICONS.length];
                                    return (
                                        <li
                                            key={label}
                                            className="border-t border-white/15 pt-4"
                                        >
                                            <Icon
                                                size={24}
                                                stroke={1.6}
                                                className="text-[#d4a843]"
                                            />
                                            <p className="mt-3 text-sm font-semibold text-slate-100">
                                                {label}
                                            </p>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>

                        {store.address && (
                            <p className="hidden lg:flex items-start gap-2 text-xs text-slate-400 max-w-md">
                                <IconMapPin size={16} className="shrink-0 mt-px" />
                                {store.address}
                            </p>
                        )}
                    </section>

                    {/* Form masuk */}
                    <section className="flex items-start lg:items-center justify-center px-4 pb-10 sm:px-8 lg:py-14">
                        <div className="w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl shadow-black/30 overflow-hidden">
                            <div className="h-1.5 bg-gradient-to-r from-[#d4a843] via-[#f0d68a] to-[#d4a843]" />
                            <div className="p-7 sm:p-10">
                                <h1 className="text-2xl font-extrabold text-[#0b1f3a] dark:text-white">
                                    {t("auth.login.subtitle")}
                                </h1>
                                <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                                    {t("auth.login.description")}
                                </p>

                                {status && (
                                    <div className="mt-6 p-3 rounded-lg bg-success-50 dark:bg-success-950/50 text-success-700 dark:text-success-400 text-sm">
                                        {status}
                                    </div>
                                )}

                                <form onSubmit={submit} className="mt-8 space-y-5">
                                    <AuthBotGuardFields
                                        botGuard={botGuard}
                                        data={data}
                                        setData={setData}
                                    />
                                    {errors.human && (
                                        <div className="rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-600 dark:bg-danger-950/40 dark:text-danger-300">
                                            {errors.human}
                                        </div>
                                    )}

                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">
                                            {t("auth.login.email")}
                                        </label>
                                        <div className="relative">
                                            <IconMail
                                                size={18}
                                                className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
                                            />
                                            <input
                                                type="email"
                                                name="email"
                                                autoComplete="username"
                                                value={data.email}
                                                onChange={(e) =>
                                                    setData("email", e.target.value)
                                                }
                                                placeholder={t("auth.login.emailPlaceholder")}
                                                className={inputClass(errors.email)}
                                            />
                                        </div>
                                        {errors.email && (
                                            <p className="mt-1.5 text-sm text-danger-500">
                                                {errors.email}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <div className="flex items-center justify-between mb-2">
                                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                                {t("auth.login.password")}
                                            </label>
                                            {canResetPassword && (
                                                <Link
                                                    href={route("password.request")}
                                                    className="text-xs font-semibold text-[#0b1f3a] dark:text-[#d4a843] hover:underline"
                                                >
                                                    {t("auth.login.forgotPassword")}
                                                </Link>
                                            )}
                                        </div>
                                        <div className="relative">
                                            <IconLock
                                                size={18}
                                                className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
                                            />
                                            <input
                                                type={showPassword ? "text" : "password"}
                                                name="password"
                                                autoComplete="current-password"
                                                value={data.password}
                                                onChange={(e) =>
                                                    setData("password", e.target.value)
                                                }
                                                placeholder={t("auth.login.passwordPlaceholder")}
                                                className={`${inputClass(errors.password)} pr-12`}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPassword(!showPassword)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600"
                                            >
                                                {showPassword ? (
                                                    <IconEyeOff size={18} />
                                                ) : (
                                                    <IconEye size={18} />
                                                )}
                                            </button>
                                        </div>
                                        {errors.password && (
                                            <p className="mt-1.5 text-sm text-danger-500">
                                                {errors.password}
                                            </p>
                                        )}
                                    </div>

                                    <label className="flex items-center gap-2 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            checked={data.remember}
                                            onChange={(e) =>
                                                setData("remember", e.target.checked)
                                            }
                                            className="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-[#0b1f3a] focus:ring-[#d4a843]"
                                        />
                                        <span className="text-sm text-slate-600 dark:text-slate-400">
                                            {t("auth.login.remember")}
                                        </span>
                                    </label>

                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="group w-full h-12 rounded-lg bg-[#0b1f3a] dark:bg-[#d4a843] text-white dark:text-[#0b1f3a] font-bold hover:bg-[#13305a] dark:hover:bg-[#e0b955] focus:outline-none focus:ring-4 focus:ring-[#d4a843]/40 disabled:opacity-60 transition-colors flex items-center justify-center gap-2"
                                    >
                                        {processing ? (
                                            <>
                                                <IconLoader2 size={20} className="animate-spin" />
                                                {t("common.labels.processing")}
                                            </>
                                        ) : (
                                            <>
                                                {t("auth.login.submit")}
                                                <IconArrowRight
                                                    size={18}
                                                    className="transition-transform group-hover:translate-x-0.5"
                                                />
                                            </>
                                        )}
                                    </button>

                                    {canRegister && (
                                        <p className="text-center text-sm text-slate-600 dark:text-slate-400">
                                            {t("auth.login.noAccount")}{" "}
                                            <Link
                                                href="/register"
                                                className="font-semibold text-[#0b1f3a] dark:text-[#d4a843] hover:underline"
                                            >
                                                {t("auth.login.registerLink")}
                                            </Link>
                                        </p>
                                    )}
                                </form>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
