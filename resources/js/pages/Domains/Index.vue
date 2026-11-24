<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Edit3,
    GlobeLock,
    Plus,
    RefreshCw,
    ScanSearch,
    Search,
    ShieldCheck,
    Trash2,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmAction, executeServerActionWithPolling } from '@/lib/swal';
import {
    destroy as destroyDomain,
    index as domainsIndex,
    scan as scanDomain,
    scanAll,
    store as storeDomain,
    update as updateDomain,
} from '@/routes/domains';

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
    last_reminded_at: string | null;
    is_active: boolean;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Monitor Domain & SSL',
                href: domainsIndex(),
            },
        ],
    },
});

const props = defineProps<{
    domains: DomainItem[];
    filters: {
        search: string;
        status: string;
    };
}>();

const searchQuery = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const isScanningAll = ref(false);
const scanningDomainId = ref<number | null>(null);

const showModal = ref(false);
const editingDomain = ref<DomainItem | null>(null);

const form = useForm({
    domain_name: '',
    port: 443,
    is_active: true,
});

function openCreateModal(): void {
    editingDomain.value = null;
    form.reset();
    form.clearErrors();
    form.port = 443;
    form.is_active = true;
    showModal.value = true;
}

function openEditModal(domain: DomainItem): void {
    editingDomain.value = domain;
    form.clearErrors();
    form.domain_name = domain.domain_name;
    form.port = domain.port;
    form.is_active = domain.is_active;
    showModal.value = true;
}

function submitDomainForm(): void {
    if (editingDomain.value) {
        form.put(updateDomain.url(editingDomain.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
            },
        });
    } else {
        form.post(storeDomain.url(), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
                form.reset();
            },
        });
    }
}

async function handleDeleteDomain(domain: DomainItem): Promise<void> {
    const confirmed = await confirmAction({
        title: `Hapus Domain ${domain.domain_name}?`,
        text: 'Domain ini akan dihapus dari daftar pemantauan SSL otomatis.',
        confirmButtonText: 'Ya, Hapus Domain',
        icon: 'warning',
        isDanger: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyDomain.url(domain.id), {
        preserveScroll: true,
    });
}

function handleScanSingle(domain: DomainItem): void {
    scanningDomainId.value = domain.id;
    router.post(
        scanDomain.url(domain.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                scanningDomainId.value = null;
            },
        },
    );
}

function handleScanAll(): void {
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

function handleRenewSsl(): void {
    void executeServerActionWithPolling('renew_ssl', () => {
        router.reload();
    });
}

function applyFilters(): void {
    router.get(
        domainsIndex.url(),
        {
            search: searchQuery.value || undefined,
            status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
        return '-';
    }
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Monitor Domain & SSL" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- Top Header -->
        <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm lg:flex-row lg:items-center"
        >
            <div>
                <h1 class="text-xl font-bold text-[#1E1B4B]">
                    Monitor Sertifikat SSL &amp; Pembaruan win-acme
                </h1>
                <p class="text-sm text-[#64748B]">
                    Inspeksi sertifikat X.509 secara langsung menggunakan PHP OpenSSL (<code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 text-xs text-[#7C3AED]">stream_socket_client</code> &amp; <code class="rounded bg-[#EDE9FE] px-1.5 py-0.5 text-xs text-[#7C3AED]">openssl_x509_parse</code>).
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="button"
                    class="bg-[#7C3AED] font-semibold text-white shadow-sm hover:bg-[#6D28D9]"
                    @click="openCreateModal"
                >
                    <Plus class="mr-1.5 size-4" />
                    Tambah Domain
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="border-[#7C3AED]/30 bg-[#EDE9FE]/50 font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                    @click="handleRenewSsl"
                >
                    <ShieldCheck class="mr-1.5 size-4" />
                    Renew SSL (win-acme)
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="border-[#EDE9FE] bg-white font-semibold text-[#1E1B4B] hover:bg-[#F8F7FC]"
                    :disabled="isScanningAll"
                    @click="handleScanAll"
                >
                    <ScanSearch
                        class="mr-1.5 size-4 text-[#7C3AED]"
                        :class="{ 'animate-spin': isScanningAll }"
                    />
                    {{ isScanningAll ? 'Memindai...' : 'Scan Ulang Semua' }}
                </Button>
            </div>
        </div>

        <!-- Filter & Table Card -->
        <div class="rounded-2xl border border-[#EDE9FE] bg-white p-5 shadow-sm">
            <div
                class="flex flex-col justify-between gap-3 border-b border-[#EDE9FE] pb-4 sm:flex-row sm:items-center"
            >
                <div class="relative flex-1 sm:max-w-xs">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-[#64748B]"
                    />
                    <Input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Cari nama domain atau issuer..."
                        class="border-[#EDE9FE] pl-9"
                        @keyup.enter="applyFilters"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <select
                        v-model="statusFilter"
                        class="h-9 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] px-3 text-sm font-medium text-[#1E1B4B] focus:border-[#7C3AED] focus:outline-none"
                        @change="applyFilters"
                    >
                        <option value="all">Semua Status SSL</option>
                        <option value="valid">Hijau — Aman (&gt; 14 Hari)</option>
                        <option value="warning">Kuning — Warning (8–14 Hari)</option>
                        <option value="critical">Merah — Kritis (&le; 7 Hari)</option>
                        <option value="error">Error / Gagal Koneksi</option>
                    </select>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="border-[#EDE9FE] text-[#7C3AED]"
                        @click="applyFilters"
                    >
                        Terapkan
                    </Button>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-[#EDE9FE] text-xs font-semibold text-[#64748B] uppercase"
                        >
                            <th class="pb-3">Domain &amp; Port</th>
                            <th class="pb-3">Penerbit (Issuer)</th>
                            <th class="pb-3">Masa Berlaku</th>
                            <th class="pb-3">Sisa Hari</th>
                            <th class="pb-3">Status SSL</th>
                            <th class="pb-3">Pantauan</th>
                            <th class="pb-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EDE9FE]/70">
                        <tr
                            v-for="domain in domains"
                            :key="domain.id"
                            class="hover:bg-[#F8F7FC]"
                        >
                            <td class="py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div
                                        class="flex size-8 items-center justify-center rounded-lg bg-[#EDE9FE] text-[#7C3AED]"
                                    >
                                        <GlobeLock class="size-4" />
                                    </div>
                                    <div>
                                        <div class="font-bold text-[#1E1B4B]">
                                            {{ domain.domain_name }}
                                        </div>
                                        <div class="text-xs text-[#64748B]">
                                            Port {{ domain.port }}
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3.5 text-xs font-medium text-[#1E1B4B]">
                                {{ domain.issuer || 'Belum terdeteksi' }}
                            </td>

                            <td class="py-3.5 text-xs text-[#64748B]">
                                <div>
                                    Mulai: {{ formatDate(domain.ssl_valid_from) }}
                                </div>
                                <div class="font-semibold text-[#1E1B4B]">
                                    Exp: {{ formatDate(domain.ssl_expires_at) }}
                                </div>
                            </td>

                            <td class="py-3.5">
                                <span
                                    v-if="domain.days_remaining !== null"
                                    class="text-sm font-extrabold"
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
                                    {{ domain.days_remaining }} Hari
                                </span>
                                <span
                                    v-else
                                    class="text-xs font-semibold text-rose-500"
                                >
                                    Tidak Valid
                                </span>
                            </td>

                            <td class="py-3.5">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
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
                                        class="size-3.5"
                                    />
                                    <AlertTriangle v-else class="size-3.5" />
                                    {{
                                        domain.status === 'valid'
                                            ? 'Valid (> 14 Hari)'
                                            : domain.status === 'warning'
                                              ? 'Warning (8-14 Hari)'
                                              : domain.status === 'critical'
                                                ? 'Kritis (<= 7 Hari)'
                                                : 'Error Koneksi'
                                    }}
                                </span>
                            </td>

                            <td class="py-3.5">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="
                                        domain.is_active
                                            ? 'bg-[#EDE9FE] text-[#7C3AED]'
                                            : 'bg-slate-100 text-slate-500'
                                    "
                                >
                                    {{ domain.is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>

                            <td class="py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="border-[#EDE9FE] text-xs font-semibold text-[#7C3AED] hover:bg-[#EDE9FE]"
                                        :disabled="scanningDomainId === domain.id"
                                        @click="handleScanSingle(domain)"
                                    >
                                        <RefreshCw
                                            class="mr-1 size-3.5"
                                            :class="{
                                                'animate-spin':
                                                    scanningDomainId === domain.id,
                                            }"
                                        />
                                        Scan
                                    </Button>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="border-[#EDE9FE] text-xs text-[#1E1B4B] hover:bg-[#F8F7FC]"
                                        @click="openEditModal(domain)"
                                    >
                                        <Edit3 class="size-3.5" />
                                    </Button>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="border-rose-200 text-xs text-rose-600 hover:bg-rose-50"
                                        @click="handleDeleteDomain(domain)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="domains.length === 0">
                            <td
                                colspan="7"
                                class="py-10 text-center text-sm text-[#64748B]"
                            >
                                Tidak ada domain yang ditemukan. Klik
                                <strong>Tambah Domain</strong> untuk mulai memantau sertifikat SSL.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add / Edit Domain Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs"
            @click.self="showModal = false"
        >
            <div
                class="w-full max-w-md rounded-2xl border border-[#EDE9FE] bg-white p-6 shadow-2xl"
            >
                <div class="flex items-center justify-between border-b border-[#EDE9FE] pb-3">
                    <h3 class="text-lg font-bold text-[#1E1B4B]">
                        {{
                            editingDomain
                                ? 'Ubah Domain Terpantau'
                                : 'Tambah Domain Baru'
                        }}
                    </h3>
                    <button
                        type="button"
                        class="rounded-lg p-1 text-[#64748B] hover:bg-[#F8F7FC]"
                        @click="showModal = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submitDomainForm">
                    <div class="grid gap-1.5">
                        <Label for="domain_name" class="font-semibold text-[#1E1B4B]">
                            Nama Domain (Tanpa https://)
                        </Label>
                        <Input
                            id="domain_name"
                            v-model="form.domain_name"
                            type="text"
                            placeholder="portal.instansi.go.id"
                            required
                            class="border-[#EDE9FE]"
                        />
                        <InputError :message="form.errors.domain_name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="port" class="font-semibold text-[#1E1B4B]">
                            Port SSL
                        </Label>
                        <Input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            required
                            class="border-[#EDE9FE]"
                        />
                        <InputError :message="form.errors.port" />
                    </div>

                    <div class="flex items-center gap-2.5 pt-1">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 rounded border-[#EDE9FE] text-[#7C3AED] focus:ring-[#7C3AED]"
                        />
                        <Label for="is_active" class="text-sm font-medium text-[#1E1B4B]">
                            Aktifkan pemantauan &amp; reminder otomatis
                        </Label>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2.5 border-t border-[#EDE9FE] pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            class="border-[#EDE9FE]"
                            @click="showModal = false"
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#7C3AED] font-semibold text-white hover:bg-[#6D28D9]"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Menyimpan & Memindai...'
                                    : 'Simpan Domain'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
