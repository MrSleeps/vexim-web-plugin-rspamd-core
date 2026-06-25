<?php

namespace VEximweb\Plugin\RSpamd\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use VEximweb\Plugin\RSpamd\Core\Models\QuarantinedEmail;
use VEximweb\Plugin\RSpamd\Core\Services\EmailSpamStatsService;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailStatRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailScoreSampleRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleStatRepositoryInterface;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleScoreSampleRepositoryInterface;
use VEximweb\Core\Data\Repositories\Interfaces\DomainRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class RspamdMetadataController extends Controller
{
    protected EmailSpamStatsService $statsService;
    protected EmailStatRepositoryInterface $emailStatRepository;
    protected EmailScoreSampleRepositoryInterface $emailScoreSampleRepository;
    protected SpamRuleStatRepositoryInterface $spamRuleStatRepository;
    protected SpamRuleScoreSampleRepositoryInterface $spamRuleScoreSampleRepository;
    protected DomainRepositoryInterface $domainRepository;
    
    protected ?array $localDomainsCache = null;
    
    public function __construct(
        EmailSpamStatsService $statsService,
        EmailStatRepositoryInterface $emailStatRepository,
        EmailScoreSampleRepositoryInterface $emailScoreSampleRepository,
        SpamRuleStatRepositoryInterface $spamRuleStatRepository,
        SpamRuleScoreSampleRepositoryInterface $spamRuleScoreSampleRepository,
        DomainRepositoryInterface $domainRepository
    ) {
        $this->statsService = $statsService;
        $this->emailStatRepository = $emailStatRepository;
        $this->emailScoreSampleRepository = $emailScoreSampleRepository;
        $this->spamRuleStatRepository = $spamRuleStatRepository;
        $this->spamRuleScoreSampleRepository = $spamRuleScoreSampleRepository;
        $this->domainRepository = $domainRepository;
    }
    
    public function import(Request $request): \Illuminate\Http\JsonResponse
    {
        Log::debug('RSpamd Meta: Raw request', [
            'bearer'       => $request->bearerToken(),
            'content_type' => $request->header('Content-Type'),
        ]);

        if (!$request->user() || !$request->user()->tokenCan('rspamd:meta')) {
            Log::warning('RSpamd Meta: unauthorized token', [
                'user_id'         => $request->user()?->id,
                'token_abilities' => $request->user()?->currentAccessToken()?->abilities,
            ]);
            return response()->json(['error' => 'Forbidden - missing rspamd:meta ability'], 403);
        }

        $metadata = $request->json()->all();
        if (empty($metadata)) {
            return response()->json(['error' => 'Missing or invalid JSON body'], 400);
        }

        $queueId       = $metadata['qid'] ?? null;
        $messageId     = $metadata['message_id'] ?? null;
        $action        = $metadata['action'] ?? null;
        $score         = (float) ($metadata['score'] ?? 0);
        $requiredScore = (float) ($metadata['required_score'] ?? 5.0);
        $mailFrom      = $metadata['mail_from'] ?? null;
        $mimeFrom      = $metadata['mime_from'] ?? null;
        $rcptTo        = $metadata['rcpt_to'] ?? [];
        $mimeTo        = $metadata['mime_to'] ?? null;
        $subject       = $metadata['subject'] ?? null;
        $ip            = $metadata['ip'] ?? null;
        $helo          = $metadata['helo'] ?? null;
        $symbols       = $metadata['symbols'] ?? [];
        $hasVirus      = (bool) ($metadata['has_virus'] ?? false);
        $size          = (int) ($metadata['size'] ?? 0);

        if (!$action) {
            return response()->json(['error' => 'Missing action field'], 400);
        }

        // Determine if this is incoming or outgoing
        $isIncoming = $this->isIncomingEmail($mailFrom, $rcptTo);
        
        Log::info('RSpamd Meta: metadata received', [
            'qid'          => $queueId,
            'mail_from'    => $mailFrom,
            'rcpt_to'      => $rcptTo,
            'action'       => $action,
            'score'        => $score,
            'has_virus'    => $hasVirus,
            'symbol_count' => count($symbols),
            'is_incoming'  => $isIncoming,
        ]);

        // Only update statistics and quarantine for INCOMING emails
        if ($isIncoming) {
            $this->updateStatistics($action, $score, $requiredScore, $hasVirus);

            if (!empty($symbols)) {
                $this->updateRuleStats($symbols);
            }

            try {
                $this->statsService->updateAllStats(
                    $action,
                    $score,
                    $hasVirus,
                    $mailFrom,
                    $rcptTo,
                    $size
                );
            } catch (\Exception $e) {
                Log::error('RSpamd Meta: failed to update domain/recipient stats', [
                    'error' => $e->getMessage(),
                    'queue_id' => $queueId
                ]);
            }

            $shouldQuarantine = in_array($action, ['reject', 'discard']) || $hasVirus;
            if ($shouldQuarantine) {
                $this->storeQuarantinedEmail([
                    'queue_id'       => $queueId,
                    'message_id'     => $messageId,
                    'action'         => $action,
                    'score'          => $score,
                    'required_score' => $requiredScore,
                    'mail_from'      => $mailFrom,
                    'mime_from'      => $mimeFrom,
                    'rcpt_to'        => $rcptTo,
                    'mime_to'        => $mimeTo,
                    'subject'        => $subject,
                    'ip'             => $ip,
                    'helo'           => $helo,
                    'symbols'        => $symbols,
                    'has_virus'      => $hasVirus,
                    'size'           => $size,
                ]);
            }
        } else {
            // This is an outgoing email - just log it, don't quarantine
            Log::debug('RSpamd Meta: skipping outgoing email', [
                'qid' => $queueId,
                'mail_from' => $mailFrom,
                'rcpt_to' => $rcptTo
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Get all local domains from the database
     * Uses caching to avoid repeated queries within the same request
     */
    private function getLocalDomains(): array
    {
        if ($this->localDomainsCache === null) {
            $this->localDomainsCache = \Illuminate\Support\Facades\Cache::remember(
                'rspamd_local_domains',
                3600, // Cache for 1 hour
                function () {
                    try {
                        $domains = $this->domainRepository->getEnabledDomains();
                        return $domains->pluck('domain')
                            ->map(fn($d) => strtolower($d))
                            ->toArray();
                    } catch (\Exception $e) {
                        Log::error('RSpamd Meta: failed to load local domains', [
                            'error' => $e->getMessage()
                        ]);
                        return [];
                    }
                }
            );
        }

        return $this->localDomainsCache;
    }

    /**
     * Determine if this is an incoming email (to your domains) or outgoing (from your domains)
     */
    private function isIncomingEmail(?string $mailFrom, array $rcptTo): bool
    {
        $localDomains = $this->getLocalDomains();
        
        // If no local domains are configured, treat everything as incoming
        if (empty($localDomains)) {
            Log::warning('RSpamd Meta: no local domains configured, treating all as incoming');
            return true;
        }

        // If sender is from your domain, it's outgoing
        if ($this->isLocalDomain($mailFrom, $localDomains)) {
            return false;
        }

        // If any recipient is from your domain, it's incoming
        foreach ($rcptTo as $recipient) {
            if ($this->isLocalDomain($recipient, $localDomains)) {
                return true;
            }
        }

        // If we have recipients but none are local, it's outgoing
        if (!empty($rcptTo)) {
            return false;
        }

        // Default: assume incoming if we can't determine
        return true;
    }

    private function isLocalDomain(?string $email, array $localDomains): bool
    {
        if (empty($email)) {
            return false;
        }

        // Extract domain from email address
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        $domain = strtolower($parts[1]);
        
        // Check against your local domains
        return in_array($domain, $localDomains, true);
    }

    private function storeQuarantinedEmail(array $data): void
    {
        try {
            $retentionDays = $data['has_virus'] ? 90 : 30;

            QuarantinedEmail::create([
                'queue_id'       => $data['queue_id'],
                'message_id'     => $data['message_id'],
                'action'         => $data['action'],
                'spam_score'     => $data['score'],
                'required_score' => $data['required_score'],
                'mail_from'      => $data['mail_from'],
                'mime_from'      => $data['mime_from'],
                'rcpt_to'        => $data['rcpt_to'],
                'mime_to'        => $data['mime_to'],
                'subject'        => $data['subject'],
                'ip_address'     => $data['ip'],
                'helo'           => $data['helo'],
                'symbols'        => $data['symbols'],
                'has_virus'      => $data['has_virus'],
                'size'           => $data['size'],
                'status'         => 'quarantined',
                'received_at'    => now(),
                'expires_at'     => now()->addDays($retentionDays),
            ]);

            Log::debug('RSpamd Meta: stored in quarantine', [
                'queue_id' => $data['queue_id'],
                'action'   => $data['action'],
                'score'    => $data['score'],
            ]);
        } catch (\Exception $e) {
            Log::error('RSpamd Meta: failed to store quarantined email', [
                'error'    => $e->getMessage(),
                'queue_id' => $data['queue_id'],
            ]);
        }
    }

    private function updateStatistics(string $action, float $score, float $requiredScore, bool $hasVirus): void
    {
        try {
            $hour = now()->format('Y-m-d H:00:00');

            $this->emailStatRepository->incrementOrCreate($hour, $action, $hasVirus);

            $this->emailScoreSampleRepository->create([
                'action' => $action,
                'score' => $score,
                'required_score' => $requiredScore,
                'has_virus' => $hasVirus,
                'received_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('RSpamd Meta: failed to update statistics', ['error' => $e->getMessage()]);
        }
    }

    private function updateRuleStats(array $symbols): void
    {
        try {
            $today = now()->toDateString();
            $now = now();
            $sampleRows = [];
            $ruleNames = [];

            foreach ($symbols as $symbol) {
                $ruleName = is_array($symbol) ? ($symbol['name'] ?? null) : $symbol;
                $ruleScore = is_array($symbol) ? (float) ($symbol['score'] ?? 0) : 0.0;

                if (!$ruleName) continue;

                $sampleRows[] = [
                    'rule_name' => $ruleName,
                    'score' => $ruleScore,
                    'received_at' => $now,
                ];

                $ruleNames[] = $ruleName;
            }

            if (!empty($sampleRows)) {
                $this->spamRuleScoreSampleRepository->batchCreate($sampleRows);
            }

            if (!empty($ruleNames)) {
                $this->spamRuleStatRepository->batchIncrementOrCreate($ruleNames, $today);
            }
        } catch (\Exception $e) {
            Log::error('RSpamd Meta: failed to update rule stats', ['error' => $e->getMessage()]);
        }
    }
}