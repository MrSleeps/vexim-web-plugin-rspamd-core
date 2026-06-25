<?php
namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpamRuleStat extends Model
{
    use HasFactory;

    protected $table = 'vw_spam_rule_stats';

    protected $fillable = [
        'rule_name',
        'date',
        'hit_count',
    ];

    protected $casts = [
        'date' => 'date',
        'hit_count' => 'integer',
    ];

    public function scopeForRuleName($query, string $ruleName)
    {
        return $query->where('rule_name', $ruleName);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeTopRules($query, int $limit = 10)
    {
        return $query->orderBy('hit_count', 'desc')->limit($limit);
    }
}