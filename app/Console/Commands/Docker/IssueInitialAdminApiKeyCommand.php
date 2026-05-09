<?php

namespace App\Console\Commands\Docker;

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Services\Acl\Api\AdminAcl;
use App\Services\Api\KeyCreationService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IssueInitialAdminApiKeyCommand extends Command
{
    protected $signature = 'p:docker:issue-initial-admin-api-key';

    protected $description = 'Issues a one-time full-permission admin application API key for Docker-based first installs.';

    private const DEFAULT_KEY_MEMO = 'Initial Docker admin API key';

    public function __construct(private readonly KeyCreationService $keyCreationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!config('app.installed')) {
            $this->line('Panel is not installed yet, skipping initial admin API key generation.');

            return 0;
        }

        $markerPath = config('panel.docker.initial_admin_api_key_marker_path');
        if (!is_string($markerPath) || $markerPath === '') {
            $this->error('Invalid Docker API key marker path configuration.');

            return 1;
        }

        if (File::exists($markerPath)) {
            $this->line('Initial admin API key has already been issued, skipping.');

            return 0;
        }

        $adminUser = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::ROOT_ADMIN))
            ->orderBy('id')
            ->first();
        if (!$adminUser) {
            $this->line('No root admin user found yet, skipping initial admin API key generation.');

            return 0;
        }

        $existingKey = ApiKey::query()
            ->where('user_id', $adminUser->id)
            ->where('key_type', ApiKey::TYPE_APPLICATION)
            ->where('memo', self::DEFAULT_KEY_MEMO)
            ->first();

        if ($existingKey) {
            $this->line('Initial admin API key already exists but marker file is missing. Creating marker to prevent duplicate generation (existing key value cannot be recovered).');
            $this->markAsIssued($markerPath);

            return 0;
        }

        $permissions = array_fill_keys(ApiKey::getPermissionList(), AdminAcl::READ | AdminAcl::WRITE);

        try {
            $key = $this->keyCreationService
                ->setKeyType(ApiKey::TYPE_APPLICATION)
                ->handle([
                    'user_id' => $adminUser->id,
                    'memo' => self::DEFAULT_KEY_MEMO,
                    'allowed_ips' => [],
                    'permissions' => $permissions,
                ]);
        } catch (Exception $exception) {
            $this->error('Failed to generate initial admin API key: ' . $exception->getMessage());

            return 1;
        }

        $this->markAsIssued($markerPath);

        $this->newLine();
        $this->line('==============================================');
        $this->line(' Initial admin API key has been generated.');
        $this->line(' This key has full admin read/write permissions.');
        if (config('panel.docker.initial_admin_api_key_output')) {
            $this->line(' Save this key now. It will not be shown again.');
            $this->line(' Warning: console logs can expose this key if logs are shared.');
            $this->line(' KEY: ' . $key->identifier . $key->token);
        } else {
            $this->line(' API key output is disabled by configuration.');
        }
        $this->line('==============================================');
        $this->newLine();

        return 0;
    }

    private function markAsIssued(string $markerPath): void
    {
        try {
            File::ensureDirectoryExists(dirname($markerPath));
            File::put($markerPath, 'issued');
        } catch (Exception $exception) {
            $this->warn('Could not persist API key marker file; another key generation attempt may happen on next restart: ' . $exception->getMessage());
        }
    }
}
