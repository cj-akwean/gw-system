<?php

namespace App\Models;

use Database\Factories\ConnectionLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'service_connection_id', 'status', 'linked_at', 'unlinked_at'])]
class ConnectionLink extends Model
{
    /** @use HasFactory<ConnectionLinkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }
}
