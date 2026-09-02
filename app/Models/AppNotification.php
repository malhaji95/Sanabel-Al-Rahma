<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'app_notifications';

    protected $fillable = [
        'channel', 'recipient_id', 'template_key', 'payload_json', 'status', 'sent_at', 'read_at',
    ];

    protected $casts = ['payload_json' => 'array', 'sent_at' => 'datetime', 'read_at' => 'datetime'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
