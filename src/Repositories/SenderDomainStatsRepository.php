<?php
namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\SenderDomainStats;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SenderDomainStatsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SenderDomainStatsRepository implements SenderDomainStatsRepositoryInterface
{
    protected $model;

    public function __construct(SenderDomainStats $model)
    {
        $this->model = $model;
    }

    public function findOrCreate(string $senderDomain, string $date): SenderDomainStats
    {
        return $this->model->firstOrNew([
            'sender_domain' => $senderDomain,
            'date' => $date,
        ]);
    }

    public function save($stats): bool
    {
        return $stats->save();
    }

    public function getSenderDomainSummary(string $senderDomain, string $startDate, string $endDate): Collection
    {
        return $this->model
            ->forSenderDomain($senderDomain)
            ->forDateRange($startDate, $endDate)
            ->orderBy('date')
            ->get();
    }

    public function deleteOldStats(string $cutoffDate): int
    {
        return $this->model
            ->where('date', '<', $cutoffDate)
            ->delete();
    }

    public function updateTopRecipientDomains(string $senderDomain, string $date, array $topDomains): bool
    {
        return $this->model
            ->updateOrCreate(
                [
                    'sender_domain' => $senderDomain,
                    'date' => $date,
                ],
                [
                    'top_recipient_domains' => $topDomains,
                ]
            ) ? true : false;
    }
    
    /**
     * Get top spam senders (overload with domain filtering)
     */
    public function getTopSpamSenders(string $startDate, string $endDate, int $limit = 10, ?array $recipientDomains = null): Collection
    {
        $query = $this->model
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'sender_domain',
                DB::raw('SUM(total_emails) as total_emails'),
                DB::raw('SUM(spam_count) as total_spam'),
                DB::raw('SUM(virus_count) as total_virus'),
                DB::raw('MAX(max_spam_score) as highest_score'),
                DB::raw('AVG(avg_spam_score) as average_score')
            )
            ->groupBy('sender_domain')
            ->having('total_spam', '>', 0)
            ->orderBy('total_spam', 'DESC')
            ->limit($limit);

        if ($recipientDomains !== null) {
            $query->where(function ($q) use ($recipientDomains) {
                foreach ($recipientDomains as $domain) {
                    $q->orWhere('top_recipient_domains', 'like', '%' . $domain . '%');
                }
            });
        }

        return $query->get();
    }    
}
