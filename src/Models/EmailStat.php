<?php
namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailStat extends Model
{
    use HasFactory;

    protected $table = 'vw_email_stats';

    protected $fillable = [
        'hour',
        'action',
        'count',
        'has_virus',
    ];

    protected $casts = [
        'hour' => 'datetime',
        'count' => 'integer',
        'has_virus' => 'boolean',
    ];

    public function scopeForHour($query, string $hour)
    {
        return $query->where('hour', $hour);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('hour', [$startDate, $endDate]);
    }

    public function scopeWithVirus($query, bool $hasVirus = true)
    {
        return $query->where('has_virus', $hasVirus);
    }
}