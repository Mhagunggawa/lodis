import Swal from 'sweetalert2';
import type { SweetAlertIcon } from 'sweetalert2';

/**
 * Show a SweetAlert2 toast notification in the top-right corner (3 seconds).
 */
export function showToast(
    title: string,
    icon: SweetAlertIcon = 'success',
): void {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        background: '#FFFFFF',
        color: '#1E1B4B',
        didOpen: (toast) => {
            toast.onmouseenter = Swal.stopTimer;
            toast.onmouseleave = Swal.resumeTimer;
        },
    });

    void Toast.fire({
        icon,
        title,
    });
}

/**
 * Show a SweetAlert2 confirmation dialog styled with Lodis Purple & White theme (#7C3AED).
 */
export async function confirmAction(options: {
    title: string;
    text: string;
    confirmButtonText?: string;
    cancelButtonText?: string;
    icon?: SweetAlertIcon;
    isDanger?: boolean;
}): Promise<boolean> {
    const result = await Swal.fire({
        title: options.title,
        text: options.text,
        icon: options.icon ?? 'warning',
        showCancelButton: true,
        confirmButtonColor: options.isDanger ? '#EF4444' : '#7C3AED',
        cancelButtonColor: '#E2E8F0',
        confirmButtonText: options.confirmButtonText ?? 'Ya, Lanjutkan',
        cancelButtonText: options.cancelButtonText ?? 'Batal',
        background: '#FFFFFF',
        color: '#1E1B4B',
        reverseButtons: true,
        didOpen: () => {
            const cancelBtn = Swal.getCancelButton();
            if (cancelBtn) {
                cancelBtn.style.color = '#1E1B4B';
                cancelBtn.style.fontWeight = '600';
            }
        },
    });

    return Boolean(result.isConfirmed);
}

/**
 * Read CSRF token from XSRF-TOKEN cookie for fetch calls.
 */
function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Trigger an asynchronous server action (reload_webserver or renew_ssl)
 * with 3-second grace period and automatic health-check / status polling via SweetAlert2.
 */
export async function executeServerActionWithPolling(
    action: 'reload_webserver' | 'renew_ssl',
    onCompleted?: () => void,
): Promise<void> {
    const isReload = action === 'reload_webserver';

    const confirmed = await confirmAction({
        title: isReload
            ? 'Refresh Web Server (Laragon)?'
            : 'Renew Sertifikat SSL (win-acme)?',
        text: isReload
            ? 'Sistem akan menjadwalkan jeda 3 detik sebelum me-reload layanan web server Laragon agar koneksi browser Anda tetap aman.'
            : 'Sistem akan menjalankan win-acme (wacs.exe --renew) di latar belakang dan otomatis memindai ulang seluruh domain aktif.',
        confirmButtonText: isReload
            ? 'Ya, Refresh Web Server'
            : 'Ya, Jalankan Renew SSL',
        icon: 'question',
    });

    if (!confirmed) {
        return;
    }

    void Swal.fire({
        title: isReload
            ? 'Me-refresh Web Server...'
            : 'Memperbarui Sertifikat SSL...',
        html: '<p id="lodis-swal-status" style="color:#64748B;font-size:14px;margin-top:8px;">Menjadwalkan eksekusi asinkron ke PowerShell Runner...</p>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        background: '#FFFFFF',
        color: '#1E1B4B',
        didOpen: () => {
            Swal.showLoading();
        },
    });

    const updateModalMessage = (msg: string) => {
        const el = document.getElementById('lodis-swal-status');
        if (el) {
            el.innerHTML = msg;
        }
    };

    try {
        const response = await fetch('/server-actions/trigger', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify({ action }),
        });

        if (response.status !== 202 && !response.ok) {
            const errData = await response.json().catch(() => ({}));
            throw new Error(
                errData.message || 'Gagal memicu aksi pada server.',
            );
        }

        const data = (await response.json()) as { log_id: number };
        const logId = data.log_id;

        updateModalMessage(
            'Menunggu jeda eksekusi 3 detik (HTTP 202 Accepted diterima)...',
        );

        await new Promise((resolve) => setTimeout(resolve, 3200));

        updateModalMessage(
            isReload
                ? 'Menghubungkan ulang ke server (/api/health)...'
                : 'Memantau proses win-acme & pemindaian ulang SSL...',
        );

        let attempts = 0;
        const maxAttempts = 20;
        let finished = false;
        let finalStatus = 'success';

        while (attempts < maxAttempts && !finished) {
            attempts++;

            try {
                const healthRes = await fetch('/api/health', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (healthRes.ok) {
                    const logRes = await fetch(
                        `/server-actions/${logId}/status`,
                        {
                            headers: { Accept: 'application/json' },
                            cache: 'no-store',
                        },
                    );

                    if (logRes.ok) {
                        const logData = (await logRes.json()) as {
                            status: string;
                        };
                        if (
                            logData.status === 'success' ||
                            logData.status === 'failed'
                        ) {
                            finished = true;
                            finalStatus = logData.status;
                            break;
                        }
                    }

                    if (isReload && attempts >= 2) {
                        finished = true;
                        break;
                    }
                }
            } catch {
                updateModalMessage(
                    `Menghubungkan ulang ke server... (Percobaan ${attempts})`,
                );
            }

            await new Promise((resolve) => setTimeout(resolve, 2000));
        }

        Swal.close();

        if (finalStatus === 'failed') {
            await Swal.fire({
                icon: 'error',
                title: 'Eksekusi Mengalami Kendala',
                text: 'Proses selesai dengan status gagal. Silakan periksa detail output pada halaman Audit Log.',
                confirmButtonColor: '#7C3AED',
            });
        } else {
            await Swal.fire({
                icon: 'success',
                title: isReload
                    ? 'Web Server Berhasil Di-refresh!'
                    : 'Proses Renew SSL Selesai!',
                text: isReload
                    ? 'Layanan web server Laragon telah merespon kembali dengan status 200 OK.'
                    : 'Eksekusi win-acme telah tercatat di Audit Log dan sertifikat domain telah diperbarui.',
                confirmButtonColor: '#7C3AED',
                timer: 2800,
            });
        }

        if (onCompleted) {
            onCompleted();
        }
    } catch (error) {
        Swal.close();
        await Swal.fire({
            icon: 'error',
            title: 'Gagal Menjalankan Aksi',
            text:
                error instanceof Error
                    ? error.message
                    : 'Terjadi kesalahan jaringan.',
            confirmButtonColor: '#7C3AED',
        });
    }
}
