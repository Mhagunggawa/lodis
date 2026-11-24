<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Lock, Server } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Masuk ke Panel Admin Lodis',
        description:
            'Web Server & SSL Manager untuk Windows Server / Laragon',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Login Administrator" />

    <div
        v-if="status"
        class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-center text-sm font-medium text-emerald-700"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email" class="font-semibold text-[#1E1B4B]">
                    Email Administrator
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="admin@lodis.local"
                    class="border-[#EDE9FE] focus-visible:ring-[#7C3AED]"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password" class="font-semibold text-[#1E1B4B]">
                        Kata Sandi
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs font-medium text-[#7C3AED] hover:text-[#6D28D9]"
                        :tabindex="5"
                    >
                        Lupa kata sandi?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="border-[#EDE9FE] focus-visible:ring-[#7C3AED]"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label
                    for="remember"
                    class="flex items-center space-x-2.5 text-sm text-[#64748B]"
                >
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Ingat sesi saya di perangkat ini</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-2 w-full bg-[#7C3AED] font-semibold text-white shadow-md shadow-[#7C3AED]/20 hover:bg-[#6D28D9]"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                <Lock v-else class="mr-1.5 size-4" />
                Masuk ke Dashboard
            </Button>
        </div>

        <div
            class="mt-2 flex items-center justify-center gap-2 rounded-lg border border-[#EDE9FE] bg-[#F8F7FC] px-3 py-2 text-xs text-[#64748B]"
        >
            <Server class="size-3.5 text-[#7C3AED]" />
            <span>Rate Limit Aktif (Maks 5 percobaan/menit per IP)</span>
        </div>
    </Form>
</template>
