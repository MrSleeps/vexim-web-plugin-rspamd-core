<?php
namespace VEximweb\Plugin\RSpamd\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailScoreSample extends Model
{
    use HasFactory;

    protected $table = 'vw_email_score_samples';
    public $timestamps = false;

    protected $fillable = [
        'action',
        'score',
        'required_score',
        'has_virus',
        'received_at',
    ];

    protected $casts = [
        'score' => 'float',
        'required_score' => 'float',
        'has_virus' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('received_at', [$startDate, $endDate]);
    }

    public function scopeWithVirus($query, bool $hasVirus = true)
    {
        return $query->where('has_virus', $hasVirus);
    }
}
