<?php

namespace App\Mail;

use App\Models\MonitoredDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SslExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public MonitoredDomain $domain,
        public bool $isTest = false,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $prefix = $this->isTest ? '[TES NOTIFIKASI]' : '[PERINGATAN SSL]';

        return new Envelope(
            subject: "{$prefix} Lodis — Sertifikat SSL {$this->domain->domain_name} ({$this->domain->days_remaining} Hari Tersisa)",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtmlContent(),
        );
    }

    /**
     * Build clean purple-and-white themed HTML email body.
     */
    protected function buildHtmlContent(): string
    {
        $domainName = e($this->domain->domain_name);
        $port = (int) $this->domain->port;
        $days = (int) ($this->domain->days_remaining ?? 0);
        $issuer = e((string) ($this->domain->issuer ?? 'Unknown Issuer'));
        $expiresAt = e($this->domain->ssl_expires_at?->format('d M Y H:i') ?? 'N/A');
        $badgeColor = $days <= 7 ? '#EF4444' : '#F59E0B';
        $title = $this->isTest ? 'Uji Coba Pengingat SSL Lodis' : 'Peringatan Masa Berlaku Sertifikat SSL';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:24px;background-color:#F8F7FC;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1E1B4B;">
            <div style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #EDE9FE;border-radius:12px;overflow:hidden;">
                <div style="background:#7C3AED;color:#FFFFFF;padding:20px 24px;">
                    <h2 style="margin:0;font-size:18px;">Lodis — Web Server &amp; SSL Manager</h2>
                    <p style="margin:4px 0 0;font-size:13px;opacity:0.9;">{$title}</p>
                </div>
                <div style="padding:24px;">
                    <p style="margin:0 0 16px;font-size:14px;color:#475569;">
                        Sistem pemantauan Lodis mendeteksi sertifikat SSL berikut memerlukan perhatian:
                    </p>
                    <table style="width:100%;border-collapse:collapse;font-size:14px;">
                        <tr>
                            <td style="padding:8px 0;color:#64748B;">Domain &amp; Port</td>
                            <td style="padding:8px 0;font-weight:600;color:#1E1B4B;">{$domainName}:{$port}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0;color:#64748B;">Sisa Masa Aktif</td>
                            <td style="padding:8px 0;font-weight:700;color:{$badgeColor};">{$days} Hari</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0;color:#64748B;">Tanggal Kedaluwarsa</td>
                            <td style="padding:8px 0;color:#1E1B4B;">{$expiresAt}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0;color:#64748B;">Issuer</td>
                            <td style="padding:8px 0;color:#1E1B4B;">{$issuer}</td>
                        </tr>
                    </table>
                    <div style="margin-top:20px;padding:12px 16px;background:#F8F7FC;border-left:4px solid #7C3AED;border-radius:6px;font-size:13px;color:#475569;">
                        Silakan buka dashboard <strong>Lodis</strong> pada server Windows Laragon Anda dan jalankan aksi <strong>Renew SSL (win-acme)</strong>.
                    </div>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
