<?php
namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpamRuleScoreSample extends Model
{
    use HasFactory;

    protected $table = 'vw_spam_rule_score_samples';
    public $timestamps = false;

    protected $fillable = [
        'rule_name',
        'score',
        'received_at',
    ];

    protected $casts = [
        'score' => 'float',
        'received_at' => 'datetime',
    ];

    public function scopeForRuleName($query, string $ruleName)
    {
        return $query->where('rule_name', $ruleName);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('received_at', [$startDate, $endDate]);
    }
}