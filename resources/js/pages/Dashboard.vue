<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock,
    Cpu,
    GlobeLock,
    HardDrive,
    MemoryStick,
    RefreshCw,
    ScanSearch,
    Server,
    ShieldAlert,
    ShieldCheck,
    Terminal,
    X,
} from '@lucide/vue';
import {
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { executeServerActionWithPolling, showToast } from '@/lib/swal';
import { dashboard } from '@/routes';
import { index as domainsIndex, scanAll } from '@/routes/domains';
import { index as logsIndex } from '@/routes/logs';

Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Filler,
    Tooltip,
    Legend,
);

interface ActionLogItem {
    id: number;
    action_type: 'reload_webserver' | 'renew_ssl';
    status: 'queued' | 'running' | 'success' | 'failed';
    output: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
    user?: {
        id: number;
        name: string;
        email: string;
    } | null;
}

interface DomainItem {
    id: number;
    domain_name: string;
    port: number;
    issuer: string | null;
    ssl_valid_from: string | null;
    ssl_expires_at: string | null;
    days_remaining: number | null;
    status: 'valid' | 'warning' | 'critical' | 'error';
    last_checked_at: string | null;
    is_active: boolean;
}

interface ServerMetrics {
    cpu_percent: number;
    ram_percent: number;
    ram_used_gb: number;
    ram_total_gb: number;
    disk_percent: number;
    disk_used_gb: number;
    disk_total_gb: number;
    webserver_online: boolean;
    webserver_name: string;
    os_name: string;
    uptime_hours: number;
    history: Array<{ time: string; cpu: number; ram: number }>;
    collected_at: string;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard Monitoring Server',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    kpi: {
        webserver_online: boolean;
        webserver_name: string;
        total_domains: number;
        active_domains: number;
        critical_ssl_count: number;
        warning_ssl_count: number;
        last_action: ActionLogItem | null;
    };
    metrics: ServerMetrics;
    domains: DomainItem[];
    recentLogs: ActionLogItem[];
}>();

const chartCanvas = ref<HTMLCanvasElement | null>(null);
let chartInstance: Chart | null = null;
const isScanningAll = ref(false);
const selectedLogForOutput = ref<ActionLogItem | null>(null);

function renderMetricsChart(): void {
    if (!chartCanvas.value) {
        return;
    }

    const labels = props.metrics.history.map((item) => item.time);
    const cpuData = props.metrics.history.map((item) => item.cpu);
    const ramData = props.metrics.history.map((item) => item.ram);

    if (chartInstance) {
        chartInstance.data.labels = labels;
        chartInstance.data.datasets[0].data = cpuData;
        chartInstance.data.datasets[1].data = ramData;
        chartInstance.update();
        return;
    }

    chartInstance = new Chart(chartCanvas.value, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'CPU Usage (%)',
                    data: cpuData,
                    borderColor: '#7C3AED',
                    backgroundColor: 'rgba(124, 58, 237, 0.14)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#7C3AED',
                },
                {
                    label: 'RAM Usage (%)',
                    data: ramData,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#10B981',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    min: 0,
                    max: 100,
                    ticks: {
                        callback: (value) => `${value}%`,
                        color: '#64748B',
                    },
                    grid: {
                        color: '#EDE9FE',
                    },
                },
                x: {
                    ticks: {
                        color: '#64748B',
                    },
                    grid: {
                        display: false,
                    },
                },
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#1E1B4B',
                        usePointStyle: true,
                        boxWidth: 8,
                    },
                },
            },
        },
    });
}

onMounted(() => {
    renderMetricsChart();
});

watch(
    () => props.metrics.history,
    () => {
        renderMetricsChart();
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }
});

function handleTriggerAction(action: 'reload_webserver' | 'renew_ssl'): void {
    void executeServerActionWithPolling(action, () => {
        router.reload();
    });
}

function handleScanAllSsl(): void {
    isScanningAll.value = true;
    router.post(
        scanAll.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isScanningAll.value = false;
            },
        },
    );
}

function handleRefreshMetrics(): void {
    router.reload({
        only: ['metrics', 'kpi'],
        onSuccess: () => {
            showToast('Metrik resource server berhasil diperbarui.');
        },
    });
}

function formatActionLabel(actionType: string): string {
    return actionType === 'reload_webserver'
        ? 'Refresh Web Server'
        : 'Renew SSL (win-acme)';
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
        return '-';
    }
    return new Date(dateStr).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Dashboard Server & SSL" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- Header & Quick Action Bar -->
        <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm lg:flex-row lg:items-center"
        >
            <div>
                <div class="flex items-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-[#EDE9FE] px-3 py-1 text-xs font-semibold text-[#7C3AED]"
                    >
                        <Server class="size-3.5" />
                        {{ metrics.os_name }}
                    </span>
                    <span class="text-xs text-[#64748B]">
                        Uptime: {{ metrics.uptime_hours }} jam
                    </span>
                </div>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-[#1E1B4B]">
                    Lodis — Web Server &amp; SSL Control Panel
                </h1>
                <p class="text-sm text-[#64748B]">
                    Pemantauan kesehatan server Windows Laragon, kontrol reload asinkron jeda 3 detik, dan manajemen SSL win-acme.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="button"
                    class="bg-[#7C3AED] font-semibold text-white shadow-sm shadow-[#7C3AED]/25 hover:bg-[#6D28D9]"
                    @click="handleTriggerAction('reload_webserver')"
                >
                    <RefreshCw class="mr-1.5 size-4" />
                    Refresh Web Server
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="border-[#7C3AED]/30 bg-[#EDE9FE]/50 font-semibold text-[#7C3AED] hover:bg-[#EDE9FE] hover:text-[#6D28D9]"
                    @click="handleTriggerAction('renew_ssl')"
                >
                    <ShieldCheck class="mr-1.5 size-4" />
                    Renew SSL (win-acme)
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="border-[#EDE9FE] bg-white font-semibold text-[#1E1B4B] hover:bg-[#F8F7FC]"
                    :disabled="isScanningAll"
                    @click="handleScanAllSsl"
                >
                    <ScanSearch
                        class="mr-1.5 size-4 text-[#7C3AED]"
                        :class="{ 'animate-spin': isScanningAll }"
                    />
                    {{ isScanningAll ? 'Memindai...' : 'Cek Semua SSL' }}
                </Button>
            </div>
        </div>

        <!-- 4 KPI Cards -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <!-- KPI 1: Web Server Status -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold tracking-wider text-[#64748B] uppercase">
                        Status Web Server
                    </span>
                    <div
                        class="flex size-10 items-center justify-center rounded-xl"
                        :class="
                            kpi.webserver_online
                                ? 'bg-emerald-50 text-emerald-600'
                                : 'bg-rose-50 text-rose-600'
                        "
                    >
                        <Activity class="size-5" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-2.5">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold"
                        :class="
                            kpi.webserver_online
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-rose-100 text-rose-700'
                        "
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="
                                kpi.webserver_online
                                    ? 'bg-emerald-500'
                                    : 'bg-rose-500'
                            "
                        />
                        {{ kpi.webserver_online ? 'ONLINE' : 'OFFLINE' }}
                    </span>
                </div>
                <p class="mt-2 truncate text-xs font-medium text-[#64748B]">
                    Target: {{ kpi.webserver_name }}
                </p>
            </div>

            <!-- KPI 2: Total Monitored Domains -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold tracking-wider text-[#64748B] uppercase">
                        Total Domain Terpantau
                    </span>
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <GlobeLock class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-3xl font-extrabold text-[#1E1B4B]">
                    {{ kpi.total_domains }}
                </div>
                <p class="mt-1 text-xs text-[#64748B]">
                    {{ kpi.active_domains }} domain aktif dipantau
                </p>
            </div>

            <!-- KPI 3: Critical SSL (<= 7 days) -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold tracking-wider text-[#64748B] uppercase">
                        SSL Kritis (&le; 7 Hari)
                    </span>
                    <div
                        class="flex size-10 items-center justify-center rounded-xl"
                        :class="
                            kpi.critical_ssl_count > 0
                                ? 'bg-rose-50 text-rose-600'
                                : 'bg-emerald-50 text-emerald-600'
                        "
                    >
                        <ShieldAlert class="size-5" />
                    </div>
                </div>
                <div
                    class="mt-2 text-3xl font-extrabold"
                    :class="
                        kpi.critical_ssl_count > 0
                            ? 'text-rose-600'
                            : 'text-[#1E1B4B]'
                    "
                >
                    {{ kpi.critical_ssl_count }}
                </div>
                <p class="mt-1 text-xs text-[#64748B]">
                    {{ kpi.warning_ssl_count }} domain berstatus peringatan (8–14 hari)
                </p>
            </div>

            <!-- KPI 4: Last Action -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold tracking-wider text-[#64748B] uppercase">
                        Aksi Terakhir
                    </span>
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#EDE9FE] text-[#7C3AED]"
                    >
                        <Clock class="size-5" />
                    </div>
                </div>
                <div v-if="kpi.last_action" class="mt-2">
                    <div class="flex items-center gap-2">
                        <span class="truncate text-sm font-bold text-[#1E1B4B]">
                            {{ formatActionLabel(kpi.last_action.action_type) }}
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                            :class="{
                                'bg-emerald-100 text-emerald-700':
                                    kpi.last_action.status === 'success',
                                'bg-rose-100 text-rose-700':
                                    kpi.last_action.status === 'failed',
                                'bg-amber-100 text-amber-700':
                                    kpi.last_action.status === 'running' ||
                                    kpi.last_action.status === 'queued',
                            }"
                        >
                            {{ kpi.last_action.status }}
                        </span>
                    </div>
                    <p class="mt-1.5 text-xs text-[#64748B]">
                        {{ formatDate(kpi.last_action.created_at) }}
                    </p>
                </div>
                <div v-else class="mt-3 text-sm text-[#64748B]">
                    Belum ada riwayat aksi
                </div>
            </div>
        </div>

        <!-- Server Resource Metrics & Chart.js -->
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Resource Gauges -->
            <div
                class="flex flex-col justify-between rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div>
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-[#1E1B4B]">
                            Resource Server Windows
                        </h2>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg bg-[#F8F7FC] px-2.5 py-1 text-xs font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                            @click="handleRefreshMetrics"
                        >
                            <RefreshCw class="size-3" />
                            Segarkan
                        </button>
                    </div>
                    <p class="mt-0.5 text-xs text-[#64748B]">
                        Diambil via PowerShell WMI/CIM (Cache 30 detik)
                    </p>

                    <div class="mt-5 space-y-5">
                        <!-- CPU -->
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2 font-semibold text-[#1E1B4B]">
                                    <Cpu class="size-4 text-[#7C3AED]" />
                                    CPU Load
                                </span>
                                <span class="font-bold text-[#7C3AED]">
                                    {{ metrics.cpu_percent }}%
                                </span>
                            </div>
                            <div
                                class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-[#EDE9FE]"
                            >
                                <div
                                    class="h-full rounded-full bg-[#7C3AED] transition-all duration-500"
                                    :style="{
                                        width: `${Math.min(100, metrics.cpu_percent)}%`,
                                    }"
                                />
                            </div>
                        </div>

                        <!-- RAM -->
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2 font-semibold text-[#1E1B4B]">
                                    <MemoryStick class="size-4 text-emerald-600" />
                                    RAM Memory
                                </span>
                                <span class="font-bold text-emerald-600">
                                    {{ metrics.ram_percent }}%
                                    <span class="text-xs font-normal text-[#64748B]">
                                        ({{ metrics.ram_used_gb }} / {{ metrics.ram_total_gb }} GB)
                                    </span>
                                </span>
                            </div>
                            <div
                                class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-[#EDE9FE]"
                            >
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                                    :style="{
                                        width: `${Math.min(100, metrics.ram_percent)}%`,
                                    }"
                                />
                            </div>
                        </div>

                        <!-- Disk -->
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2 font-semibold text-[#1E1B4B]">
                                    <HardDrive class="size-4 text-amber-500" />
                                    Penyimpanan Disk
                                </span>
                                <span class="font-bold text-amber-600">
                                    {{ metrics.disk_percent }}%
                                    <span class="text-xs font-normal text-[#64748B]">
                                        ({{ metrics.disk_used_gb }} / {{ metrics.disk_total_gb }} GB)
                                    </span>
                                </span>
                            </div>
                            <div
                                class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-[#EDE9FE]"
                            >
                                <div
                                    class="h-full rounded-full bg-amber-500 transition-all duration-500"
                                    :style="{
                                        width: `${Math.min(100, metrics.disk_percent)}%`,
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-6 rounded-xl border border-[#EDE9FE] bg-[#F8F7FC] p-3 text-xs text-[#64748B]"
                >
                    Pembaruan terakhir: <strong class="text-[#1E1B4B]">{{ metrics.collected_at }}</strong>
                </div>
            </div>

            <!-- Chart.js Line Chart -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm lg:col-span-2"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-[#1E1B4B]">
                            Tren Penggunaan CPU &amp; RAM Server
                        </h2>
                        <p class="text-xs text-[#64748B]">
                            Visualisasi metrik real-time server Windows dengan Chart.js
                        </p>
                    </div>
                    <span
                        class="rounded-lg bg-[#EDE9FE] px-2.5 py-1 text-xs font-semibold text-[#7C3AED]"
                    >
                        Chart.js Live
                    </span>
                </div>

                <div class="mt-4 h-64 w-full">
                    <canvas ref="chartCanvas" />
                </div>
            </div>
        </div>

        <!-- Bottom Grid: SSL Status Summary & Recent Server Action Logs -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Monitored Domains SSL Summary -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-[#1E1B4B]">
                            Status Sertifikat SSL Domain
                        </h2>
                        <p class="text-xs text-[#64748B]">
                            Diurutkan berdasarkan sisa masa berlaku terdekat
                        </p>
                    </div>
                    <Link
                        :href="domainsIndex()"
                        class="text-xs font-semibold text-[#7C3AED] hover:text-[#6D28D9]"
                    >
                        Kelola Semua &rarr;
                    </Link>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-[#EDE9FE] text-xs font-semibold text-[#64748B] uppercase"
                            >
                                <th class="pb-2.5">Domain</th>
                                <th class="pb-2.5">Issuer</th>
                                <th class="pb-2.5">Sisa Hari</th>
                                <th class="pb-2.5 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#EDE9FE]/60">
                            <tr
                                v-for="domain in domains"
                                :key="domain.id"
                                class="hover:bg-[#F8F7FC]"
                            >
                                <td class="py-3 font-semibold text-[#1E1B4B]">
                                    {{ domain.domain_name }}
                                    <span class="text-xs font-normal text-[#64748B]">
                                        :{{ domain.port }}
                                    </span>
                                </td>
                                <td class="py-3 text-xs text-[#64748B]">
                                    {{ domain.issuer || 'Belum dipindai' }}
                                </td>
                                <td class="py-3 font-bold">
                                    <span
                                        v-if="domain.days_remaining !== null"
                                        :class="{
                                            'text-emerald-600':
                                                domain.days_remaining > 14,
                                            'text-amber-600':
                                                domain.days_remaining >= 8 &&
                                                domain.days_remaining <= 14,
                                            'text-rose-600':
                                                domain.days_remaining <= 7,
                                        }"
                                    >
                                        {{ domain.days_remaining }} hari
                                    </span>
                                    <span v-else class="text-xs text-rose-500">
                                        N/A
                                    </span>
                                </td>
                                <td class="py-3 text-right">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
                                        :class="{
                                            'bg-emerald-100 text-emerald-700':
                                                domain.status === 'valid',
                                            'bg-amber-100 text-amber-700':
                                                domain.status === 'warning',
                                            'bg-rose-100 text-rose-700':
                                                domain.status === 'critical' ||
                                                domain.status === 'error',
                                        }"
                                    >
                                        <CheckCircle2
                                            v-if="domain.status === 'valid'"
                                            class="size-3"
                                        />
                                        <AlertTriangle
                                            v-else
                                            class="size-3"
                                        />
                                        {{
                                            domain.status === 'valid'
                                                ? 'Aman (>14h)'
                                                : domain.status === 'warning'
                                                  ? 'Warning (8-14h)'
                                                  : 'Kritis (<=7h)'
                                        }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="domains.length === 0">
                                <td
                                    colspan="4"
                                    class="py-6 text-center text-sm text-[#64748B]"
                                >
                                    Belum ada domain yang dipantau.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Audit Logs -->
            <div
                class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-[#1E1B4B]">
                            Riwayat Eksekusi Server Terbaru
                        </h2>
                        <p class="text-xs text-[#64748B]">
                            Audit log perintah PowerShell &amp; win-acme
                        </p>
                    </div>
                    <Link
                        :href="logsIndex()"
                        class="text-xs font-semibold text-[#7C3AED] hover:text-[#6D28D9]"
                    >
                        Lihat Semua Log &rarr;
                    </Link>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="log in recentLogs"
                        :key="log.id"
                        class="flex items-center justify-between rounded-xl border border-[#EDE9FE] bg-[#F8F7FC] p-3.5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-[#1E1B4B]">
                                    #{{ log.id }} — {{ formatActionLabel(log.action_type) }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                    :class="{
                                        'bg-emerald-100 text-emerald-700':
                                            log.status === 'success',
                                        'bg-rose-100 text-rose-700':
                                            log.status === 'failed',
                                        'bg-amber-100 text-amber-700':
                                            log.status === 'running' ||
                                            log.status === 'queued',
                                    }"
                                >
                                    {{ log.status }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-[#64748B]">
                                Oleh {{ log.user?.name || 'System' }} &bull;
                                {{ formatDate(log.created_at) }}
                            </p>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="ml-3 border-[#EDE9FE] bg-white text-xs font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                            @click="selectedLogForOutput = log"
                        >
                            <Terminal class="mr-1 size-3.5" />
                            Output
                        </Button>
                    </div>

                    <div
                        v-if="recentLogs.length === 0"
                        class="py-6 text-center text-sm text-[#64748B]"
                    >
                        Belum ada riwayat aksi server.
                    </div>
                </div>
            </div>
        </div>

        <!-- Dark Console Terminal Output Modal -->
        <div
            v-if="selectedLogForOutput"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs"
            @click.self="selectedLogForOutput = null"
        >
            <div
                class="w-full max-w-2xl overflow-hidden rounded-2xl border border-[#EDE9FE] bg-[#0F0D1D] text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-slate-800 bg-[#18142C] px-5 py-3.5"
                >
                    <div class="flex items-center gap-2.5">
                        <Terminal class="size-4 text-[#A78BFA]" />
                        <span class="text-sm font-bold text-white">
                            Terminal Output — Log #{{ selectedLogForOutput.id }}
                            ({{ formatActionLabel(selectedLogForOutput.action_type) }})
                        </span>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1 text-slate-400 hover:bg-slate-800 hover:text-white"
                        @click="selectedLogForOutput = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <div class="p-5">
                    <pre
                        class="max-h-96 overflow-auto rounded-xl bg-black/70 p-4 font-mono text-xs leading-relaxed text-emerald-400"
                    >{{ selectedLogForOutput.output || 'Tidak ada output tercatat.' }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
