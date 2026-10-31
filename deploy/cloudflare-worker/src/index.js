// Meneruskan https://kabanjawara.anzero.workers.dev ke aplikasi di PC lokal
// lewat Cloudflare Tunnel + Workers VPC. Tidak ada data yang disimpan di Worker.
export default {
    async fetch(request, env) {
        const url = new URL(request.url);
        const target = new URL(url.pathname + url.search, env.ORIGIN_URL);

        const headers = new Headers(request.headers);
        headers.set("X-Forwarded-Host", url.host);
        headers.set("X-Forwarded-Proto", "https");
        headers.set("X-Forwarded-Port", "443");
        headers.set("X-Forwarded-For", request.headers.get("CF-Connecting-IP") ?? "");

        return env.POS.fetch(target, {
            method: request.method,
            headers,
            body: ["GET", "HEAD"].includes(request.method) ? undefined : request.body,
            redirect: "manual",
        });
    },
};
