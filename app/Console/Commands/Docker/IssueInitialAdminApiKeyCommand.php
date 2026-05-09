<?php

namespace App\Console\Commands\Docker;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\Acl\Api\AdminAcl;
use App\Services\Api\KeyCreationService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IssueInitialAdminApiKeyCommand extends Command
{
    protected $signature = 'p:docker:issue-initial-admin-api-key';

    protected $description = 'Issues a one-time admin application API key for Docker-based first installs.';

    private const DEFAULT_MARKER_PATH = '/pelican-data/.initial-admin-api-key-issued';

    private const DEFAULT_KEY_MEMO = 'Initial Docker admin API key';

    public function __construct(private readonly KeyCreationService $keyCreationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!config('app.installed')) {
            return 0;
        }

        $markerPath = env('INITIAL_ADMIN_API_KEY_MARKER', self::DEFAULT_MARKER_PATH);
        if (File::exists($markerPath)) {
            return 0;
        }

        $adminUser = User::query()->get()->first(fn (User $user) => $user->isRootAdmin());
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
        $this->line(' Save this key now. It will not be shown again.');
        $this->line(' KEY: ' . $key->identifier . $key->token);
        $this->line('==============================================');
        $this->newLine();

        return 0;
    }

    private function markAsIssued(string $markerPath): void
    {
        try {
            File::ensureDirectoryExists(dirname($markerPath));
            File::put($markerPath, now()->toIso8601String());
        } catch (Exception $exception) {
            $this->warn('Could not persist API key marker file: ' . $exception->getMessage());
        }
    }
}
