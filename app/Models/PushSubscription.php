<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suscripción push de un navegador. Nunca se expone el endpoint completo en respuestas.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property bool $notify_new_leads
 * @property bool $notify_stale_leads
 * @property bool $notify_month_end
 * @property string|null $device
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property int $failures
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'notify_new_leads', 'notify_stale_leads', 'notify_month_end', 'device'];

    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return [
            'notify_new_leads' => 'boolean',
            'notify_stale_leads' => 'boolean',
            'notify_month_end' => 'boolean',
            'last_success_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Solo de administradores activos. @param Builder<PushSubscription> $query */
    public function scopeOfActiveUsers(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $q) => $q->where('is_active', true));
    }

    /** @return array{new_leads: bool, stale_leads: bool, month_end: bool} */
    public function preferences(): array
    {
        return ['new_leads' => $this->notify_new_leads, 'stale_leads' => $this->notify_stale_leads, 'month_end' => $this->notify_month_end];
    }
}
