<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface DomainStatsRepositoryInterface
{
    public function findOrCreate(string $domain, string $date, string $action): mixed;
    
    public function save(mixed $stats): bool;
    
    public function getDomainSummary(string $domain, string $startDate, string $endDate): Collection;
    
    public function getTopSpamDomains(string $startDate, string $endDate, int $limit = 10): Collection;
    
    public function getDomainDailyTrend(string $domain, string $startDate, string $endDate): Collection;
    
    public function deleteOldStats(string $cutoffDate): int;
    
    public function getAggregatedStats(string $startDate, string $endDate): array;
    
    /**
     * Get aggregated stats with date range grouping
     */
    public function getAggregatedStatsByDateRange(string $startDate, string $endDate, ?string $groupBy = null): array;
    
    /**
     * Get action breakdown for a date range
     */
    public function getActionBreakdown(string $startDate, string $endDate, ?string $domain = null): array;
    
    /**
     * Get total stats for current day/week/month/year
     */
    public function getTotalStatsForPeriod(string $period): array;    
    
    public function getAggregatedStatsByDateRangeForDomains(string $startDate, string $endDate, array $domains): array;
    public function getMessageCountsByDomains(string $startDate, string $endDate, array $domains): array;
    public function getActionBreakdownForDomains(string $startDate, string $endDate, array $domains): array;
    public function getDailyTrendForDomains(string $startDate, string $endDate, array $domains): Collection;    
}
