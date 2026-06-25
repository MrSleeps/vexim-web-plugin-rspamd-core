<?php

namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainStats extends Model
{
    use HasFactory;

    protected $table = 'vw_domain_stats';

    protected $fillable = [
        'domain',
        'date',
        'action',
        'incoming_count',
        'spam_count',
        'virus_count',
        'avg_spam_score',
        'max_spam_score',
        'total_size_bytes',
    ];

    protected $casts = [
        'date' => 'date',
        'incoming_count' => 'integer',
        'spam_count' => 'integer',
        'virus_count' => 'integer',
        'avg_spam_score' => 'float',
        'max_spam_score' => 'float',
        'total_size_bytes' => 'integer',
    ];

    // Scopes for common queries
    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeWithSpam($query)
    {
        return $query->where('spam_count', '>', 0);
    }

    // Helper methods
    public function getVirusPercentageAttribute(): float
    {
        if ($this->incoming_count === 0) {
            return 0;
        }
        return round(($this->virus_count / $this->incoming_count) * 100, 2);
    }
    
    public function getSpamPercentageAttribute(): float
    {
        if ($this->incoming_count > 0) {
            return round(($this->spam_count / $this->incoming_count) * 100, 2);
        }
        return 0;
    }
    
    public $timestamps = false;    
}
