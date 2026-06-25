<?php
namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\RecipientStats;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\RecipientStatsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecipientStatsRepository implements RecipientStatsRepositoryInterface
{
    protected $model;

    public function __construct(RecipientStats $model)
    {
        $this->model = $model;
    }

    public function findOrCreate(string $recipient, string $date): RecipientStats
    {
        return $this->model->firstOrNew([
            'recipient' => $recipient,
            'date' => $date,
        ]);
    }

    public function save($stats): bool
    {
        return $stats->save();
    }

    public function getRecipientSummary(string $recipient, string $startDate, string $endDate): ?array
    {
        $stats = $this->model
            ->forRecipient($recipient)
            ->forDateRange($startDate, $endDate)
            ->select(
                DB::raw('SUM(total_incoming) as total_incoming'),
                DB::raw('SUM(spam_count) as spam_count'),
                DB::raw('SUM(virus_count) as virus_count'),
                DB::raw('SUM(quarantined_count) as quarantined_count'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score'),
                DB::raw('MAX(max_spam_score) as max_spam_score')
            )
            ->first();

        if (!$stats || $stats->total_incoming == 0) {
            return null;
        }

        return [
            'recipient' => $recipient,
            'domain' => explode('@', $recipient)[1] ?? null,
            'local_part' => explode('@', $recipient)[0] ?? '',
            'total_incoming' => (int) $stats->total_incoming,
            'spam_count' => (int) $stats->spam_count,
            'virus_count' => (int) $stats->virus_count,
            'quarantined_count' => (int) $stats->quarantined_count,
            'avg_spam_score' => round($stats->avg_spam_score ?? 0, 2),
            'max_spam_score' => round($stats->max_spam_score ?? 0, 2),
            'spam_percentage' => round(($stats->spam_count / $stats->total_incoming) * 100, 2),
        ];
    }

    public function getTopTargetedRecipients(string $startDate, string $endDate, int $limit = 10): Collection
    {
        return $this->model
            ->forDateRange($startDate, $endDate)
            ->select(
                'recipient',
                'domain',
                DB::raw('SUM(total_incoming) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('SUM(quarantined_count) as total_quarantined'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score')
            )
            ->groupBy('recipient', 'domain')
            ->having('total_spam', '>', 0)
            ->orderBy('total_spam', 'DESC')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'recipient' => $item->recipient,
                    'domain' => $item->domain,
                    'local_part' => explode('@', $item->recipient)[0] ?? '',
                    'total_incoming' => (int) $item->total_incoming,
                    'total_spam' => (int) $item->total_spam,
                    'total_virus' => (int) $item->total_virus,
                    'total_quarantined' => (int) $item->total_quarantined,
                    'avg_spam_score' => round($item->avg_spam_score ?? 0, 2),
                    'spam_percentage' => $item->total_incoming > 0 
                        ? round(($item->total_spam / $item->total_incoming) * 100, 2)
                        : 0,
                ];
            });
    }

    public function deleteOldStats(string $cutoffDate): int
    {
        return $this->model
            ->where('date', '<', $cutoffDate)
            ->delete();
    }

    public function getRecipientsByDomain(string $domain, string $startDate, string $endDate): Collection
    {
        return $this->model
            ->forDomain($domain)
            ->forDateRange($startDate, $endDate)
            ->select(
                'recipient',
                DB::raw('SUM(total_incoming) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus')
            )
            ->groupBy('recipient')
            ->orderBy('total_spam', 'DESC')
            ->get();
    }
    
    /**
     * Get top targeted recipients for specific domains
     */
    public function getTopTargetedRecipientsForDomains(array $domains, string $startDate, string $endDate, int $limit = 10): Collection
    {
        return $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('domain', $domains)
            ->select(
                'recipient',
                'domain',
                DB::raw('SUM(total_incoming) as total_incoming'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('SUM(quarantined_count) as total_quarantined'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score')
            )
            ->groupBy('recipient', 'domain')
            ->having('total_spam', '>', 0)
            ->orderBy('total_spam', 'DESC')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'recipient' => $item->recipient,
                    'domain' => $item->domain,
                    'local_part' => explode('@', $item->recipient)[0] ?? '',
                    'total_incoming' => (int) $item->total_incoming,
                    'total_spam' => (int) $item->total_spam,
                    'total_virus' => (int) $item->total_virus,
                    'total_quarantined' => (int) $item->total_quarantined,
                    'avg_spam_score' => round($item->avg_spam_score ?? 0, 2),
                    'spam_percentage' => $item->total_incoming > 0 
                        ? round(($item->total_spam / $item->total_incoming) * 100, 2)
                        : 0,
                ];
            });
    }

    /**
     * Get all recipients for specific domains
     */
    public function getRecipientsByDomains(array $domains): array
    {
        return $this->model
            ->whereIn('domain', $domains)
            ->distinct()
            ->pluck('recipient')
            ->toArray();
    }

    /**
     * Get recipient summary for specific domain (overload existing method)
     */
    public function getRecipientSummaryForDomain(string $recipient, string $domain, string $startDate, string $endDate): ?array
    {
        $stats = $this->model
            ->where('recipient', $recipient)
            ->where('domain', $domain)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('SUM(total_incoming) as total_incoming'),
                DB::raw('SUM(spam_count) as spam_count'),
                DB::raw('SUM(virus_count) as virus_count'),
                DB::raw('SUM(quarantined_count) as quarantined_count'),
                DB::raw('AVG(avg_spam_score) as avg_spam_score'),
                DB::raw('MAX(max_spam_score) as max_spam_score')
            )
            ->first();

        if (!$stats || $stats->total_incoming == 0) {
            return null;
        }

        return [
            'recipient' => $recipient,
            'domain' => $domain,
            'local_part' => explode('@', $recipient)[0] ?? '',
            'total_incoming' => (int) $stats->total_incoming,
            'spam_count' => (int) $stats->spam_count,
            'virus_count' => (int) $stats->virus_count,
            'quarantined_count' => (int) $stats->quarantined_count,
            'avg_spam_score' => round($stats->avg_spam_score ?? 0, 2),
            'max_spam_score' => round($stats->max_spam_score ?? 0, 2),
            'spam_percentage' => round(($stats->spam_count / $stats->total_incoming) * 100, 2),
        ];
    }    
}
