<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-gradient-to-br from-[#F8F7FC] via-[#F3E8FF]/60 to-[#EDE9FE] p-6 md:p-10"
    >
        <div
            class="w-full max-w-md rounded-2xl border border-[#EDE9FE] bg-white p-8 shadow-xl shadow-[#7C3AED]/5"
        >
            <div class="flex flex-col gap-6">
                <div class="flex flex-col items-center gap-3">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#7C3AED] text-white shadow-md shadow-[#7C3AED]/30"
                        >
                            <ShieldCheck class="size-7 text-white" />
                        </div>
                        <span class="text-xs font-bold tracking-widest text-[#7C3AED] uppercase">
                            LODIS CONTROL PANEL
                        </span>
                    </Link>
                    <div class="space-y-1.5 text-center">
                        <h1 class="text-xl font-bold text-[#1E1B4B]">
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm text-[#64748B]">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
