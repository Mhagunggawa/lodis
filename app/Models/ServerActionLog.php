<?php

namespace App\Models;

use Database\Factories\ServerActionLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $action_type
 * @property string $status
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'action_type',
    'status',
    'output',
    'started_at',
    'finished_at',
])]
class ServerActionLog extends Model
{
    /** @use HasFactory<ServerActionLogFactory> */
    use HasFactory;

    public const ACTION_RELOAD_WEBSERVER = 'reload_webserver';

    public const ACTION_RENEW_SSL = 'renew_ssl';

    /**
     * Allowed action keys for strict command whitelisting.
     *
     * @return array<int, string>
     */
    public static function allowedActions(): array
    {
        return [
            self::ACTION_RELOAD_WEBSERVER,
            self::ACTION_RENEW_SSL,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the admin user who triggered this action.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
