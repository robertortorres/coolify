<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ApplicationShare extends Model
{
    protected $fillable = [
        'application_id',
        'user_id',
        'team_id',
        'permission',
        'granted_by',
    ];

    protected $attributes = [
        'permission' => 'read',
    ];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'user_id' => 'integer',
            'team_id' => 'integer',
            'granted_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ApplicationShare $share): void {
            if (($share->user_id === null) === ($share->team_id === null)) {
                throw ValidationException::withMessages([
                    'recipient' => 'Choose exactly one recipient: a user or a team.',
                ]);
            }

            if (! in_array($share->permission, ['read', 'operate'], true)) {
                throw ValidationException::withMessages([
                    'permission' => 'Choose read or operate access.',
                ]);
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
