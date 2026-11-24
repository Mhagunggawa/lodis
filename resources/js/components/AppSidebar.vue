<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    GlobeLock,
    LayoutDashboard,
    ScrollText,
    Server,
    SlidersHorizontal,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as domainsIndex } from '@/routes/domains';
import { index as lodisSettingsIndex } from '@/routes/lodis-settings';
import { index as logsIndex } from '@/routes/logs';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard Server',
        href: dashboard(),
        icon: LayoutDashboard,
    },
    {
        title: 'Monitor Domain & SSL',
        href: domainsIndex(),
        icon: GlobeLock,
    },
    {
        title: 'Audit Log & Terminal',
        href: logsIndex(),
        icon: ScrollText,
    },
    {
        title: 'Pengaturan & Reminder',
        href: lodisSettingsIndex(),
        icon: SlidersHorizontal,
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        class="border-r border-[#EDE9FE] bg-white"
    >
        <SidebarHeader class="border-b border-[#EDE9FE]/70 pb-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="pt-2">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-[#EDE9FE]/70 pt-2">
            <div
                class="mx-2 mb-2 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] p-2.5 text-xs group-data-[collapsible=icon]:hidden"
            >
                <div class="flex items-center gap-2 font-semibold text-[#1E1B4B]">
                    <Server class="size-3.5 text-[#7C3AED]" />
                    <span>Single-Server Mode</span>
                </div>
                <p class="mt-0.5 text-[11px] text-[#64748B]">
                    Target: Laragon Windows Server
                </p>
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
