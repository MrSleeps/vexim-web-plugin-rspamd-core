<?php

namespace VEximweb\Plugin\RSpamd\Core\Services;

use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\DomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\RecipientStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SenderDomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailStatRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailSpamStatsService
{
    protected $domainStatsRepository;
    protected $recipientStatsRepository;
    protected $senderDomainStatsRepository;
    protected $emailStatRepository;

    public function __construct(
        DomainStatsRepositoryInterface $domainStatsRepository,
        RecipientStatsRepositoryInterface $recipientStatsRepository,
        SenderDomainStatsRepositoryInterface $senderDomainStatsRepository,
        EmailStatRepositoryInterface $emailStatRepository
    ) {
        $this->domainStatsRepository = $domainStatsRepository;
        $this->recipientStatsRepository = $recipientStatsRepository;
        $this->senderDomainStatsRepository = $senderDomainStatsRepository;
        $this->emailStatRepository = $emailStatRepository;
    }

    /**
     * Map Rspamd actions to your ENUM values
     */
    private function mapActionToEnum(string $action, bool $hasVirus): string
    {
        if ($hasVirus) {
            return 'quarantine';
        }
        
        return match($action) {
            'reject' => 'reject',
            'discard' => 'discard',
            'quarantine' => 'quarantine',
            'no action', 'accept', 'pass', 'deliver' => 'deliver',
            'greylist' => 'greylist',
            'rewrite subject' => 'rewrite subject',
            default => 'deliver'
        };
    }
    
    /**
     * Extract domain from email address
     */
    private function extractDomainFromEmail(?string $email): ?string
    {
        if (empty($email) || !is_string($email)) {
            return null;
        }
        
        if (str_contains($email, '@')) {
            $parts = explode('@', $email);
            return isset($parts[1]) ? strtolower(trim($parts[1])) : null;
        }
        
        return null;
    }

    /**
     * Extract local part from email address
     */
    private function extractLocalPart(?string $email): ?string
    {
        if (empty($email) || !is_string($email)) {
            return null;
        }
        
        if (str_contains($email, '@')) {
            $parts = explode('@', $email);
            return isset($parts[0]) ? strtolower(trim($parts[0])) : null;
        }
        
        return null;
    }

    /**
     * Determine if action is spam
     */
    private function isSpam(string $action, bool $hasVirus): bool
    {
        return in_array($action, ['reject', 'discard']) || $hasVirus;
    }

    /**
     * Update domain statistics
     */
    public function updateDomainStats(
        string $domain,
        string $action,
        float $score,
        bool $hasVirus,
        int $size
    ): void {
        try {
            $today = now()->toDateString();
            $isSpam = $this->isSpam($action, $hasVirus);
            $mappedAction = $this->mapActionToEnum($action, $hasVirus);
            
            $stats = $this->domainStatsRepository->findOrCreate($domain, $today, $mappedAction);
            
            $oldIncomingCount = $stats->incoming_count;
            $newIncomingCount = $oldIncomingCount + 1;
            
            $oldAvgScore = $stats->avg_spam_score;
            $newAvgScore = $oldIncomingCount > 0 
                ? (($oldAvgScore * $oldIncomingCount) + $score) / $newIncomingCount
                : $score;
            
            $stats->incoming_count = $newIncomingCount;
            $stats->spam_count = $stats->spam_count + ($isSpam ? 1 : 0);
            $stats->virus_count = $stats->virus_count + ($hasVirus ? 1 : 0);
            $stats->avg_spam_score = $newAvgScore;
            $stats->max_spam_score = max($stats->max_spam_score, $score);
            $stats->total_size_bytes = $stats->total_size_bytes + $size;
            $stats->updated_at = now();
            
            if (!$stats->exists) {
                $stats->created_at = now();
            }
            
            $this->domainStatsRepository->save($stats);
            
            Log::debug('Updated domain stats', [
                'domain' => $domain,
                'action' => $mappedAction,
                'is_spam' => $isSpam,
                'score' => $score,
                'new_count' => $newIncomingCount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update domain stats', [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'action' => $action,
                'has_virus' => $hasVirus
            ]);
        }
    }

    /**
     * Update recipient statistics
     */
    public function updateRecipientStats(
        string $recipient,
        string $domain,
        string $action,
        float $score,
        bool $hasVirus
    ): void {
        try {
            $today = now()->toDateString();
            $isSpam = $this->isSpam($action, $hasVirus);
            $isQuarantined = in_array($action, ['reject', 'discard']) || $hasVirus;
            
            $stats = $this->recipientStatsRepository->findOrCreate($recipient, $today);
            
            if (!$stats->exists) {
                $stats->domain = $domain;
            }
            
            $oldTotalIncoming = $stats->total_incoming;
            $newTotalIncoming = $oldTotalIncoming + 1;
            
            $oldAvgScore = $stats->avg_spam_score;
            $newAvgScore = $oldTotalIncoming > 0 
                ? (($oldAvgScore * $oldTotalIncoming) + $score) / $newTotalIncoming
                : $score;
            
            $stats->total_incoming = $newTotalIncoming;
            $stats->spam_count = $stats->spam_count + ($isSpam ? 1 : 0);
            $stats->virus_count = $stats->virus_count + ($hasVirus ? 1 : 0);
            $stats->avg_spam_score = $newAvgScore;
            $stats->max_spam_score = max($stats->max_spam_score, $score);
            $stats->quarantined_count = $stats->quarantined_count + ($isQuarantined ? 1 : 0);
            $stats->updated_at = now();
            
            if (!$stats->exists) {
                $stats->created_at = now();
            }
            
            $this->recipientStatsRepository->save($stats);
            
            Log::debug('Updated recipient stats', [
                'recipient' => $recipient,
                'domain' => $domain,
                'is_spam' => $isSpam,
                'new_total' => $newTotalIncoming
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update recipient stats', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update sender domain statistics (spam sources)
     */
    public function updateSenderDomainStats(
        ?string $senderDomain,
        string $action,
        float $score,
        bool $hasVirus,
        array $recipients
    ): void {
        $isSpam = $this->isSpam($action, $hasVirus);
        
        if (!$senderDomain || !$isSpam) {
            return;
        }
        
        try {
            $today = now()->toDateString();
            
            $stats = $this->senderDomainStatsRepository->findOrCreate($senderDomain, $today);
            
            $oldTotalEmails = $stats->total_emails;
            $newTotalEmails = $oldTotalEmails + 1;
            
            $oldAvgScore = $stats->avg_spam_score;
            $newAvgScore = $oldTotalEmails > 0 
                ? (($oldAvgScore * $oldTotalEmails) + $score) / $newTotalEmails
                : $score;
            
            $topRecipientDomains = $this->getTopRecipientDomains($recipients);
            
            $stats->total_emails = $newTotalEmails;
            $stats->spam_count = $stats->spam_count + 1;
            $stats->virus_count = $stats->virus_count + ($hasVirus ? 1 : 0);
            $stats->avg_spam_score = $newAvgScore;
            $stats->max_spam_score = max($stats->max_spam_score, $score);
            $stats->top_recipient_domains = $topRecipientDomains;
            $stats->updated_at = now();
            
            if (!$stats->exists) {
                $stats->created_at = now();
            }
            
            $this->senderDomainStatsRepository->save($stats);
            
            Log::debug('Updated sender domain stats', [
                'sender_domain' => $senderDomain,
                'score' => $score,
                'recipients_count' => count($recipients),
                'new_total' => $newTotalEmails
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update sender domain stats', [
                'sender_domain' => $senderDomain,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get top recipient domains for sender analytics
     */
    private function getTopRecipientDomains(array $recipients): array
    {
        $domains = [];
        
        foreach ($recipients as $recipient) {
            $domain = $this->extractDomainFromEmail($recipient);
            if ($domain) {
                $domains[] = $domain;
            }
        }
        
        $domainCounts = array_count_values($domains);
        arsort($domainCounts);
        
        return array_slice(array_keys($domainCounts), 0, 5);
    }

    /**
     * Update all stats for an email
     */
    public function updateAllStats(
        string $action,
        float $score,
        bool $hasVirus,
        ?string $senderEmail,
        array $recipients,
        int $size
    ): void {
        try {
            DB::beginTransaction();
            
            $senderDomain = $this->extractDomainFromEmail($senderEmail);
            
            foreach ($recipients as $recipient) {
                $recipientDomain = $this->extractDomainFromEmail($recipient);
                
                if (!$recipientDomain) {
                    continue;
                }
                
                $this->updateDomainStats(
                    $recipientDomain,
                    $action,
                    $score,
                    $hasVirus,
                    $size
                );
                
                $this->updateRecipientStats(
                    $recipient,
                    $recipientDomain,
                    $action,
                    $score,
                    $hasVirus
                );
            }
            
            $this->updateSenderDomainStats(
                $senderDomain,
                $action,
                $score,
                $hasVirus,
                $recipients
            );
            
            DB::commit();
            
            Log::info('All email stats updated successfully', [
                'action' => $action,
                'recipients_count' => count($recipients),
                'sender_domain' => $senderDomain
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update all stats', [
                'error' => $e->getMessage(),
                'action' => $action
            ]);
            throw $e;
        }
    }

    /**
     * Get statistics summary for a domain
     */
    public function getDomainSummary(string $domain, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        $stats = $this->domainStatsRepository->getDomainSummary($domain, $startDate, $endDate);
        
        return [
            'domain' => $domain,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'total_incoming' => $stats->sum('incoming_count'),
            'total_spam' => $stats->sum('spam_count'),
            'total_virus' => $stats->sum('virus_count'),
            'avg_spam_score' => $stats->avg('avg_spam_score'),
            'max_spam_score' => $stats->max('max_spam_score'),
            'total_size_bytes' => $stats->sum('total_size_bytes'),
            'breakdown_by_action' => $stats->groupBy('action')->map(function ($group) {
                return [
                    'count' => $group->sum('incoming_count'),
                    'spam_count' => $group->sum('spam_count')
                ];
            }),
        ];
    }

    /**
     * Get statistics summary for a recipient
     */
    public function getRecipientSummary(string $recipient, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        $summary = $this->recipientStatsRepository->getRecipientSummary($recipient, $startDate, $endDate);
        
        if (!$summary) {
            return [
                'recipient' => $recipient,
                'domain' => $this->extractDomainFromEmail($recipient),
                'local_part' => $this->extractLocalPart($recipient),
                'total_incoming' => 0,
                'spam_count' => 0,
                'virus_count' => 0,
                'quarantined_count' => 0,
                'avg_spam_score' => 0,
                'max_spam_score' => 0,
                'spam_percentage' => 0
            ];
        }
        
        return $summary;
    }

    /**
     * Get top spam domains
     */
    public function getTopSpamDomains(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        return $this->domainStatsRepository
            ->getTopSpamDomains($startDate, $endDate, $limit)
            ->toArray();
    }

    /**
     * Get top spam senders
     */
    public function getTopSpamSenders(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        return $this->senderDomainStatsRepository
            ->getTopSpamSenders($startDate, $endDate, $limit)
            ->toArray();
    }

    /**
     * Get top targeted recipients
     */
    public function getTopTargetedRecipients(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        return $this->recipientStatsRepository
            ->getTopTargetedRecipients($startDate, $endDate, $limit)
            ->toArray();
    }

    /**
     * Get daily trend for a domain
     */
    public function getDomainDailyTrend(string $domain, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        
        return $this->domainStatsRepository
            ->getDomainDailyTrend($domain, $startDate, $endDate)
            ->toArray();
    }

    /**
     * Clean up old stats
     */
    public function cleanupOldStats(int $retentionDays = 365): void
    {
        $cutoffDate = now()->subDays($retentionDays)->toDateString();
        
        $deletedDomain = $this->domainStatsRepository->deleteOldStats($cutoffDate);
        $deletedRecipient = $this->recipientStatsRepository->deleteOldStats($cutoffDate);
        $deletedSender = $this->senderDomainStatsRepository->deleteOldStats($cutoffDate);
        
        Log::info('Cleaned up old stats', [
            'retention_days' => $retentionDays,
            'deleted_domain_stats' => $deletedDomain,
            'deleted_recipient_stats' => $deletedRecipient,
            'deleted_sender_stats' => $deletedSender
        ]);
    }
    
    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(): array
    {
        return [
            'domain_stats' => [
                'today' => $this->getDomainStatsForPeriod('today'),
                'week' => $this->getDomainStatsForPeriod('week'),
                'month' => $this->getDomainStatsForPeriod('month'),
                'year' => $this->getDomainStatsForPeriod('year'),
            ],
            'email_stats' => [
                'today' => $this->getEmailStatsForPeriod('today'),
                'week' => $this->getEmailStatsForPeriod('week'),
                'month' => $this->getEmailStatsForPeriod('month'),
                'year' => $this->getEmailStatsForPeriod('year'),
            ],
            'message_stats' => $this->getMessageStats(),
            'top_spam_domains' => $this->getTopSpamDomains(5),
            'top_spam_senders' => $this->getTopSpamSenders(5),
            'top_targeted_recipients' => $this->getTopTargetedRecipients(5),
            'action_breakdown' => $this->getActionBreakdown(),
            'daily_trend' => $this->getLast30DaysTrend(),
            'hourly_distribution' => $this->getTodayHourlyDistribution(),
            'message_action_summary' => $this->getMessageActionSummary(),
        ];
    }
    
    /**
     * Get domain statistics for a specific period (from domain_stats table)
     */
    public function getDomainStatsForPeriod(string $period): array
    {
        $now = now();

        switch ($period) {
            case 'today':
                $startDate = $now->startOfDay()->toDateString();
                $endDate = $now->endOfDay()->toDateString();
                $label = 'Today';
                break;
            case 'week':
                $startDate = $now->startOfWeek()->toDateString();
                $endDate = $now->endOfWeek()->toDateString();
                $label = 'This Week';
                break;
            case 'month':
                $startDate = $now->startOfMonth()->toDateString();
                $endDate = $now->endOfMonth()->toDateString();
                $label = 'This Month';
                break;
            case 'year':
                $startDate = $now->startOfYear()->toDateString();
                $endDate = $now->endOfYear()->toDateString();
                $label = 'This Year';
                break;
            default:
                throw new \InvalidArgumentException("Invalid period: {$period}");
        }

        $stats = $this->domainStatsRepository->getAggregatedStatsByDateRange($startDate, $endDate);
        $stats['period'] = $label;
        $stats['date_range'] = [
            'start' => $startDate,
            'end' => $endDate
        ];

        // Add formatted size
        $stats['total_size_formatted'] = $this->formatBytes($stats['total_size_bytes']);

        return $stats;
    }

    /**
     * Get email message statistics for a specific period (from email_stats table)
     */
    public function getEmailStatsForPeriod(string $period): array
    {
        $now = now();

        switch ($period) {
            case 'today':
                $startDate = $now->startOfDay()->toDateTimeString();
                $endDate = $now->endOfDay()->toDateTimeString();
                $label = 'Today';
                break;
            case 'week':
                $startDate = $now->startOfWeek()->toDateTimeString();
                $endDate = $now->endOfWeek()->toDateTimeString();
                $label = 'This Week';
                break;
            case 'month':
                $startDate = $now->startOfMonth()->toDateTimeString();
                $endDate = $now->endOfMonth()->toDateTimeString();
                $label = 'This Month';
                break;
            case 'year':
                $startDate = $now->startOfYear()->toDateTimeString();
                $endDate = $now->endOfYear()->toDateTimeString();
                $label = 'This Year';
                break;
            default:
                throw new \InvalidArgumentException("Invalid period: {$period}");
        }

        $stats = $this->emailStatRepository->getMessageCountsByPeriod($startDate, $endDate);
        $stats['period'] = $label;
        $stats['date_range'] = [
            'start' => $startDate,
            'end' => $endDate
        ];

        return $stats;
    }
    
    /**
     * Get action breakdown for default period (last 30 days)
     */
    public function getActionBreakdown(?string $domain = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        $breakdown = $this->domainStatsRepository->getActionBreakdown($startDate, $endDate, $domain);
        
        // Calculate percentages
        $total = array_sum(array_column($breakdown, 'total'));
        
        foreach ($breakdown as $action => &$data) {
            $data['percentage'] = $total > 0 ? round(($data['total'] / $total) * 100, 2) : 0;
        }
        
        return $breakdown;
    }
    
    /**
     * Get detailed spam statistics with multiple breakdowns
     */
    public function getDetailedSpamStats(?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        $stats = $this->domainStatsRepository->getAggregatedStatsByDateRange($startDate, $endDate);
        $actionBreakdown = $this->getActionBreakdown(null, $startDate, $endDate);
        $dailyStats = $this->domainStatsRepository->getAggregatedStatsByDateRange($startDate, $endDate, 'day');
        
        return [
            'summary' => $stats,
            'action_breakdown' => $actionBreakdown,
            'daily_stats' => $dailyStats,
            'top_spam_domains' => $this->getTopSpamDomains(10, $startDate, $endDate),
            'top_spam_senders' => $this->getTopSpamSenders(10, $startDate, $endDate),
            'top_targeted_recipients' => $this->getTopTargetedRecipients(10, $startDate, $endDate),
            'spam_trend' => $this->getSpamTrend($startDate, $endDate),
        ];
    }
    
/**
 * Get spam trend data (spam vs ham over time)
 */
public function getSpamTrend(string $startDate, string $endDate): array
{
    $dailyStats = $this->domainStatsRepository->getAggregatedStatsByDateRange($startDate, $endDate, 'day');
    
    $trend = [];
    foreach ($dailyStats as $day) {
        // Handle both object and array formats
        if (is_array($day)) {
            $trend[] = [
                'date' => $day['period'] ?? $day['date'] ?? '',
                'total' => (int) ($day['total_incoming'] ?? 0),
                'spam' => (int) ($day['total_spam'] ?? 0),
                'virus' => (int) ($day['total_virus'] ?? 0),
                'clean' => (int) (($day['total_incoming'] ?? 0) - ($day['total_spam'] ?? 0)),
                'spam_rate' => ($day['total_incoming'] ?? 0) > 0 
                    ? round((($day['total_spam'] ?? 0) / ($day['total_incoming'] ?? 0)) * 100, 2)
                    : 0,
            ];
        } else {
            // It's an object
            $trend[] = [
                'date' => $day->period ?? $day->date ?? '',
                'total' => (int) ($day->total_incoming ?? 0),
                'spam' => (int) ($day->total_spam ?? 0),
                'virus' => (int) ($day->total_virus ?? 0),
                'clean' => (int) (($day->total_incoming ?? 0) - ($day->total_spam ?? 0)),
                'spam_rate' => ($day->total_incoming ?? 0) > 0 
                    ? round((($day->total_spam ?? 0) / ($day->total_incoming ?? 0)) * 100, 2)
                    : 0,
            ];
        }
    }
    
    return $trend;
}
    
    /**
     * Get last 30 days trend for charts
     */
    public function getLast30DaysTrend(): array
    {
        $startDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();
        
        return $this->getSpamTrend($startDate, $endDate);
    }
    
    /**
     * Get recipient health score (how much spam they receive)
     */
    public function getRecipientHealthScore(string $recipient, ?int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        
        $summary = $this->getRecipientSummary($recipient, $startDate, $endDate);
        
        // Calculate health score (0-100, higher is better)
        $spamRate = $summary['spam_percentage'] ?? 0;
        $healthScore = max(0, 100 - ($spamRate * 2)); // 50% spam = 0 score
        
        return [
            'recipient' => $recipient,
            'health_score' => round($healthScore, 2),
            'risk_level' => $this->getRiskLevel($spamRate),
            'spam_rate' => $spamRate,
            'total_emails' => $summary['total_incoming'],
            'spam_count' => $summary['spam_count'],
            'virus_count' => $summary['virus_count'],
            'recommendation' => $this->getRecommendation($spamRate),
        ];
    }
    
    /**
     * Get domain health score
     */
    public function getDomainHealthScore(string $domain, ?int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        
        $summary = $this->getDomainSummary($domain, $startDate, $endDate);
        $spamRate = $summary['total_incoming'] > 0 
            ? ($summary['total_spam'] / $summary['total_incoming']) * 100 
            : 0;
        
        $healthScore = max(0, 100 - ($spamRate * 2));
        
        return [
            'domain' => $domain,
            'health_score' => round($healthScore, 2),
            'risk_level' => $this->getRiskLevel($spamRate),
            'spam_rate' => round($spamRate, 2),
            'total_incoming' => $summary['total_incoming'],
            'total_spam' => $summary['total_spam'],
            'total_virus' => $summary['total_virus'],
            'recommendation' => $this->getRecommendation($spamRate),
        ];
    }
    
    /**
     * Get global statistics summary for dashboard
     */
    public function getGlobalStats(): array
    {
        $emailToday = $this->getEmailStatsForPeriod('today');
        $emailWeek = $this->getEmailStatsForPeriod('week');
        $emailMonth = $this->getEmailStatsForPeriod('month');

        $domainToday = $this->getDomainStatsForPeriod('today');
        $domainWeek = $this->getDomainStatsForPeriod('week');
        $domainMonth = $this->getDomainStatsForPeriod('month');

        // Calculate trends
        $spamTrend = $this->calculateTrend($domainWeek['total_spam'], $domainToday['total_spam']);
        $volumeTrend = $this->calculateTrend($emailWeek['total_messages'], $emailToday['total_messages']);

        return [
            'current' => [
                'today' => [
                    'messages' => $emailToday,
                    'spam' => $domainToday,
                ],
                'week' => [
                    'messages' => $emailWeek,
                    'spam' => $domainWeek,
                ],
                'month' => [
                    'messages' => $emailMonth,
                    'spam' => $domainMonth,
                ],
            ],
            'trends' => [
                'spam' => $spamTrend,
                'volume' => $volumeTrend,
            ],
            'alerts' => $this->getAlerts(),
        ];
    }
    
    /**
     * Get spammer ranking (top spam sources)
     */
    public function getSpammerRanking(int $limit = 20, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();
        
        $senders = $this->senderDomainStatsRepository->getTopSpamSenders($startDate, $endDate, $limit);
        $domains = $this->domainStatsRepository->getTopSpamDomains($startDate, $endDate, $limit);
        
        return [
            'top_spam_senders' => $senders->toArray(),
            'top_spam_targets' => $domains->toArray(),
            'summary' => [
                'total_spam_senders' => count($senders),
                'total_spam_targets' => count($domains),
                'report_date' => now()->toDateString(),
            ],
        ];
    }
    
    /**
     * Get message statistics from email_stats table
     */
    public function getMessageStats(): array
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(7)->toDateString();
        $monthAgo = now()->subDays(30)->toDateString();
        
        return [
            'today' => $this->emailStatRepository->getMessageCountsByPeriod(
                $today . ' 00:00:00',
                $today . ' 23:59:59'
            ),
            'this_week' => $this->emailStatRepository->getMessageCountsByPeriod(
                $weekAgo . ' 00:00:00',
                now()->toDateString() . ' 23:59:59'
            ),
            'this_month' => $this->emailStatRepository->getMessageCountsByPeriod(
                $monthAgo . ' 00:00:00',
                now()->toDateString() . ' 23:59:59'
            ),
            'daily_average' => $this->getDailyMessageAverage(),
            'peak_hour' => $this->getPeakMessageHour(),
        ];
    }
    
    /**
     * Get daily average message count
     */
    public function getDailyMessageAverage(int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString() . ' 00:00:00';
        $endDate = now()->toDateString() . ' 23:59:59';
        
        $dailyStats = $this->emailStatRepository->getMessageCountsByPeriod($startDate, $endDate, 'day');
        
        if (empty($dailyStats)) {
            return ['average' => 0, 'total' => 0, 'days' => 0];
        }
        
        $totalMessages = array_sum(array_column($dailyStats, 'total_messages'));
        $daysCount = count($dailyStats);
        
        return [
            'average' => round($totalMessages / $daysCount, 1),
            'total' => $totalMessages,
            'days' => $daysCount,
            'trend' => $this->calculateMessageTrend($dailyStats),
        ];
    }
    
    /**
     * Get peak message hour based on historical data
     */
    public function getPeakMessageHour(int $days = 7): array
    {
        $startDate = now()->subDays($days)->toDateString() . ' 00:00:00';
        $endDate = now()->toDateString() . ' 23:59:59';
        
        $hourlyData = [];
        for ($i = 0; $i < 24; $i++) {
            $hourlyData[$i] = 0;
        }
        
        // Get hourly distribution for each day
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->toDateString();
            $distribution = $this->emailStatRepository->getHourlyDistribution($date);
            
            foreach ($distribution as $hour) {
                $hourlyData[$hour['hour']] += $hour['total'];
            }
        }
        
        $peakHour = array_keys($hourlyData, max($hourlyData))[0];
        $peakCount = $hourlyData[$peakHour];
        $totalMessages = array_sum($hourlyData);
        
        return [
            'peak_hour' => $peakHour,
            'peak_hour_formatted' => date('g:00 A', mktime($peakHour, 0, 0)),
            'peak_count' => $peakCount,
            'average_per_hour' => $totalMessages > 0 ? round($totalMessages / 24, 1) : 0,
            'hourly_distribution' => $hourlyData,
        ];
    }
    
    /**
     * Get today's hourly message distribution
     */
    public function getTodayHourlyDistribution(): array
    {
        $today = now()->toDateString();
        
        return $this->emailStatRepository->getHourlyDistribution($today);
    }
    
    /**
     * Get message action summary (what happened to messages)
     */
    public function getMessageActionSummary(?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30)->toDateString() . ' 00:00:00';
        $endDate = $endDate ?? now()->toDateString() . ' 23:59:59';
        
        $summary = $this->emailStatRepository->getActionSummary($startDate, $endDate);
        
        // Add color coding and icons for dashboard
        $actionTypes = [];
        foreach ($summary['actions'] as $action => $data) {
            $actionTypes[$action] = [
                'count' => $data['count'],
                'virus_count' => $data['virus_count'],
                'percentage' => $data['percentage'],
                'icon' => $this->getActionIcon($action),
                'color' => $this->getActionColor($action),
            ];
        }
        
        return [
            'total_messages' => $summary['total_messages'],
            'actions' => $actionTypes,
            'top_action' => !empty($actionTypes) ? array_key_first($actionTypes) : null,
        ];
    }
    
    /**
     * Get formatted stats for the stats overview widget
     */
    public function getQuickStats(): array
    {
        $emailToday = $this->getEmailStatsForPeriod('today');
        $domainToday = $this->getDomainStatsForPeriod('today');
        $actionSummary = $this->getMessageActionSummary(
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString()
        );

        return [
            'messages_today' => [
                'total' => $emailToday['total_messages'],
                'with_virus' => $emailToday['virus_messages'],
                'clean' => $emailToday['clean_messages'],
            ],
            'spam_stats' => [
                'total_spam' => $domainToday['total_spam'],
                'spam_rate' => $domainToday['spam_percentage'],
                'avg_score' => $domainToday['avg_spam_score'],
            ],
            'actions_today' => $actionSummary['actions'],
            'delivery_rate' => $this->calculateDeliveryRate($actionSummary),
        ];
    }
    
    // Helper methods
    
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    private function getRiskLevel(float $spamRate): string
    {
        if ($spamRate < 5) return 'Low';
        if ($spamRate < 15) return 'Moderate';
        if ($spamRate < 30) return 'High';
        return 'Critical';
    }
    
    private function getRecommendation(float $spamRate): string
    {
        if ($spamRate < 5) {
            return 'Excellent - Continue current security measures';
        }
        if ($spamRate < 15) {
            return 'Good - Consider reviewing spam filters';
        }
        if ($spamRate < 30) {
            return 'Warning - Immediate attention recommended';
        }
        return 'Critical - Security review required immediately';
    }
    
    private function calculateTrend(int $previous, int $current): array
    {
        if ($previous === 0) {
            return ['percentage' => 0, 'direction' => 'stable'];
        }
        
        $percentage = round((($current - $previous) / $previous) * 100, 2);
        $direction = $percentage > 0 ? 'up' : ($percentage < 0 ? 'down' : 'stable');
        
        return [
            'percentage' => abs($percentage),
            'direction' => $direction,
        ];
    }
    
    private function getAlerts(): array
    {
        $alerts = [];

        // Check for unusual spam activity using domain stats
        $weekDomainStats = $this->getDomainStatsForPeriod('week');
        $monthDomainStats = $this->getDomainStatsForPeriod('month');

        $weeklyAverage = $weekDomainStats['total_spam'] / 7;
        $monthlyAverage = $monthDomainStats['total_spam'] / 30;

        if ($weeklyAverage > $monthlyAverage * 1.5) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'Unusual spam activity detected this week',
                'details' => "Weekly average ({$weeklyAverage}) is 50% higher than monthly average ({$monthlyAverage})",
            ];
        }

        // Check message volume
        $todayMessages = $this->getEmailStatsForPeriod('today');
        if ($todayMessages['total_messages'] > 1000) {
            $alerts[] = [
                'level' => 'info',
                'message' => 'High message volume detected',
                'details' => "{$todayMessages['total_messages']} messages processed today",
            ];
        }

        // Check top spam domains
        $topSpam = $this->getTopSpamDomains(1);
        if (!empty($topSpam) && $topSpam[0]['total_spam'] > 100) {
            $alerts[] = [
                'level' => 'info',
                'message' => 'High spam volume detected',
                'details' => "{$topSpam[0]['domain']} received {$topSpam[0]['total_spam']} spam emails",
            ];
        }

        return $alerts;
    }
    
    private function calculateMessageTrend(array $dailyStats): array
    {
        if (count($dailyStats) < 2) {
            return ['direction' => 'stable', 'percentage' => 0];
        }
        
        $recent = array_slice($dailyStats, -7);
        $previous = array_slice($dailyStats, -14, 7);
        
        $recentAvg = array_sum(array_column($recent, 'total_messages')) / count($recent);
        $previousAvg = array_sum(array_column($previous, 'total_messages')) / count($previous);
        
        if ($previousAvg == 0) {
            return ['direction' => 'stable', 'percentage' => 0];
        }
        
        $change = (($recentAvg - $previousAvg) / $previousAvg) * 100;
        
        return [
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
            'percentage' => round(abs($change), 1),
        ];
    }
    
    private function calculateDeliveryRate(array $actionSummary): float
    {
        $delivered = $actionSummary['actions']['deliver']['count'] ?? 0;
        $total = $actionSummary['total_messages'];
        
        return $total > 0 ? round(($delivered / $total) * 100, 2) : 0;
    }
    
    private function getActionIcon(string $action): string
    {
        return match($action) {
            'deliver' => 'heroicon-o-check-circle',
            'reject' => 'heroicon-o-x-circle',
            'quarantine' => 'heroicon-o-shield-exclamation',
            'greylist' => 'heroicon-o-clock',
            'discard' => 'heroicon-o-trash',
            default => 'heroicon-o-envelope',
        };
    }
    
    private function getActionColor(string $action): string
    {
        return match($action) {
            'deliver' => 'success',
            'reject' => 'danger',
            'quarantine' => 'warning',
            'greylist' => 'info',
            'discard' => 'secondary',
            default => 'primary',
        };
    }
}