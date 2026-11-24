<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    RefreshCw,
    Search,
    ShieldCheck,
    Terminal,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { executeServerActionWithPolling } from '@/lib/swal';
import { index as logsIndex } from '@/routes/logs';

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

interface PaginatedLogs {
    data: ActionLogItem[];
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
    total: number;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Audit Log & Terminal Output',
                href: logsIndex(),
            },
        ],
    },
});

const props = defineProps<{
    logs: PaginatedLogs;
    filters: {
        status: string;
        action_type: string;
        search: string;
    };
}>();

const statusFilter = ref(props.filters.status);
const actionFilter = ref(props.filters.action_type);
const searchQuery = ref(props.filters.search);
const selectedLog = ref<ActionLogItem | null>(null);

function applyFilters(): void {
    router.get(
        logsIndex.url(),
        {
            status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
            action_type:
                actionFilter.value !== 'all' ? actionFilter.value : undefined,
            search: searchQuery.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function handleTriggerAction(action: 'reload_webserver' | 'renew_ssl'): void {
    void executeServerActionWithPolling(action, () => {
        router.reload();
    });
}

function formatActionLabel(actionType: string): string {
    return actionType === 'reload_webserver'
        ? 'Refresh Web Server (reload_webserver)'
        : 'Renew SSL win-acme (renew_ssl)';
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
        second: '2-digit',
    });
}
</script>

<template>
    <Head title="Audit Log & Terminal Output" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- Header -->
        <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm lg:flex-row lg:items-center"
        >
            <div>
                <h1 class="text-xl font-bold text-[#1E1B4B]">
                    Audit Log &amp; Terminal Output Viewer
                </h1>
                <p class="text-sm text-[#64748B]">
                    Riwayat lengkap eksekusi perintah sistem yang di-whitelist (<code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 text-xs text-[#7C3AED]">reload_webserver</code> &amp; <code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 text-xs text-[#7C3AED]">renew_ssl</code>).
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="button"
                    class="bg-[#7C3AED] font-semibold text-white hover:bg-[#6D28D9]"
                    @click="handleTriggerAction('reload_webserver')"
                >
                    <RefreshCw class="mr-1.5 size-4" />
                    Refresh Web Server
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="border-[#7C3AED]/30 bg-[#EDE9FE]/50 font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                    @click="handleTriggerAction('renew_ssl')"
                >
                    <ShieldCheck class="mr-1.5 size-4" />
                    Renew SSL (win-acme)
                </Button>
            </div>
        </div>

        <!-- Filter & Table -->
        <div class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm">
            <div
                class="flex flex-col justify-between gap-3 border-b border-[#EDE9FE] pb-4 md:flex-row md:items-center"
            >
                <div class="relative flex-1 md:max-w-xs">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-[#64748B]"
                    />
                    <Input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Cari isi output terminal..."
                        class="border-[#EDE9FE] pl-9"
                        @keyup.enter="applyFilters"
                    />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-model="actionFilter"
                        class="h-9 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] px-3 text-sm font-medium text-[#1E1B4B]"
                        @change="applyFilters"
                    >
                        <option value="all">Semua Jenis Aksi</option>
                        <option value="reload_webserver">
                            Refresh Web Server
                        </option>
                        <option value="renew_ssl">Renew SSL (win-acme)</option>
                    </select>

                    <select
                        v-model="statusFilter"
                        class="h-9 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] px-3 text-sm font-medium text-[#1E1B4B]"
                        @change="applyFilters"
                    >
                        <option value="all">Semua Status</option>
                        <option value="queued">Queued</option>
                        <option value="running">Running</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                    </select>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="border-[#EDE9FE] text-[#7C3AED]"
                        @click="applyFilters"
                    >
                        Filter
                    </Button>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-[#EDE9FE] text-xs font-semibold text-[#64748B] uppercase"
                        >
                            <th class="pb-3">ID</th>
                            <th class="pb-3">Jenis Aksi</th>
                            <th class="pb-3">Admin Eksekutor</th>
                            <th class="pb-3">Waktu Mulai</th>
                            <th class="pb-3">Waktu Selesai</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3 text-right">Terminal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EDE9FE]/70">
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="hover:bg-[#F8F7FC]"
                        >
                            <td class="py-3.5 font-mono text-xs font-bold text-[#7C3AED]">
                                #{{ log.id }}
                            </td>
                            <td class="py-3.5 font-semibold text-[#1E1B4B]">
                                {{ formatActionLabel(log.action_type) }}
                            </td>
                            <td class="py-3.5 text-xs text-[#64748B]">
                                <div class="font-semibold text-[#1E1B4B]">
                                    {{ log.user?.name || 'System / CLI' }}
                                </div>
                                <div>{{ log.user?.email || '-' }}</div>
                            </td>
                            <td class="py-3.5 text-xs text-[#64748B]">
                                {{ formatDate(log.started_at || log.created_at) }}
                            </td>
                            <td class="py-3.5 text-xs text-[#64748B]">
                                {{ formatDate(log.finished_at) }}
                            </td>
                            <td class="py-3.5">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold uppercase"
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
                            </td>
                            <td class="py-3.5 text-right">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="border-[#EDE9FE] bg-[#F8F7FC] text-xs font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                                    @click="selectedLog = log"
                                >
                                    <Terminal class="mr-1.5 size-3.5" />
                                    Lihat Output
                                </Button>
                            </td>
                        </tr>

                        <tr v-if="logs.data.length === 0">
                            <td
                                colspan="7"
                                class="py-10 text-center text-sm text-[#64748B]"
                            >
                                Belum ada log eksekusi yang sesuai dengan filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="logs.links && logs.links.length > 3"
                class="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-[#EDE9FE] pt-4"
            >
                <span class="text-xs text-[#64748B]">
                    Total {{ logs.total }} riwayat aksi
                </span>
                <div class="flex flex-wrap gap-1">
                    <template v-for="(link, idx) in logs.links" :key="idx">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-lg border px-3 py-1 text-xs font-semibold transition-colors"
                            :class="
                                link.active
                                    ? 'border-[#7C3AED] bg-[#7C3AED] text-white'
                                    : 'border-[#EDE9FE] bg-white text-[#1E1B4B] hover:bg-[#EDE9FE]'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                    </template>
                </div>
            </div>
        </div>

        <!-- Console Output Viewer Modal -->
        <div
            v-if="selectedLog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs"
            @click.self="selectedLog = null"
        >
            <div
                class="w-full max-w-3xl overflow-hidden rounded-2xl border border-[#EDE9FE] bg-[#0F0D1D] text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between border-b border-slate-800 bg-[#18142C] px-5 py-4"
                >
                    <div class="flex items-center gap-2.5">
                        <Terminal class="size-4 text-[#A78BFA]" />
                        <div>
                            <h3 class="text-sm font-bold text-white">
                                Console Output — Log #{{ selectedLog.id }} ({{
                                    selectedLog.action_type
                                }})
                            </h3>
                            <p class="text-[11px] text-slate-400">
                                Mulai: {{ formatDate(selectedLog.started_at) }} &bull;
                                Selesai: {{ formatDate(selectedLog.finished_at) }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white"
                        @click="selectedLog = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="p-5">
                    <pre
                        class="max-h-[420px] overflow-auto rounded-xl border border-slate-800 bg-black/80 p-4 font-mono text-xs leading-relaxed text-emerald-400"
                    >{{ selectedLog.output || 'Tidak ada output stdout/stderr.' }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
