<?php

namespace VEximweb\Plugin\RSpamd\Core\Services;

use VEximweb\Core\Data\Repositories\Interfaces\EximUserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RspamdConfigService
{
    /**
     * @var EximUserRepositoryInterface
     */
    protected EximUserRepositoryInterface $userRepository;
    
    /**
     * Constructor
     * 
     * @param EximUserRepositoryInterface $userRepository
     */
    public function __construct(EximUserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    
    /**
     * Generate UCL configuration for all users
     * 
     * @return string
     */
    public function generateUserSettingsUCL(): string
    {
        $users = $this->userRepository->getUsersWithSpamSettings();
        
        if ($users->isEmpty()) {
            return "# No user-specific spam settings configured\n";
        }
        
        $output = [];
        
        foreach ($users as $user) {
            $settings = $this->buildUserSettings($user);
            
            if (!empty($settings)) {
                $output[] = $this->formatUserAsUCL($user->username, $settings);
            }
        }
        
        return implode("\n\n", $output);
    }
    
    /**
     * Generate UCL for a single user
     * 
     * @param string $email
     * @return string|null
     */
    public function generateSingleUserUCL(string $email): ?string
    {
        $user = $this->userRepository->findByUsername($email);
        
        if (!$user || !$user->enabled || $user->type !== 'local') {
            return null;
        }
        
        $settings = $this->buildUserSettings($user);
        
        if (empty($settings)) {
            return null;
        }
        
        return $this->formatUserAsUCL($user->username, $settings);
    }
    
    /**
     * Build settings array for a user
     * 
     * @param object $user
     * @return array
     */
    protected function buildUserSettings(object $user): array
    {
        $settings = [];
        
        // Only apply spam settings if SpamAssassin is enabled for this user
        if ($user->on_spamassassin) {
            // Map sa_tag to "add_header" action (mark as spam but deliver)
            if ($user->sa_tag !== null && $user->sa_tag > 0) {
                $settings['actions']['add_header'] = $user->sa_tag;
            }
            
            // Map sa_refuse to "reject" action
            if ($user->sa_refuse !== null && $user->sa_refuse > 0) {
                $settings['actions']['reject'] = $user->sa_refuse;
            }
        }
        
        return $settings;
    }
    
    /**
     * Format user settings as UCL
     * 
     * @param string $email
     * @param array $settings
     * @return string
     */
    protected function formatUserAsUCL(string $email, array $settings): string
    {
        return '"' . addslashes($email) . '" ' . $this->arrayToUCL($settings);
    }
    
    /**
     * Convert PHP array to Rspamd UCL format
     * 
     * @param array $data
     * @param int $depth
     * @return string
     */
    protected function arrayToUCL(array $data, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $result = "{\n";
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result .= $indent . "  $key = " . $this->arrayToUCL($value, $depth + 1) . ";\n";
            } elseif (is_numeric($value)) {
                $result .= $indent . "  $key = $value;\n";
            } elseif (is_bool($value)) {
                $result .= $indent . "  $key = " . ($value ? 'true' : 'false') . ";\n";
            } else {
                $result .= $indent . "  $key = \"$value\";\n";
            }
        }
        
        $result .= $indent . "}";
        return $result;
    }
}
