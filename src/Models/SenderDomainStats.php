<?php

namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SenderDomainStats extends Model
{
    use HasFactory;

    protected $table = 'vw_sender_domain_stats';

    protected $fillable = [
        'sender_domain',
        'date',
        'total_emails',
        'spam_count',
        'virus_count',
        'avg_spam_score',
        'max_spam_score',
        'top_recipient_domains',
    ];

    protected $casts = [
        'date' => 'date',
        'total_emails' => 'integer',
        'spam_count' => 'integer',
        'virus_count' => 'integer',
        'avg_spam_score' => 'float',
        'max_spam_score' => 'float',
        'top_recipient_domains' => 'array',
    ];

    // Scopes
    public function scopeForSenderDomain($query, string $senderDomain)
    {
        return $query->where('sender_domain', $senderDomain);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeTopSpamSenders($query, int $limit = 10)
    {
        return $query->orderBy('spam_count', 'desc')->limit($limit);
    }

    // Helper methods
    public function getSpamPercentageAttribute(): float
    {
        if ($this->total_emails === 0) {
            return 0;
        }
        return round(($this->spam_count / $this->total_emails) * 100, 2);
    }

    public function getTopRecipientDomainsListAttribute(): array
    {
        return $this->top_recipient_domains ?? [];
    }
}
