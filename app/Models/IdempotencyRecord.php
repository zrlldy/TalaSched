<?php

namespace App\Models;

use Database\Factories\IdempotencyRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'actor_user_id', 'route', 'key_hash', 'request_hash', 'status', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyRecord extends Model
{
    public const string Completed = 'completed';

    public const string Processing = 'processing';

    /** @use HasFactory<IdempotencyRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
