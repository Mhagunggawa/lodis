<?php

namespace App\Services;

use App\Models\MonitoredDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SslCheckerService
{
    /**
     * Inspect SSL certificate for a single MonitoredDomain and persist the result.
     */
    public function checkDomain(MonitoredDomain $domain, int $timeoutSeconds = 8): MonitoredDomain
    {
        $info = $this->fetchCertificateInfo($domain->domain_name, $domain->port, $timeoutSeconds);

        $domain->update([
            'issuer' => $info['issuer'],
            'ssl_valid_from' => $info['valid_from'],
            'ssl_expires_at' => $info['expires_at'],
            'days_remaining' => $info['days_remaining'],
            'status' => $info['status'],
            'last_checked_at' => now(),
        ]);

        return $domain->refresh();
    }

    /**
     * Inspect all active monitored domains.
     *
     * @return Collection<int, MonitoredDomain>
     */
    public function checkAllActiveDomains(): Collection
    {
        $domains = MonitoredDomain::query()
            ->where('is_active', true)
            ->orderBy('domain_name')
            ->get();

        foreach ($domains as $domain) {
            $this->checkDomain($domain);
        }

        return $domains;
    }

    /**
     * Fetch raw SSL certificate metadata using stream_socket_client and openssl_x509_parse.
     *
     * @return array{
     *     issuer: string|null,
     *     valid_from: Carbon|null,
     *     expires_at: Carbon|null,
     *     days_remaining: int|null,
     *     status: string,
     *     error: string|null
     * }
     */
    public function fetchCertificateInfo(string $host, int $port = 443, int $timeoutSeconds = 8): array
    {
        $cleanHost = trim((string) preg_replace('#^https?://#i', '', $host), '/');
        $cleanHost = explode('/', $cleanHost)[0] ?? $cleanHost;

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $cleanHost,
            ],
        ]);

        $errorNumber = 0;
        $errorString = '';

        $client = @stream_socket_client(
            "ssl://{$cleanHost}:{$port}",
            $errorNumber,
            $errorString,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return [
                'issuer' => null,
                'valid_from' => null,
                'expires_at' => null,
                'days_remaining' => null,
                'status' => 'error',
                'error' => $errorString !== '' ? $errorString : "Unable to connect to {$cleanHost}:{$port}",
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certResource === null) {
            return [
                'issuer' => null,
                'valid_from' => null,
                'expires_at' => null,
                'days_remaining' => null,
                'status' => 'error',
                'error' => 'No SSL peer certificate returned.',
            ];
        }

        $parsed = openssl_x509_parse($certResource);

        if (! is_array($parsed)) {
            return [
                'issuer' => null,
                'valid_from' => null,
                'expires_at' => null,
                'days_remaining' => null,
                'status' => 'error',
                'error' => 'Failed to parse X.509 certificate.',
            ];
        }

        return $this->parseCertificateArray($parsed);
    }

    /**
     * Parse an openssl_x509_parse array into normalized domain SSL status attributes.
     *
     * @param  array<string, mixed>  $parsed
     * @return array{
     *     issuer: string|null,
     *     valid_from: Carbon|null,
     *     expires_at: Carbon|null,
     *     days_remaining: int|null,
     *     status: string,
     *     error: string|null
     * }
     */
    public function parseCertificateArray(array $parsed): array
    {
        $issuerData = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        $issuerOrganization = $issuerData['O'] ?? null;
        $issuerCommonName = $issuerData['CN'] ?? null;

        if (is_array($issuerOrganization)) {
            $issuerOrganization = implode(', ', $issuerOrganization);
        }

        if (is_array($issuerCommonName)) {
            $issuerCommonName = implode(', ', $issuerCommonName);
        }

        $issuer = trim(implode(' - ', array_filter([$issuerOrganization, $issuerCommonName])));
        if ($issuer === '') {
            $issuer = 'Unknown Issuer';
        }

        $validFromTimestamp = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validToTimestamp = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;

        if ($validToTimestamp === null) {
            return [
                'issuer' => $issuer,
                'valid_from' => null,
                'expires_at' => null,
                'days_remaining' => null,
                'status' => 'error',
                'error' => 'Missing certificate expiration timestamp.',
            ];
        }

        $validFrom = $validFromTimestamp ? Carbon::createFromTimestampUTC($validFromTimestamp)->setTimezone(config('app.timezone')) : null;
        $expiresAt = Carbon::createFromTimestampUTC($validToTimestamp)->setTimezone(config('app.timezone'));

        $secondsDiff = $expiresAt->getTimestamp() - now()->getTimestamp();
        $daysRemaining = (int) floor($secondsDiff / 86400);

        $status = $this->determineStatus($daysRemaining);

        return [
            'issuer' => $issuer,
            'valid_from' => $validFrom,
            'expires_at' => $expiresAt,
            'days_remaining' => $daysRemaining,
            'status' => $status,
            'error' => null,
        ];
    }

    /**
     * Map remaining days to status badge color category:
     * - valid (Green): > 14 days
     * - warning (Amber): 8 - 14 days
     * - critical (Red): <= 7 days or expired
     */
    public function determineStatus(?int $daysRemaining): string
    {
        if ($daysRemaining === null) {
            return 'error';
        }

        if ($daysRemaining > 14) {
            return 'valid';
        }

        if ($daysRemaining >= 8) {
            return 'warning';
        }

        return 'critical';
    }
}
