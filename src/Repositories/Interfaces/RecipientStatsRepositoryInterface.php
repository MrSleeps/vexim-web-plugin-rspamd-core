<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface RecipientStatsRepositoryInterface
{
    public function findOrCreate(string $recipient, string $date): mixed;
    
    public function save(mixed $stats): bool;
    
    public function getRecipientSummary(string $recipient, string $startDate, string $endDate): ?array;
    
    public function getTopTargetedRecipients(string $startDate, string $endDate, int $limit = 10): Collection;
    
    public function deleteOldStats(string $cutoffDate): int;
    
    public function getRecipientsByDomain(string $domain, string $startDate, string $endDate): Collection;
    
    public function getTopTargetedRecipientsForDomains(array $domains, string $startDate, string $endDate, int $limit = 10): Collection;
    public function getRecipientsByDomains(array $domains): array;    
    }
