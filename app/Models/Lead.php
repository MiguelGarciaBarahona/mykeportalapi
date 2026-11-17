<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Solicitud recibida desde el formulario «Hablemos de tu proyecto».
 *
 * @property int $id
 * @property string $public_id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string $email
 * @property string|null $business
 * @property string $service
 * @property string $message
 * @property string|null $source
 * @property bool $whatsapp_consent                                   histórico (WhatsApp ya no se usa)
 * @property \Illuminate\Support\Carbon|null $whatsapp_consent_at   histórico
 * @property string|null $consent_version                            histórico
 * @property LeadStatus $status
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 */
class Lead extends Model
{
    /** @use HasFactory<\Database\Factories\LeadFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'business',
        'service',
        'message',
        'source',
        'status',
        'status_changed_at',
        'assigned_to',
    ];

    /** Nunca se serializan datos internos por accidente; el panel usará API Resources. */
    protected $hidden = ['id', 'assigned_to', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'whatsapp_consent' => 'boolean',
            'whatsapp_consent_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function isAnonymized(): bool
    {
        return $this->getAttribute('anonymized_at') !== null;
    }


    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function serviceLabel(): string
    {
        return (string) (config('mykesystems.services')[$this->service] ?? $this->service);
    }

    /** @return HasMany<LeadStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(LeadStatusChange::class)->orderBy('created_at');
    }

    /** @return HasMany<LeadNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
