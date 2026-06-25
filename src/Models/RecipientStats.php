<?php

namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecipientStats extends Model
{
    use HasFactory;

    protected $table = 'vw_recipient_stats';

    protected $fillable = [
        'recipient',
        'domain',
        'date',
        'total_incoming',
        'spam_count',
        'virus_count',
        'avg_spam_score',
        'max_spam_score',
        'quarantined_count',
    ];

    protected $casts = [
        'date' => 'date',
        'total_incoming' => 'integer',
        'spam_count' => 'integer',
        'virus_count' => 'integer',
        'avg_spam_score' => 'float',
        'max_spam_score' => 'float',
        'quarantined_count' => 'integer',
    ];

    // Scopes
    public function scopeForRecipient($query, string $recipient)
    {
        return $query->where('recipient', $recipient);
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeTopSpamRecipients($query, int $limit = 10)
    {
        return $query->orderBy('spam_count', 'desc')->limit($limit);
    }

    public function getSpamPercentageAttribute(): float
    {
        if ($this->total_incoming === 0) {
            return 0;
        }
        return round(($this->spam_count / $this->total_incoming) * 100, 2);
    }

    public function getLocalPartAttribute(): string
    {
        return explode('@', $this->recipient)[0] ?? '';
    }
}
