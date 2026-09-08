<?php

namespace App\Models\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermsAcceptance extends Model
{
    protected $table = 'terms_acceptances';

    protected $fillable = [
        'user_id',
        'terms_version_id',
        'role',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'terms_version_id');
    }
}
