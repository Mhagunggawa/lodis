<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    BellRing,
    GlobeLock,
    RefreshCw,
    Server,
    ShieldCheck,
    Terminal,
} from '@lucide/vue';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="Lodis — Web Server & SSL Manager" />

    <div
        class="flex min-h-screen flex-col justify-between bg-[#F8F7FC] text-[#1E1B4B]"
    >
        <!-- Top Navbar -->
        <header class="border-b border-[#EDE9FE] bg-white/90 backdrop-blur-xs">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#7C3AED] text-white shadow-md shadow-[#7C3AED]/25"
                    >
                        <ShieldCheck class="size-6" />
                    </div>
                    <div>
                        <span class="text-lg font-extrabold tracking-tight text-[#1E1B4B]">
                            Lodis
                        </span>
                        <span
                            class="ml-2 rounded-full bg-[#EDE9FE] px-2.5 py-0.5 text-xs font-semibold text-[#7C3AED]"
                        >
                            v1.0 Single-Server
                        </span>
                    </div>
                </div>

                <nav class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center gap-2 rounded-xl bg-[#7C3AED] px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-[#7C3AED]/20 transition hover:bg-[#6D28D9]"
                    >
                        Buka Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="inline-flex items-center gap-2 rounded-xl bg-[#7C3AED] px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-[#7C3AED]/20 transition hover:bg-[#6D28D9]"
                    >
                        Login Administrator
                    </Link>
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <main class="mx-auto max-w-6xl px-6 py-12">
            <div class="mx-auto max-w-3xl text-center">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-[#EDE9FE] bg-white px-4 py-1.5 text-xs font-bold text-[#7C3AED] shadow-xs"
                >
                    <Server class="size-3.5" />
                    Windows Server / Laragon Web Server &amp; win-acme Controller
                </span>
                <h1
                    class="mt-5 text-4xl font-extrabold tracking-tight text-[#1E1B4B] sm:text-5xl"
                >
                    Kendalikan Web Server &amp; Sertifikat SSL Tanpa RDP
                </h1>
                <p class="mt-4 text-base leading-relaxed text-[#64748B]">
                    Panel kendali ringan berarsitektur <strong>Single-Server</strong> untuk memantau resource Windows, me-refresh layanan web server Laragon secara asinkron dengan jeda 3 detik, memperbarui sertifikat SSL via <code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 font-mono text-xs text-[#7C3AED]">win-acme</code>, dan mengirim pengingat otomatis ke Telegram &amp; Email.
                </p>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                    <Link
                        :href="$page.props.auth.user ? dashboard() : login()"
                        class="inline-flex items-center gap-2 rounded-xl bg-[#7C3AED] px-6 py-3 text-sm font-bold text-white shadow-lg shadow-[#7C3AED]/25 transition hover:bg-[#6D28D9]"
                    >
                        <ShieldCheck class="size-4" />
                        {{
                            $page.props.auth.user
                                ? 'Masuk ke Panel Kontrol'
                                : 'Masuk Sebagai Administrator'
                        }}
                    </Link>
                </div>
            </div>

            <!-- Feature Cards -->
            <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <RefreshCw class="size-5" />
                    </div>
                    <h3 class="mt-4 font-bold text-[#1E1B4B]">
                        Reload Asinkron 3 Detik
                    </h3>
                    <p class="mt-1.5 text-xs leading-relaxed text-[#64748B]">
                        Mengembalikan HTTP <code class="font-mono text-[#7C3AED]">202 Accepted</code> seketika sebelum me-restart layanan Laragon, dilengkapi auto-reconnect polling <code class="font-mono">/api/health</code>.
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <GlobeLock class="size-5" />
                    </div>
                    <h3 class="mt-4 font-bold text-[#1E1B4B]">
                        Inspeksi SSL &amp; win-acme
                    </h3>
                    <p class="mt-1.5 text-xs leading-relaxed text-[#64748B]">
                        Pengecekan X.509 native via PHP OpenSSL dan pembaruan sertifikat otomatis melalui <code class="font-mono text-[#7C3AED]">wacs.exe --renew</code>.
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <BellRing class="size-5" />
                    </div>
                    <h3 class="mt-4 font-bold text-[#1E1B4B]">
                        Reminder Anti-Spam
                    </h3>
                    <p class="mt-1.5 text-xs leading-relaxed text-[#64748B]">
                        Notifikasi otomatis H-14, H-7, H-3, dan H-1 ke Telegram Bot API &amp; SMTP Email dengan proteksi maksimal 1 pengingat/hari.
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <Terminal class="size-5" />
                    </div>
                    <h3 class="mt-4 font-bold text-[#1E1B4B]">
                        Command Whitelisting
                    </h3>
                    <p class="mt-1.5 text-xs leading-relaxed text-[#64748B]">
                        Hanya menerima kunci aksi statis dan menampilkan log stdout/stderr lengkap pada Terminal Output Viewer.
                    </p>
                </div>
            </div>
        </main>

        <footer class="border-t border-[#EDE9FE] bg-white py-4 text-center text-xs text-[#64748B]">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6">
                <span>Lodis — Web Server &amp; SSL Manager</span>
                <span class="inline-flex items-center gap-1.5 text-emerald-600">
                    <Activity class="size-3.5" />
                    Single-Server Windows Edition
                </span>
            </div>
        </footer>
    </div>
</template>
