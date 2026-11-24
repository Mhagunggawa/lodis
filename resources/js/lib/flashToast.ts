import { router } from '@inertiajs/vue3';
import { showToast } from '@/lib/swal';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('finish', (event) => {
        const page = (event as CustomEvent).detail?.page ?? router.page;
        const flash = page?.props?.flash as
            | {
                  success?: string;
                  error?: string;
                  toast?: FlashToast;
              }
            | undefined;

        if (!flash) {
            return;
        }

        if (flash.success) {
            showToast(flash.success, 'success');
        } else if (flash.error) {
            showToast(flash.error, 'error');
        } else if (flash.toast) {
            showToast(
                flash.toast.message,
                flash.toast.type === 'error' ? 'error' : 'success',
            );
        }
    });
}
