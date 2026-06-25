<?php

namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuarantinedEmail extends Model
{
    use HasFactory;

    protected $table = 'quarantined_emails';

    protected $fillable = [
        'queue_id',
        'message_id',
        'from_address',
        'to_address',
        'subject',
        'action',
        'spam_score',
        'required_score',
        'symbols',
        'ip_address',
        'helo',
        'raw_content',
        'received_at',
    ];

    protected $casts = [
        'symbols' => 'array',
        'spam_score' => 'float',
        'required_score' => 'float',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Scope for rejected emails
    public function scopeRejected($query)
    {
        return $query->whereIn('action', ['reject', 'discard']);
    }

    // Scope for high spam score
    public function scopeHighSpam($query, $threshold = 10)
    {
        return $query->where('spam_score', '>=', $threshold);
    }
}
