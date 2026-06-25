<?php
namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

interface EmailStatRepositoryInterface
{
    public function incrementOrCreate(string $hour, string $action, bool $hasVirus, int $incrementBy = 1): void;
    
    public function createSample(array $data): void;
    
    public function getHourlyStats(string $date, ?string $action = null): array;
    
    public function getAggregatedStats(string $startDate, string $endDate): array;
    
    /**
     * Get total message counts by period
     */
    public function getMessageCountsByPeriod(string $startDate, string $endDate, ?string $groupBy = null): array;
    
    /**
     * Get hourly distribution for a specific date
     */
    public function getHourlyDistribution(string $date): array;
    
    /**
     * Get action summary for a date range
     */
    public function getActionSummary(string $startDate, string $endDate): array;    
}