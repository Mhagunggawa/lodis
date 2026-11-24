<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $chat_id
 * @property int|null $user_id
 * @property string $state
 * @property string|null $temp_email
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'chat_id',
    'user_id',
    'state',
    'temp_email',
    'failed_attempts',
    'locked_until',
    'authenticated_at',
    'expires_at',
])]
class TelegramSession extends Model
{
    public const STATE_IDLE = 'idle';

    public const STATE_AWAITING_EMAIL = 'awaiting_email';

    public const STATE_AWAITING_PASSWORD = 'awaiting_password';

    public const STATE_AUTHENTICATED = 'authenticated';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'authenticated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Check if the session is currently locked due to too many failed login attempts.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Check if the session is currently authenticated and within its 24-hour validity window.
     */
    public function isAuthenticated(): bool
    {
        return $this->state === self::STATE_AUTHENTICATED
            && $this->user_id !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Get the authenticated user associated with this Telegram session.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
