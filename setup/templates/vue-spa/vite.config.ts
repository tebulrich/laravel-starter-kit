import { defineConfig, type ProxyOptions } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';
import type { IncomingMessage, ServerResponse } from 'node:http';
import path from 'node:path';
import { fileURLToPath, URL } from 'node:url';
import type { Socket } from 'node:net';

const apiProxy = process.env.VITE_PROXY_TARGET ?? '{{API_PROXY}}';
const vitePort = Number(process.env.VITE_PORT ?? {{VITE_PORT}});

/**
 * Prefer mkcert PEMs from ./certs (created by ./scripts/create-certificate.sh / ./start.sh).
 * Falls back to HTTP when certs are missing so CI/tooling can still run vite.
 */
function httpsConfig(): { key: Buffer; cert: Buffer } | undefined {
    const certDir = process.env.VITE_CERT_DIR ?? path.resolve('certs');
    const keyPath = path.join(certDir, 'localhost-key.pem');
    const certPath = path.join(certDir, 'localhost.pem');

    if (fs.existsSync(keyPath) === false || fs.existsSync(certPath) === false) {
        return undefined;
    }

    return {
        key: fs.readFileSync(keyPath),
        cert: fs.readFileSync(certPath),
    };
}

/**
 * FrankenPHP/Caddy advertises HTTP/3 via Alt-Svc. When Vite proxies those
 * responses, Firefox applies Alt-Svc to the Vite origin (localhost:5173) and
 * then sends later document requests to Laravel on :443 — which redirects
 * "/" to "/up" and hides the Vue SPA. Strip Alt-Svc on every proxied response.
 */
function laravelProxy(): ProxyOptions {
    return {
        target: apiProxy,
        changeOrigin: true,
        // Local mkcert CA is trusted by the OS/browser; Node may still reject it.
        secure: false,
        configure: (proxy) => {
            proxy.on(
                'proxyRes',
                (proxyRes: IncomingMessage, _req: IncomingMessage, _res: ServerResponse | Socket) => {
                    delete proxyRes.headers['alt-svc'];
                },
            );
        },
    };
}

const https = httpsConfig();

export default defineConfig({
    plugins: [vue(), tailwindcss()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        https,
        hmr: https === undefined
            ? undefined
            : {
                  protocol: 'wss',
                  host: 'localhost',
                  port: vitePort,
              },
        proxy: {
            '/api': laravelProxy(),
            '/oauth': laravelProxy(),
            // Framework liveness for curl/tools only — SPA health uses /api/health.
            '/up': laravelProxy(),
        },
    },
});
