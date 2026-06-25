<?php

namespace VEximweb\Plugin\RSpamd\Core\Console\Commands;

use VEximweb\Core\Data\Models\User;
use Illuminate\Console\Command;

class CreateRspamdApiToken extends Command
{
    protected $signature = 'vw:create-rspamd-token {user_id?}';
    protected $description = 'Create API token for Rspamd service';

    public function handle()
    {
        $userId = $this->argument('user_id') ?? 1;
        $user = User::find($userId);

        if (!$user) {
            $this->error("User ID {$userId} not found");
            return 1;
        }

        // Revoke existing Rspamd tokens
        $user->tokens()->where('name', 'rspamd-service')->delete();

        // Create new token with specific abilities
        $token = $user->createToken('rspamd-service', [
            'rspamd:maps:read',
            'rspamd:settings:read',
            'rspamd:meta'
        ]);

        $this->info('Rspamd API Token created successfully!');
        $this->newLine();
        $this->info('Add this to your Rspamd configuration:');
        $this->newLine();
        $this->line('X-API-Key: ' . $token->plainTextToken);
        $this->newLine();
        $this->warn('If you are updating an existing token, you will need to replace the existing token in your rspamd config');
        $this->newLine();
        $this->warn('Store this token securely - it won\'t be shown again!');

        return 0;
    }
}