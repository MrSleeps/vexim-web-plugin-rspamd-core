<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface SenderDomainStatsRepositoryInterface
{
    public function findOrCreate(string $senderDomain, string $date): mixed;
    
    public function save(mixed $stats): bool;
    
    public function getTopSpamSenders(string $startDate, string $endDate, int $limit = 10): Collection;
    
    public function getSenderDomainSummary(string $senderDomain, string $startDate, string $endDate): Collection;
    
    public function deleteOldStats(string $cutoffDate): int;
    
    public function updateTopRecipientDomains(string $senderDomain, string $date, array $topDomains): bool;
}
