<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
</script>

<template>
    <div
        class="flex aspect-square size-9 items-center justify-center rounded-lg bg-[#7C3AED] text-white shadow-sm shadow-[#7C3AED]/30"
    >
        <ShieldCheck class="size-5 text-white" />
    </div>
    <div class="ml-1.5 grid flex-1 text-left text-sm">
        <span class="truncate font-bold tracking-tight text-[#1E1B4B] dark:text-white">
            Lodis
        </span>
        <span class="truncate text-[11px] font-medium text-[#64748B]">
            Server &amp; SSL Manager
        </span>
    </div>
</template>
