<?php
namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\DomainStats;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\DomainStatsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DomainStatsRepository implements DomainStatsRepositoryInterface
{
    protected $model;

    public function __construct(DomainStats $model)
    {
        $this->model = $model;
    }

    public function findOrCreate(string $domain, string $date, string $action): DomainStats
    {
        return $this->model->firstOrNew([
            'domain' => $domain,
            'date' => $date,
            'action' => $action
        ]);
    }

    public function save($stats): bool
    {
        return $stats->save();
    }

    public function getDomainSummary(string $domain, string $startDate, string $endDate): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->forDateRange($startDate, $endDate)
            ->get();
    }

    /**
     * Get top spam domains (updated to support domain filtering)
     */
    public function getTopSpamDomains(string $startDate, string $endDate, int $limit = 10, ?array $domains = null): Collection
    {
        $query = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'domain',
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('MAX(max_spam_score) as highest_score'),
                DB::raw('AVG(avg_spam_score) as average_score')
            )
            ->groupBy('domain')
            ->having('total_spam', '>', 0)
            ->orderBy('total_spam', 'DESC')
            ->limit($limit);

        if ($domains !== null && !empty($domains)) {
            $query->whereIn('domain', $domains);
        }

        return $query->get();
    }

    public function getDomainDailyTrend(string $domain, string $startDate, string $endDate): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->forDateRange($startDate, $endDate)
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function ($dayStats, $date) {
                return [
                    'date' => $date,
                    'total_incoming' => $dayStats->sum('incoming_count'),
                    'spam_count' => $dayStats->sum('spam_count'),
                    'virus_count' => $dayStats->sum('virus_count'),
                    'avg_spam_score' => $dayStats->avg('avg_spam_score'),
                    'total_size_bytes' => $dayStats->sum('total_size_bytes'),
                ];
            })
            ->values();
    }

    public function deleteOldStats(string $cutoffDate): int
    {
        return $this->model
            ->where('date', '<', $cutoffDate)
            ->delete();
    }

    public function getAggregatedStats(string $startDate, string $endDate): array
    {
        $stats = $this->model
            ->forDateRange($startDate, $endDate)
            ->select(
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score'),
                DB::raw('MAX(max_spam_score) as max_spam_score'),
                DB::raw('SUM(total_size_bytes) as total_size_bytes')
            )
            ->first();

        return [
            'total_incoming' => (int) ($stats->total_incoming ?? 0),
            'total_spam' => (int) ($stats->total_spam ?? 0),
            'total_virus' => (int) ($stats->total_virus ?? 0),
            'avg_spam_score' => round($stats->avg_spam_score ?? 0, 2),
            'max_spam_score' => round($stats->max_spam_score ?? 0, 2),
            'total_size_bytes' => (int) ($stats->total_size_bytes ?? 0),
            'spam_percentage' => $stats->total_incoming > 0 
                ? round(($stats->total_spam / $stats->total_incoming) * 100, 2)
                : 0,
        ];
    }
    
    public function getAggregatedStatsByDateRange(string $startDate, string $endDate, ?string $groupBy = null): array
    {
        $query = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score'),
                DB::raw('MAX(max_spam_score) as max_spam_score'),
                DB::raw('SUM(total_size_bytes) as total_size_bytes')
            );

        if ($groupBy === 'day') {
            $query->addSelect(DB::raw('date as period'))
                  ->groupBy('date')
                  ->orderBy('date');
            $results = $query->get();

            // Convert to array of arrays for consistency
            return $results->map(function ($item) {
                return [
                    'period' => $item->period,
                    'total_incoming' => (int) $item->total_incoming,
                    'total_spam' => (int) $item->total_spam,
                    'total_virus' => (int) $item->total_virus,
                    'avg_spam_score' => round($item->avg_spam_score ?? 0, 2),
                    'max_spam_score' => round($item->max_spam_score ?? 0, 2),
                    'total_size_bytes' => (int) $item->total_size_bytes,
                ];
            })->toArray();
        }

        $stats = $query->first();
        return [
            'total_incoming' => (int) ($stats->total_incoming ?? 0),
            'total_spam' => (int) ($stats->total_spam ?? 0),
            'total_virus' => (int) ($stats->total_virus ?? 0),
            'avg_spam_score' => round($stats->avg_spam_score ?? 0, 2),
            'max_spam_score' => round($stats->max_spam_score ?? 0, 2),
            'total_size_bytes' => (int) ($stats->total_size_bytes ?? 0),
            'spam_percentage' => ($stats->total_incoming ?? 0) > 0 
                ? round((($stats->total_spam ?? 0) / ($stats->total_incoming ?? 0)) * 100, 2)
                : 0,
        ];
    }
    
    public function getActionBreakdown(string $startDate, string $endDate, ?string $domain = null): array
    {
        $query = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'action',
                DB::raw('SUM(incoming_count) as total_count'),
                DB::raw('SUM(spam_count) as spam_count'),
                DB::raw('SUM(virus_count) as virus_count')
            )
            ->groupBy('action')
            ->orderBy('total_count', 'desc');
        
        if ($domain) {
            $query->where('domain', $domain);
        }
        
        $results = $query->get();
        
        $breakdown = [];
        foreach ($results as $result) {
            $breakdown[$result->action] = [
                'total' => (int) $result->total_count,
                'spam' => (int) $result->spam_count,
                'virus' => (int) $result->virus_count,
                'percentage' => 0
            ];
        }
        
        return $breakdown;
    }
    
    public function getTotalStatsForPeriod(string $period): array
    {
        $now = now();
        
        switch ($period) {
            case 'today':
                $startDate = $now->startOfDay()->toDateString();
                $endDate = $now->endOfDay()->toDateString();
                break;
            case 'week':
                $startDate = $now->startOfWeek()->toDateString();
                $endDate = $now->endOfWeek()->toDateString();
                break;
            case 'month':
                $startDate = $now->startOfMonth()->toDateString();
                $endDate = $now->endOfMonth()->toDateString();
                break;
            case 'year':
                $startDate = $now->startOfYear()->toDateString();
                $endDate = $now->endOfYear()->toDateString();
                break;
            default:
                throw new \InvalidArgumentException("Invalid period: {$period}");
        }
        
        return $this->getAggregatedStatsByDateRange($startDate, $endDate);
    }    
    
    /**
     * Get aggregated stats for specific domains
     */
    public function getAggregatedStatsByDateRangeForDomains(string $startDate, string $endDate, array $domains): array
    {
        $stats = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('domain', $domains)
            ->select(
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score'),
                DB::raw('MAX(max_spam_score) as max_spam_score'),
                DB::raw('SUM(total_size_bytes) as total_size_bytes')
            )
            ->first();

        return [
            'total_incoming' => (int) ($stats->total_incoming ?? 0),
            'total_spam' => (int) ($stats->total_spam ?? 0),
            'total_virus' => (int) ($stats->total_virus ?? 0),
            'avg_spam_score' => round($stats->avg_spam_score ?? 0, 2),
            'max_spam_score' => round($stats->max_spam_score ?? 0, 2),
            'total_size_bytes' => (int) ($stats->total_size_bytes ?? 0),
            'spam_percentage' => ($stats->total_incoming ?? 0) > 0 
                ? round((($stats->total_spam ?? 0) / ($stats->total_incoming ?? 0)) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get message counts for specific domains
     */
    public function getMessageCountsByDomains(string $startDate, string $endDate, array $domains): array
    {
        $stats = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('domain', $domains)
            ->select(
                DB::raw('SUM(incoming_count) as total_messages'),
                DB::raw('SUM(spam_count) as spam_messages'),
                DB::raw('SUM(virus_count) as virus_messages')
            )
            ->first();

        return [
            'total_messages' => (int) ($stats->total_messages ?? 0),
            'spam_messages' => (int) ($stats->spam_messages ?? 0),
            'virus_messages' => (int) ($stats->virus_messages ?? 0),
            'clean_messages' => (int) (($stats->total_messages ?? 0) - ($stats->spam_messages ?? 0)),
        ];
    }

    /**
     * Get action breakdown for specific domains
     */
    public function getActionBreakdownForDomains(string $startDate, string $endDate, array $domains): array
    {
        $results = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('domain', $domains)
            ->select(
                'action',
                DB::raw('SUM(incoming_count) as total_count'),
                DB::raw('SUM(spam_count) as spam_count'),
                DB::raw('SUM(virus_count) as virus_count')
            )
            ->groupBy('action')
            ->orderBy('total_count', 'desc')
            ->get();

        $breakdown = [];
        foreach ($results as $result) {
            $breakdown[$result->action] = [
                'total' => (int) $result->total_count,
                'spam' => (int) $result->spam_count,
                'virus' => (int) $result->virus_count,
                'percentage' => 0,
            ];
        }

        return $breakdown;
    }

    /**
     * Get daily trend for specific domains
     */
    public function getDailyTrendForDomains(string $startDate, string $endDate, array $domains): Collection
    {
        return $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('domain', $domains)
            ->select(
                'date',
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get top spam domains (already exists, but add domain filtering)
     */
    public function getTopSpamDomains_old(string $startDate, string $endDate, int $limit = 10, ?array $domains = null): Collection
    {
        $query = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'domain',
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(incoming_count) as total_incoming'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('MAX(max_spam_score) as highest_score'),
                DB::raw('AVG(avg_spam_score) as average_score')
            )
            ->groupBy('domain')
            ->having('total_spam', '>', 0)
            ->orderBy('total_spam', 'DESC')
            ->limit($limit);

        if ($domains !== null) {
            $query->whereIn('domain', $domains);
        }

        return $query->get();
    }    
}
