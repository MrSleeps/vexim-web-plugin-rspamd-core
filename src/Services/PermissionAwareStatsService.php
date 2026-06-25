<?php

namespace VEximweb\Plugin\RSpamd\Core\Services;

use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\DomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\RecipientStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SenderDomainStatsRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailStatRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use VEximweb\Plugin\RSpamd\Core\Services\EmailSpamStatsService;

class PermissionAwareStatsService
{
    protected $emailSpamStatsService;
    protected $domainStatsRepository;
    protected $recipientStatsRepository;
    protected $senderDomainStatsRepository;
    protected $emailStatRepository;

    public function __construct(
        EmailSpamStatsService $emailSpamStatsService,
        DomainStatsRepositoryInterface $domainStatsRepository,
        RecipientStatsRepositoryInterface $recipientStatsRepository,
        SenderDomainStatsRepositoryInterface $senderDomainStatsRepository,
        EmailStatRepositoryInterface $emailStatRepository
    ) {
        $this->emailSpamStatsService = $emailSpamStatsService;
        $this->domainStatsRepository = $domainStatsRepository;
        $this->recipientStatsRepository = $recipientStatsRepository;
        $this->senderDomainStatsRepository = $senderDomainStatsRepository;
        $this->emailStatRepository = $emailStatRepository;
    }

    /**
     * Get domains accessible to the current user based on their role
     */
    public function getAccessibleDomains(): ?array
    {
        $user = Auth::user();
        
        if (!$user) {
            return [];
        }
        
        // System admin sees everything
        if ($user->hasRole('system_admin')) {
            return null; // null means all domains
        }
        
        // Domain admin sees their assigned domains
        if ($user->hasRole('domain_admin')) {
            // Assuming you have a relationship between users and domains
            // Adjust this based on your actual relationship
            return $user->domains()->pluck('domain')->toArray();
        }
        
        // Domain user only sees their own email domain
        if ($user->hasRole('domain_user')) {
            $emailDomain = $this->extractDomainFromEmail($user->email);
            return $emailDomain ? [$emailDomain] : [];
        }
        
        return [];
    }

    /**
     * Get recipients accessible to the current user
     */
    protected function getAccessibleRecipients(): ?array
    {
        $user = Auth::user();
        
        if (!$user) {
            return [];
        }
        
        // System admin sees everything
        if ($user->hasRole('system_admin')) {
            return null;
        }
        
        // Domain admin sees all recipients in their domains
        if ($user->hasRole('domain_admin')) {
            $domains = $user->domains()->pluck('domain')->toArray();
            return $this->recipientStatsRepository->getRecipientsByDomains($domains);
        }
        
        // Domain user only sees their own email
        if ($user->hasRole('domain_user')) {
            return [$user->email];
        }
        
        return [];
    }

    /**
     * Get dashboard stats based on user role
     */
    public function getDashboardStats(): array
    {
        $user = Auth::user();
        
        if (!$user) {
            return $this->getEmptyDashboardStats();
        }
        
        $domains = $this->getAccessibleDomains();
        
        if ($domains === []) {
            return $this->getEmptyDashboardStats();
        }
        
        // If user has specific domains, filter stats by those domains
        if ($domains !== null) {
            return $this->getFilteredDashboardStats($domains);
        }
        
        // System admin sees all stats - ensure consistent structure
        $stats = $this->emailSpamStatsService->getDashboardStats();
        
        // Ensure the stats have the expected structure
        return $this->ensureConsistentDashboardStructure($stats);
    }

    /**
     * Get filtered dashboard stats for specific domains
     */
    protected function getFilteredDashboardStats(array $domains): array
    {
        $now = now();
        
        return [
            'domain_stats' => [
                'today' => $this->getFilteredDomainStats($domains, 'today'),
                'week' => $this->getFilteredDomainStats($domains, 'week'),
                'month' => $this->getFilteredDomainStats($domains, 'month'),
                'year' => $this->getFilteredDomainStats($domains, 'year'),
            ],
            'email_stats' => $this->emailSpamStatsService->getEmailStatsForPeriod('today'),
            'message_stats' => $this->getFilteredMessageStats($domains),
            'top_spam_domains' => $this->emailSpamStatsService->getTopSpamDomains(5, null, null, $domains),
            'top_spam_senders' => $this->getFilteredTopSpamSenders($domains, 5),
            'top_targeted_recipients' => $this->getFilteredTopRecipients($domains, 5),
            'action_breakdown' => $this->getFilteredActionBreakdown($domains),
            'daily_trend' => $this->getFilteredDailyTrend($domains),
            'hourly_distribution' => $this->emailSpamStatsService->getTodayHourlyDistribution(),
            'message_action_summary' => $this->emailSpamStatsService->getMessageActionSummary(),
        ];
    }

    /**
     * Get filtered domain stats for a period
     */
    protected function getFilteredDomainStats(array $domains, string $period): array
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
        
        $stats = $this->domainStatsRepository->getAggregatedStatsByDateRangeForDomains($startDate, $endDate, $domains);
        $stats['period'] = ucfirst($period);
        $stats['date_range'] = ['start' => $startDate, 'end' => $endDate];
        $stats['total_size_formatted'] = $this->formatBytes($stats['total_size_bytes']);
        
        return $stats;
    }

    /**
     * Get filtered message stats
     */
    protected function getFilteredMessageStats(array $domains): array
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(7)->toDateString();
        $monthAgo = now()->subDays(30)->toDateString();
        
        return [
            'today' => $this->domainStatsRepository->getMessageCountsByDomains($today, $today, $domains),
            'this_week' => $this->domainStatsRepository->getMessageCountsByDomains($weekAgo, now()->toDateString(), $domains),
            'this_month' => $this->domainStatsRepository->getMessageCountsByDomains($monthAgo, now()->toDateString(), $domains),
            'daily_average' => $this->getFilteredDailyAverage($domains),
            'peak_hour' => $this->emailSpamStatsService->getPeakMessageHour(),
        ];
    }

    /**
     * Get filtered top spam senders
     */
    protected function getFilteredTopSpamSenders(array $domains, int $limit = 5): array
    {
        $startDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();

        // Use the correct method name with the recipientDomains parameter
        $senders = $this->senderDomainStatsRepository->getTopSpamSenders($startDate, $endDate, $limit, $domains);
        return $this->ensureArray($senders);
    }

    /**
     * Get filtered top recipients
     */
    protected function getFilteredTopRecipients(array $domains, int $limit = 5): array
    {
        $startDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();
        
        $recipients = $this->recipientStatsRepository
            ->getTopTargetedRecipientsForDomains($domains, $startDate, $endDate, $limit);
        
        return $this->ensureArray($recipients);
    }

    /**
     * Get filtered action breakdown
     */
    protected function getFilteredActionBreakdown(array $domains): array
    {
        $startDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();
        
        $breakdown = $this->domainStatsRepository->getActionBreakdownForDomains($startDate, $endDate, $domains);
        $breakdown = $this->ensureArray($breakdown);
        
        $total = array_sum(array_column($breakdown, 'total'));
        
        foreach ($breakdown as $action => &$data) {
            $data['percentage'] = $total > 0 ? round(($data['total'] / $total) * 100, 2) : 0;
        }
        
        return $breakdown;
    }

    /**
     * Get filtered daily trend
     */
    protected function getFilteredDailyTrend(array $domains): array
    {
        $startDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();
        
        $dailyStats = $this->domainStatsRepository->getDailyTrendForDomains($startDate, $endDate, $domains);
        $dailyStats = $this->ensureArray($dailyStats);
        
        $trend = [];
        foreach ($dailyStats as $day) {
            $trend[] = [
                'date' => $this->getArrayValue($day, 'date'),
                'total' => (int) $this->getArrayValue($day, 'total_incoming', 0),
                'spam' => (int) $this->getArrayValue($day, 'total_spam', 0),
                'virus' => (int) $this->getArrayValue($day, 'total_virus', 0),
                'clean' => (int) ($this->getArrayValue($day, 'total_incoming', 0) - $this->getArrayValue($day, 'total_spam', 0)),
                'spam_rate' => $this->getArrayValue($day, 'total_incoming', 0) > 0 
                    ? round(($this->getArrayValue($day, 'total_spam', 0) / $this->getArrayValue($day, 'total_incoming', 0)) * 100, 2)
                    : 0,
            ];
        }
        
        return $trend;
    }

    /**
     * Get filtered daily average
     */
    protected function getFilteredDailyAverage(array $domains, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        
        $dailyStats = $this->domainStatsRepository->getDailyTrendForDomains($startDate, $endDate, $domains);
        $dailyStats = $this->ensureArray($dailyStats);
        
        if (empty($dailyStats)) {
            return ['average' => 0, 'total' => 0, 'days' => 0];
        }
        
        $totalMessages = array_sum(array_column($dailyStats, 'total_incoming'));
        $daysCount = count($dailyStats);
        
        return [
            'average' => round($totalMessages / $daysCount, 1),
            'total' => $totalMessages,
            'days' => $daysCount,
        ];
    }

    /**
     * Ensure consistent dashboard structure across different data sources
     */
    protected function ensureConsistentDashboardStructure(array $stats): array
    {
        // Ensure domain_stats exists and has the required periods
        if (!isset($stats['domain_stats'])) {
            $emptyStats = $this->getEmptyStatsArray();
            $stats['domain_stats'] = [
                'today' => $emptyStats + ['period' => 'Today', 'date_range' => []],
                'week' => $emptyStats + ['period' => 'This Week', 'date_range' => []],
                'month' => $emptyStats + ['period' => 'This Month', 'date_range' => []],
                'year' => $emptyStats + ['period' => 'This Year', 'date_range' => []],
            ];
        }
        
        // Ensure email_stats has the 'today' key
        if (!isset($stats['email_stats']['today'])) {
            if (isset($stats['email_stats']['total_messages'])) {
                // If it's a flat array, wrap it in 'today'
                $stats['email_stats'] = ['today' => $stats['email_stats']];
            } elseif (!isset($stats['email_stats'])) {
                $stats['email_stats'] = ['today' => $this->getEmptyEmailStats()];
            } else {
                $stats['email_stats']['today'] = $this->getEmptyEmailStats();
            }
        }
        
        // Ensure other required keys exist
        $stats['message_stats'] = $stats['message_stats'] ?? [];
        $stats['top_spam_domains'] = $stats['top_spam_domains'] ?? [];
        $stats['top_spam_senders'] = $stats['top_spam_senders'] ?? [];
        $stats['top_targeted_recipients'] = $stats['top_targeted_recipients'] ?? [];
        $stats['action_breakdown'] = $stats['action_breakdown'] ?? [];
        $stats['daily_trend'] = $stats['daily_trend'] ?? [];
        $stats['hourly_distribution'] = $stats['hourly_distribution'] ?? [];
        $stats['message_action_summary'] = $stats['message_action_summary'] ?? ['total_messages' => 0, 'actions' => []];
        
        return $stats;
    }

    /**
     * Get empty stats array
     */
    protected function getEmptyStatsArray(): array
    {
        return [
            'total_incoming' => 0,
            'total_spam' => 0,
            'total_virus' => 0,
            'avg_spam_score' => 0,
            'max_spam_score' => 0,
            'total_size_bytes' => 0,
            'spam_percentage' => 0,
            'total_size_formatted' => '0 B',
        ];
    }

    /**
     * Get empty email stats array
     */
    protected function getEmptyEmailStats(): array
    {
        return [
            'total_messages' => 0,
            'virus_messages' => 0,
            'unique_actions' => 0,
            'clean_messages' => 0,
        ];
    }

    /**
     * Get empty dashboard stats
     */
    protected function getEmptyDashboardStats(): array
    {
        $emptyStats = $this->getEmptyStatsArray();
        
        return [
            'domain_stats' => [
                'today' => $emptyStats + ['period' => 'Today', 'date_range' => []],
                'week' => $emptyStats + ['period' => 'This Week', 'date_range' => []],
                'month' => $emptyStats + ['period' => 'This Month', 'date_range' => []],
                'year' => $emptyStats + ['period' => 'This Year', 'date_range' => []],
            ],
            'email_stats' => ['today' => $this->getEmptyEmailStats()],
            'message_stats' => [],
            'top_spam_domains' => [],
            'top_spam_senders' => [],
            'top_targeted_recipients' => [],
            'action_breakdown' => [],
            'daily_trend' => [],
            'hourly_distribution' => [],
            'message_action_summary' => ['total_messages' => 0, 'actions' => []],
        ];
    }

    /**
     * Get recipient health score with role checks
     */
    public function getRecipientHealthScore(string $recipient): array
    {
        $this->checkRecipientAccess($recipient);
        return $this->emailSpamStatsService->getRecipientHealthScore($recipient);
    }

    /**
     * Get domain health score with role checks
     */
    public function getDomainHealthScore(string $domain): array
    {
        $this->checkDomainAccess($domain);
        return $this->emailSpamStatsService->getDomainHealthScore($domain);
    }

    /**
     * Get domain summary with role checks
     */
    public function getDomainSummary(string $domain, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->checkDomainAccess($domain);
        return $this->emailSpamStatsService->getDomainSummary($domain, $startDate, $endDate);
    }

    /**
     * Get recipient summary with role checks
     */
    public function getRecipientSummary(string $recipient, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->checkRecipientAccess($recipient);
        return $this->emailSpamStatsService->getRecipientSummary($recipient, $startDate, $endDate);
    }

    /**
     * Check if user has access to a domain
     */
    protected function checkDomainAccess(string $domain): void
    {
        $user = Auth::user();
        
        if (!$user) {
            abort(403, 'No user logged in.');
        }
        
        $accessibleDomains = $this->getAccessibleDomains();
        
        if ($accessibleDomains === null) {
            return; // System admin has access
        }
        
        if (!in_array($domain, $accessibleDomains)) {
            abort(403, 'You do not have access to this domain\'s statistics.');
        }
    }

    /**
     * Check if user has access to a recipient
     */
    protected function checkRecipientAccess(string $recipient): void
    {
        $user = Auth::user();
        
        if (!$user) {
            abort(403, 'No user logged in.');
        }
        
        // System admin has access
        if ($user->hasRole('system_admin')) {
            return;
        }
        
        // Domain admin - check if recipient domain is in their domains
        if ($user->hasRole('domain_admin')) {
            $recipientDomain = $this->extractDomainFromEmail($recipient);
            $accessibleDomains = $user->domains()->pluck('domain')->toArray();
            
            if (!in_array($recipientDomain, $accessibleDomains)) {
                abort(403, 'You do not have access to this recipient\'s statistics.');
            }
            return;
        }
        
        // Domain user - only their own email
        if ($user->hasRole('domain_user')) {
            if ($user->email !== $recipient) {
                abort(403, 'You can only view your own statistics.');
            }
            return;
        }
        
        abort(403, 'You do not have permission to view these statistics.');
    }

    /**
     * Extract domain from email
     */
    protected function extractDomainFromEmail(string $email): ?string
    {
        if (str_contains($email, '@')) {
            $parts = explode('@', $email);
            return isset($parts[1]) ? strtolower(trim($parts[1])) : null;
        }
        return null;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Safely convert a Collection to array, or return the array as-is
     * 
     * @param mixed $data
     * @return array
     */
    protected function ensureArray($data): array
    {
        if ($data instanceof \Illuminate\Database\Eloquent\Collection) {
            return $data->toArray();
        }
        
        if (is_array($data)) {
            return $data;
        }
        
        return [];
    }

    /**
     * Safely get a value from either an array or an object
     * 
     * @param mixed $data
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getArrayValue($data, string $key, $default = null)
    {
        if (is_array($data)) {
            return $data[$key] ?? $default;
        }
        
        if (is_object($data)) {
            return $data->$key ?? $default;
        }
        
        return $default;
    }
}