<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    Mail,
    Save,
    Send,
    Server,
    Shield,
} from '@lucide/vue';
import { ref } from 'vue';
import Swal from 'sweetalert2';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index as lodisSettingsIndex,
    testNotification,
    update as updateLodisSettings,
} from '@/routes/lodis-settings';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Pengaturan & Reminder SSL',
                href: lodisSettingsIndex(),
            },
        ],
    },
});

const props = defineProps<{
    settings: {
        telegram_bot_token: string;
        telegram_chat_id: string;
        notification_channel: string;
        notification_email: string;
        ssl_reminder_thresholds: string;
        webserver_service_name: string;
        win_acme_path: string;
        win_acme_task_name: string;
        admin_ip_whitelist: string;
    };
}>();

const form = useForm({
    telegram_bot_token: props.settings.telegram_bot_token,
    telegram_chat_id: props.settings.telegram_chat_id,
    notification_channel: props.settings.notification_channel || 'both',
    notification_email: props.settings.notification_email,
    ssl_reminder_thresholds: props.settings.ssl_reminder_thresholds || '14,7,3,1',
    webserver_service_name: props.settings.webserver_service_name || 'Apache2.4',
    win_acme_path: props.settings.win_acme_path || 'C:\\win-acme\\wacs.exe',
    win_acme_task_name: props.settings.win_acme_task_name || '',
});

const isTesting = ref(false);

function handleSaveSettings(): void {
    form.put(updateLodisSettings.url(), {
        preserveScroll: true,
    });
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function handleTestNotification(): Promise<void> {
    isTesting.value = true;

    try {
        const response = await fetch(testNotification.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify({
                telegram_bot_token: form.telegram_bot_token,
                telegram_chat_id: form.telegram_chat_id,
                notification_channel: form.notification_channel,
                notification_email: form.notification_email,
            }),
        });

        const data = (await response.json()) as {
            success: boolean;
            message: string;
        };

        await Swal.fire({
            icon: data.success ? 'success' : 'warning',
            title: data.success
                ? 'Tes Notifikasi Berhasil!'
                : 'Tes Notifikasi Belum Berhasil',
            text: data.message,
            confirmButtonColor: '#7C3AED',
        });
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'Gagal Mengirim Tes Notifikasi',
            text:
                error instanceof Error
                    ? error.message
                    : 'Terjadi kesalahan saat menghubungi server.',
            confirmButtonColor: '#7C3AED',
        });
    } finally {
        isTesting.value = false;
    }
}
</script>

<template>
    <Head title="Pengaturan & Reminder SSL" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm lg:flex-row lg:items-center"
        >
            <div>
                <h1 class="text-xl font-bold text-[#1E1B4B]">
                    Pengaturan Kanal Reminder SSL &amp; Konfigurasi Server
                </h1>
                <p class="text-sm text-[#64748B]">
                    Kelola kredensial Telegram Bot API, Email SMTP, ambang batas hari peringatan (<code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 text-xs text-[#7C3AED]">php artisan ssl:check-reminder</code>), dan parameter Laragon/win-acme.
                </p>
            </div>

            <Button
                type="button"
                variant="outline"
                class="border-[#7C3AED] bg-[#EDE9FE]/50 font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                :disabled="isTesting"
                @click="handleTestNotification"
            >
                <Send class="mr-1.5 size-4" />
                {{ isTesting ? 'Mengirim Uji Coba...' : 'Tes Kirim Notifikasi' }}
            </Button>
        </div>

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="handleSaveSettings">
            <!-- Notification Settings Card -->
            <div
                class="flex flex-col justify-between rounded-2xl border border-[#EDE9FE] bg-white p-6 shadow-sm"
            >
                <div class="space-y-4">
                    <div class="flex items-center gap-2.5 border-b border-[#EDE9FE] pb-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                        >
                            <BellRing class="size-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-[#1E1B4B]">
                                Kanal Reminder (Telegram &amp; Email)
                            </h2>
                            <p class="text-xs text-[#64748B]">
                                Proteksi anti-spam: maksimal 1 pengingat per domain per hari
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="notification_channel" class="font-semibold text-[#1E1B4B]">
                            Kanal Notifikasi Aktif
                        </Label>
                        <select
                            id="notification_channel"
                            v-model="form.notification_channel"
                            class="h-10 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] px-3 text-sm font-medium text-[#1E1B4B] focus:border-[#7C3AED] focus:outline-none"
                        >
                            <option value="both">
                                Keduanya (Telegram Bot API + SMTP Email)
                            </option>
                            <option value="telegram">
                                Telegram Bot API Saja
                            </option>
                            <option value="email">SMTP Email Saja</option>
                        </select>
                        <InputError :message="form.errors.notification_channel" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="telegram_bot_token" class="font-semibold text-[#1E1B4B]">
                            Telegram Bot Token
                        </Label>
                        <Input
                            id="telegram_bot_token"
                            v-model="form.telegram_bot_token"
                            type="text"
                            placeholder="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ"
                            class="border-[#EDE9FE]"
                        />
                        <InputError :message="form.errors.telegram_bot_token" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="telegram_chat_id" class="font-semibold text-[#1E1B4B]">
                            Telegram Chat ID / Group ID
                        </Label>
                        <Input
                            id="telegram_chat_id"
                            v-model="form.telegram_chat_id"
                            type="text"
                            placeholder="-1001234567890"
                            class="border-[#EDE9FE]"
                        />
                        <InputError :message="form.errors.telegram_chat_id" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="notification_email" class="font-semibold text-[#1E1B4B]">
                            Alamat Email Penerima Reminder (SMTP)
                        </Label>
                        <div class="relative">
                            <Mail
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-[#64748B]"
                            />
                            <Input
                                id="notification_email"
                                v-model="form.notification_email"
                                type="email"
                                placeholder="admin@lodis.local"
                                class="border-[#EDE9FE] pl-9"
                            />
                        </div>
                        <InputError :message="form.errors.notification_email" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="ssl_reminder_thresholds" class="font-semibold text-[#1E1B4B]">
                            Ambang Batas Hari Pengingat (Dipisahkan Koma)
                        </Label>
                        <Input
                            id="ssl_reminder_thresholds"
                            v-model="form.ssl_reminder_thresholds"
                            type="text"
                            placeholder="14,7,3,1"
                            class="border-[#EDE9FE]"
                        />
                        <p class="text-xs text-[#64748B]">
                            Contoh: <code class="font-mono text-[#7C3AED]">14,7,3,1</code> akan memicu notifikasi saat sisa masa aktif &le; 14, 7, 3, atau 1 hari.
                        </p>
                        <InputError :message="form.errors.ssl_reminder_thresholds" />
                    </div>
                </div>
            </div>

            <!-- Windows Server & win-acme Configuration Card -->
            <div
                class="flex flex-col justify-between rounded-2xl border border-[#EDE9FE] bg-white p-6 shadow-sm"
            >
                <div class="space-y-4">
                    <div class="flex items-center gap-2.5 border-b border-[#EDE9FE] pb-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                        >
                            <Server class="size-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-[#1E1B4B]">
                                Konfigurasi Laragon &amp; win-acme
                            </h2>
                            <p class="text-xs text-[#64748B]">
                                Parameter eksekutor asinkron <code class="font-mono">scripts/server-runner.ps1</code>
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="webserver_service_name" class="font-semibold text-[#1E1B4B]">
                            Nama Layanan / Service Web Server Laragon
                        </Label>
                        <Input
                            id="webserver_service_name"
                            v-model="form.webserver_service_name"
                            type="text"
                            placeholder="Apache2.4"
                            class="border-[#EDE9FE]"
                        />
                        <p class="text-xs text-[#64748B]">
                            Mendukung Windows Service (<code class="font-mono">Apache2.4</code>, <code class="font-mono">nginx</code>, <code class="font-mono">W3SVC</code>) maupun graceful reload proses Laragon (<code class="font-mono">httpd.exe -k restart</code>).
                        </p>
                        <InputError :message="form.errors.webserver_service_name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="win_acme_path" class="font-semibold text-[#1E1B4B]">
                            Path Lengkap Executable win-acme (wacs.exe)
                        </Label>
                        <Input
                            id="win_acme_path"
                            v-model="form.win_acme_path"
                            type="text"
                            placeholder="C:\win-acme\wacs.exe"
                            class="border-[#EDE9FE] font-mono text-xs"
                        />
                        <InputError :message="form.errors.win_acme_path" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="win_acme_task_name" class="font-semibold text-[#1E1B4B]">
                            Nama Windows Scheduled Task win-acme (Opsional)
                        </Label>
                        <Input
                            id="win_acme_task_name"
                            v-model="form.win_acme_task_name"
                            type="text"
                            placeholder="win-acme-renew (Kosongkan jika langsung via wacs.exe)"
                            class="border-[#EDE9FE]"
                        />
                        <InputError :message="form.errors.win_acme_task_name" />
                    </div>

                    <div
                        class="rounded-xl border border-[#EDE9FE] bg-[#F8F7FC] p-4 text-xs text-[#64748B]"
                    >
                        <div class="flex items-center gap-2 font-bold text-[#1E1B4B]">
                            <Shield class="size-4 text-[#7C3AED]" />
                            <span>Status Keamanan &amp; IP Whitelist</span>
                        </div>
                        <p class="mt-1">
                            ADMIN_IP_WHITELIST (<code class="font-mono">.env</code>):
                            <strong class="text-[#7C3AED]">
                                {{
                                    settings.admin_ip_whitelist
                                        ? settings.admin_ip_whitelist
                                        : 'Semua IP Diizinkan (Tidak Dibatasi)'
                                }}
                            </strong>
                        </p>
                        <p class="mt-1">
                            Command Whitelisting aktif: Hanya menerima kunci statis
                            <code class="font-mono text-[#1E1B4B]">reload_webserver</code> dan
                            <code class="font-mono text-[#1E1B4B]">renew_ssl</code>.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end border-t border-[#EDE9FE] pt-4">
                    <Button
                        type="submit"
                        class="bg-[#7C3AED] font-semibold text-white shadow-md shadow-[#7C3AED]/20 hover:bg-[#6D28D9]"
                        :disabled="form.processing"
                    >
                        <Save class="mr-1.5 size-4" />
                        {{
                            form.processing
                                ? 'Menyimpan...'
                                : 'Simpan Pengaturan'
                        }}
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
