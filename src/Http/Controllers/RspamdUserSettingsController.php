<?php

namespace VEximweb\Plugin\RSpamd\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use VEximweb\Plugin\RSpamd\Core\Services\RspamdConfigService;
use VEximweb\Core\Data\Repositories\EximUserRepository;
use VEximweb\Core\Data\Repositories\DomainRepository;
use VEximweb\Core\Data\Models\EximUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class RspamdUserSettingsController extends Controller
{
    /**
     * @var RspamdConfigService
     */
    protected RspamdConfigService $rspamdService;
    
    /**
     * @var EximUserRepository
     */
    protected EximUserRepository $userRepository;
    
    /**
     * @var DomainRepository
     */
    protected DomainRepository $domainRepository;
    
    /**
     * Constructor
     * 
     * @param RspamdConfigService $rspamdService
     * @param EximUserRepository $userRepository
     * @param DomainRepository $domainRepository
     */
    public function __construct(
        RspamdConfigService $rspamdService,
        EximUserRepository $userRepository,
        DomainRepository $domainRepository
    ) {
        $this->rspamdService = $rspamdService;
        $this->userRepository = $userRepository;
        $this->domainRepository = $domainRepository;
    }
    
    /**
     * Resolve catch-all address to actual user
     * 
     * @param string $recipient Email address to resolve
     * @return EximUser|null The resolved user or null if not found
     */
    protected function resolveCatchAll(string $recipient): ?EximUser
    {
        $user = $this->userRepository->findByUsername($recipient);
        
        if ($user && $user->enabled) {
            return $user;
        }
        
        $parts = explode('@', $recipient);
        if (count($parts) !== 2) {
            return null;
        }
        
        $domainName = $parts[1];
        
        $domain = $this->domainRepository->findByDomainName($domainName);
        if (!$domain || !$domain->enabled) {
            return null;
        }
        
        $catchAllUser = $this->userRepository->findByLocalpartAndDomain('*', $domain->domain_id);
        
        if (!$catchAllUser || 
            $catchAllUser->type !== 'catch' || 
            !$catchAllUser->enabled || 
            !$catchAllUser->smtp) {
            return null;
        }

        return $this->userRepository->findByUsername($catchAllUser->smtp);
    }
    
    /**
     * Get all user spam settings for Rspamd
     * 
     * @param Request $request
     * @return Response
     */
    public function getAllSettings(Request $request): Response
    {
        if (!$request->user() || !$request->user()->tokenCan('rspamd:settings:read')) {
            return response('Unauthorized', 401);
        }
        
        // Cache the result to reduce database load
        // TTL matches Rspamd's settings_expire (5 minutes)
        $uclContent = Cache::remember('rspamd:user-settings', 300, function () {
            return $this->rspamdService->generateUserSettingsUCL();
        });
        
        Log::debug('Rspamd settings served', [
            'size_bytes' => strlen($uclContent),
            'cached' => Cache::has('rspamd:user-settings')
        ]);
        
        return response($uclContent, 200)
            ->header('Content-Type', 'application/x-ucl')
            ->header('X-Content-Type-Options', 'nosniff');
    }
    
    /**
     * Get settings for a single user (real-time lookup)
     * 
     * @param Request $request
     * @param string $email
     * @return Response
     */
    public function getUserSettings(Request $request, string $email): Response
    {
        // Validate Sanctum token
        if (!$request->user() || !$request->user()->tokenCan('rspamd:settings:read')) {
            Log::warning('Unauthorized access attempt', ['email' => $email]);
            return response('Unauthorized', 401);
        }
        
        $user = $this->resolveCatchAll($email);
        
        if (!$user) {
            Log::info('No user found', ['email' => $email]);
            return response('', 404);
        }
        
        $resolvedEmail = $user->username;
        
        if ($resolvedEmail !== $email) {
            Log::info('Catch-all resolved', [
                'original_email' => $email,
                'resolved_email' => $resolvedEmail,
                'user_id' => $user->user_id
            ]);
        }
        
        $uclContent = $this->rspamdService->generateSingleUserUCL($resolvedEmail);
        
        if (!$uclContent) {
            Log::info('No settings found for user', [
                'original_email' => $email,
                'resolved_email' => $resolvedEmail
            ]);
            return response('', 404);
        }
        
        Log::debug('Returned settings for user', [
            'original_email' => $email,
            'resolved_email' => $resolvedEmail,
            'size_bytes' => strlen($uclContent)
        ]);
        
        return response($uclContent, 200)
            ->header('Content-Type', 'application/x-ucl');
    }
    
    /**
     * Clear the cache (useful for admin webhooks)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->user() || !$request->user()->tokenCan('rspamd:admin')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        Cache::forget('rspamd:user-settings');
        
        Log::info('Rspamd settings cache cleared', [
            'cleared_by' => $request->user()->email ?? $request->user()->id
        ]);
        
        return response()->json(['message' => 'Cache cleared successfully']);
    }
}