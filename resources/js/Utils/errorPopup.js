import Swal from "sweetalert2";

/*
 * Popup error yang harus ditutup (bukan toast yang cepat hilang).
 * - Error fatal dari server (flash "error"): satu pesan.
 * - Error isian (validasi Inertia): bila lebih dari satu, atau kolomnya tidak terlihat di layar, tampil daftar
 *   "kolom yang perlu dicek" dengan tombol Lihat → gulir ke kolom itu, beri tanda merah sebentar, dan fokus.
 */

const escape = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
const isDark = () => document.body.classList.contains("dark") || document.documentElement.classList.contains("dark");
const theme = () =>
    isDark()
        ? { background: "#0f1b33", color: "#e2e8f0", confirmButtonColor: "#1e3a8a" }
        : { background: "#ffffff", color: "#0f172a", confirmButtonColor: "#1e3a8a" };

/** Cari elemen kolom untuk satu error: data-field / name / id, atau teks pesan error yang tampil di bawah kolom. */
export function findField(key, message) {
    // Kunci lengkap dulu, lalu awalannya (mis. board_members.3.months → board_members.3).
    const parts = key.split(".");
    for (let n = parts.length; n > 0; n--) {
        const k = parts.slice(0, n).join(".");
        const byAttr = document.querySelector(`[data-field="${CSS.escape(k)}"], [name="${CSS.escape(k)}"], #${CSS.escape(k.replace(/\./g, "_"))}`);
        if (byAttr) return byAttr.closest("label, [data-field]") || byAttr;
    }
    if (message) {
        const shown = [...document.querySelectorAll(".text-danger-600, .text-danger-700, [data-error]")].find((el) => el.textContent.trim() === String(message).trim());
        if (shown) return shown.closest("label, [data-field], td, li, div") || shown;
    }
    return null;
}

/** Nama kolom yang mudah dibaca: label kolom di layar, atau dari kunci (mis. board_members.3.months → Baris 4 · months). */
function fieldLabel(key, el) {
    const label = el?.getAttribute?.("data-label") || el?.closest?.("[data-label]")?.getAttribute("data-label");
    if (label) return label;
    const parts = key.split(".");
    const words = { board_members: "Pengurus", months: "bulan bertugas", customer_id: "nama", mapping: "pencocokan nama" };
    return parts.map((p) => (/^\d+$/.test(p) ? `baris ${Number(p) + 1}` : words[p] || p.replace(/_/g, " "))).join(" · ");
}

export function focusField(el) {
    if (!el) return;
    // Kolom di dalam bagian yang terlipat: buka dulu.
    for (let d = el.closest("details"); d; d = d.parentElement?.closest("details")) d.open = true;
    el.scrollIntoView({ behavior: "smooth", block: "center" });
    // Tanda merah sementara (style langsung, tidak bergantung kelas Tailwind yang mungkin tidak ter-build).
    const prev = { outline: el.style.outline, outlineOffset: el.style.outlineOffset, borderRadius: el.style.borderRadius };
    Object.assign(el.style, { outline: "3px solid #e11d48", outlineOffset: "3px", borderRadius: el.style.borderRadius || "6px" });
    setTimeout(() => Object.assign(el.style, prev), 3500);
    const input = el.matches("input, select, textarea") ? el : el.querySelector("input, select, textarea");
    setTimeout(() => input?.focus({ preventScroll: true }), 400);
}

/** Popup satu pesan (error fatal). */
export function showErrorPopup(message, title = "Terjadi kesalahan") {
    return Swal.fire({ ...theme(), icon: "error", title, text: String(message), confirmButtonText: "Tutup" });
}

/**
 * Popup daftar kolom bermasalah dari error validasi { key: pesan }.
 * Satu error yang kolomnya terlihat tidak dipopup (cukup tanda merah di kolom + digulir ke sana).
 */
export function showValidationPopup(errors) {
    const entries = Object.entries(errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]);
    if (entries.length === 0) return;
    const items = entries.map(([key, message]) => {
        const el = findField(key, message);
        return { key, message, el, label: fieldLabel(key, el) };
    });
    if (items.length === 1 && items[0].el) {
        focusField(items[0].el);
        return;
    }
    const list = items
        .map((it, i) => `<li style="margin:6px 0;display:flex;gap:8px;align-items:flex-start;justify-content:space-between">
            <span><b>${escape(it.label)}</b><br><span style="opacity:.85">${escape(it.message)}</span></span>
            ${it.el ? `<button type="button" data-goto="${i}" style="flex-shrink:0;border:1px solid currentColor;border-radius:6px;padding:2px 10px;font-size:12px;opacity:.9">Lihat</button>` : ""}
        </li>`)
        .join("");
    Swal.fire({
        ...theme(),
        icon: "warning",
        title: "Belum tersimpan",
        html: `<p style="margin-bottom:8px">${items.length} kolom perlu dicek:</p><ol style="text-align:left;max-height:50vh;overflow:auto;padding-left:18px;list-style:decimal">${list}</ol>`,
        confirmButtonText: "Tutup",
        didOpen: (popup) => {
            popup.querySelectorAll("[data-goto]").forEach((btn) =>
                btn.addEventListener("click", () => {
                    const it = items[Number(btn.dataset.goto)];
                    Swal.close();
                    setTimeout(() => focusField(it.el), 200);
                })
            );
        },
    });
}
