<?php

namespace VEximweb\Plugin\RSpamd\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use VEximweb\Core\Data\Models\Whitelist;
use VEximweb\Core\Data\Models\Blocklist;
use VEximweb\Core\Data\Models\User;
use VEximweb\Core\Data\Models\EximUser;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Repositories\EximUserRepository;
use VEximweb\Core\Data\Repositories\DomainRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class RspamdCheckController extends Controller
{
    protected EximUserRepository $userRepository;
    protected DomainRepository $domainRepository;
    
    public function __construct(
        EximUserRepository $userRepository,
        DomainRepository $domainRepository
    ) {
        $this->userRepository = $userRepository;
        $this->domainRepository = $domainRepository;
    }
    
    /**
     * Resolve catch-all address to actual delivery address
     * 
     * @param string $recipient Email address to resolve
     * @return EximUser|null The resolved user or null if not found
     */
    protected function resolveCatchAll(string $recipient): ?EximUser
    {

        $user = $this->userRepository->findByUsername($recipient);
        
        if ($user) {
            Log::info('Exact user found', ['recipient' => $recipient, 'user_id' => $user->user_id]);
            return $user;
        }
        
        $parts = explode('@', $recipient);
        if (count($parts) !== 2) {
            Log::warning('Invalid email format', ['recipient' => $recipient]);
            return null;
        }
        
        $localpart = $parts[0];
        $domainName = $parts[1];
        
        Log::info('Checking for catch-all', [
            'localpart' => $localpart,
            'domain' => $domainName,
            'original_recipient' => $recipient
        ]);
        
        $domain = $this->domainRepository->findByDomainName($domainName);
        if (!$domain) {
            Log::warning('Domain not found', ['domain' => $domainName]);
            return null;
        }
        
        Log::info('Domain found', [
            'domain_id' => $domain->domain_id,
            'domain_name' => $domain->domain,
            'enabled' => $domain->enabled
        ]);
        
        $catchAllUser = $this->userRepository->findByLocalpartAndDomain('*', $domain->domain_id);
        
        if (!$catchAllUser) {
            Log::info('No catch-all user found for domain', [
                'domain_id' => $domain->domain_id,
                'domain' => $domainName
            ]);
            return null;
        }
        
        Log::info('Catch-all user found', [
            'user_id' => $catchAllUser->user_id,
            'username' => $catchAllUser->username,
            'type' => $catchAllUser->type,
            'enabled' => $catchAllUser->enabled,
            'smtp' => $catchAllUser->smtp
        ]);
        
        if ($catchAllUser->type !== 'catch') {
            Log::warning('User found but type is not catch', [
                'type' => $catchAllUser->type,
                'expected' => 'catch'
            ]);
            return null;
        }
        
        if (!$catchAllUser->enabled) {
            Log::warning('Catch-all user is disabled', ['user_id' => $catchAllUser->user_id]);
            return null;
        }
        
        if (empty($catchAllUser->smtp)) {
            Log::error('Catch-all has no SMTP delivery address', [
                'user_id' => $catchAllUser->user_id,
                'username' => $catchAllUser->username
            ]);
            return null;
        }
        
        $actualUser = $this->userRepository->findByUsername($catchAllUser->smtp);
        
        if (!$actualUser) {
            Log::error('Catch-all delivery address not found', [
                'smtp' => $catchAllUser->smtp,
                'catch_all_user_id' => $catchAllUser->user_id
            ]);
            return null;
        }
        
        if (!$actualUser->enabled) {
            Log::warning('Catch-all target user is disabled', [
                'actual_user' => $actualUser->username,
                'user_id' => $actualUser->user_id
            ]);
            return null;
        }
        
        Log::info('Catch-all resolved successfully', [
            'original' => $recipient,
            'resolved_to' => $actualUser->username,
            'user_id' => $actualUser->user_id
        ]);
        
        return $actualUser;
    }
    
    /**
     * Single endpoint to check ALL rules for an email
     * POST /api/v1/rspamd/check
     */
    public function check(Request $request)
    {
        
    Log::info('Auth check', [
        'user' => $request->user(),
        'guard' => auth()->getDefaultDriver(),
        'token' => $request->bearerToken(),
    ]);   
        
        Log::info('=== RSPAMD CHECK CONTROLLER HIT ===');
        Log::info('Request path: ' . $request->path());
        Log::info('Request method: ' . $request->method());
        Log::info('Bearer token: ' . $request->bearerToken());
        Log::info('User: ' . ($request->user() ? $request->user()->id : 'null'));        
        
        Log::info('Rspamd check request received', [
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'recipient' => $request->input('recipient')
        ]);
        
        $validated = $request->validate([
            'sender' => 'nullable|email',
            'sender_domain' => 'nullable|string',
            'ip' => 'nullable|ip',
            'recipient' => 'required|email',
            'recipient_domain' => 'required|string',
            'subject' => 'nullable|string'
        ]);

        $recipient = $validated['recipient'];
        $sender = $validated['sender'] ?? null;
        $senderDomain = $validated['sender_domain'] ?? null;
        $ip = $validated['ip'] ?? null;
        $subject = $validated['subject'] ?? null;

        $user = $this->resolveCatchAll($recipient);

        if (!$user) {
            Log::warning('User not found', ['recipient' => $recipient]);
            return response()->json(['error' => 'User not found'], 404);
        }

        $domainId = $user->domain_id;

        $cacheKey = "rspamd:check:{$user->user_id}:" . md5($sender . $ip . $subject);
        
        return Cache::remember($cacheKey, 300, function() use (
            $recipient, $sender, $senderDomain, $ip, $subject, $domainId, $user
        ) {
            $response = [
                'whitelist' => false,
                'global_blocklist' => false,
                'blocklist' => false,
                'subject_blocked' => false,
                'color' => null,
                'action' => null,
                'matched_rule' => null,
                'score' => null,
                'subject_score' => null
            ];

            // 1. Check whitelist (highest priority)
            $whitelisted = Whitelist::where(function($q) use ($domainId, $user) {
                // Global whitelist
                $q->orWhere('domain_id', 0)
                  // Domain whitelist
                  ->orWhere('domain_id', $domainId)
                  ->whereNull('localpart')
                  // User whitelist
                  ->orWhere(function($sq) use ($domainId, $user) {
                      $sq->where('domain_id', $domainId)
                         ->where('localpart', $user->localpart);
                  });
            })
            ->where(function($q) use ($sender, $senderDomain) {
                // Match exact sender or domain pattern
                if ($sender) {
                    $q->where('sender', $sender);
                }
                if ($senderDomain) {
                    $q->orWhere('sender', 'like', '%@' . $senderDomain);
                }
            })
            ->exists();

            if ($whitelisted) {
                $response['whitelist'] = true;
                Log::info('Whitelist hit', ['sender' => $sender, 'recipient' => $user->username]);
                return $response;
            }

            // 2. Check global blocklist (IP-based or domain-based)
            $globalBlocked = Blocklist::where('domain_id', 0)
                ->where(function($q) use ($ip, $senderDomain) {
                    if ($ip) {
                        $q->orWhere('blockval', $ip);
                    }
                    if ($senderDomain) {
                        $q->orWhere('blockval', $senderDomain);
                    }
                })
                ->exists();

            if ($globalBlocked) {
                $response['global_blocklist'] = true;
                $response['action'] = 'reject';
                Log::info('Global block hit', ['ip' => $ip, 'domain' => $senderDomain]);
                return $response;
            }

            // 3. Check user blocklist for sender
            if ($sender) {
                $blockedSender = Blocklist::where('domain_id', $domainId)
                    ->where('blockhdr', 'From')
                    ->where('blockval', $sender)
                    ->first();

                if ($blockedSender) {
                    $response['blocklist'] = true;
                    $response['color'] = $blockedSender->color;
                    $response['matched_rule'] = 'sender';
                    $response['action'] = $blockedSender->color === 'red' ? 'reject' : 'add_header';
                    $response['score'] = $blockedSender->color === 'red' ? 0 : 15;
                    Log::info('Sender block hit', ['sender' => $sender, 'color' => $blockedSender->color]);
                    return $response;
                }
            }

            // 4. Check user blocklist for IP
            if ($ip) {
                $blockedIp = Blocklist::where('domain_id', $domainId)
                    ->where('blockhdr', 'IP')
                    ->where('blockval', $ip)
                    ->first();

                if ($blockedIp) {
                    $response['blocklist'] = true;
                    $response['color'] = $blockedIp->color;
                    $response['matched_rule'] = 'ip';
                    $response['action'] = $blockedIp->color === 'red' ? 'reject' : 'add_header';
                    Log::info('IP block hit', ['ip' => $ip, 'color' => $blockedIp->color]);
                    return $response;
                }
            }

            // 5. Check subject patterns
            if ($subject) {
                $blockedSubject = Blocklist::where('domain_id', $domainId)
                    ->where('blockhdr', 'Subject')
                    ->where(function($q) use ($subject) {
                        $q->where('blockval', $subject)
                          ->orWhere('blockval', 'like', '%' . $subject . '%');
                    })
                    ->first();

                if ($blockedSubject) {
                    $response['subject_blocked'] = true;
                    $response['subject_score'] = 8.0;
                    Log::info('Subject block hit', ['subject' => $subject]);
                }
            }

            return $response;
        });
    }
}