<?php

namespace App\Models;

use Database\Factories\MonitoredDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain_name
 * @property int $port
 * @property string|null $issuer
 * @property Carbon|null $ssl_valid_from
 * @property Carbon|null $ssl_expires_at
 * @property int|null $days_remaining
 * @property string $status
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_reminded_at
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'domain_name',
    'port',
    'issuer',
    'ssl_valid_from',
    'ssl_expires_at',
    'days_remaining',
    'status',
    'last_checked_at',
    'last_reminded_at',
    'is_active',
])]
class MonitoredDomain extends Model
{
    /** @use HasFactory<MonitoredDomainFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'ssl_valid_from' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'days_remaining' => 'integer',
            'last_checked_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
