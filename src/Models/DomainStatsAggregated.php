<?php
namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Model;

class DomainStatsAggregated extends Model
{
    protected $table = 'domain_stats_aggregated';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;
    
    protected $fillable = [
        'id',
        'domain',
        'date',
        'incoming_count',
        'spam_count',
        'virus_count',
        'avg_spam_score',
        'max_spam_score',
        'total_size_bytes',
        'spam_percentage',
    ];
    
    protected $casts = [
        'date' => 'date',
        'incoming_count' => 'integer',
        'spam_count' => 'integer',
        'virus_count' => 'integer',
        'avg_spam_score' => 'float',
        'max_spam_score' => 'float',
        'total_size_bytes' => 'integer',
        'spam_percentage' => 'float',
    ];
    
    // Accessor for formatted spam percentage
    public function getFormattedSpamPercentageAttribute(): string
    {
        return round($this->spam_percentage, 2) . '%';
    }
    
    // Accessor for formatted size
    public function getFormattedSizeAttribute(): string
    {
        return $this->formatBytes($this->total_size_bytes);
    }
    
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
