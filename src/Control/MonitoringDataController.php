<?php

declare(strict_types=1);

namespace Kalakotra\MonitoringClient\Control;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Manifest\VersionProvider;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Group;

/**
 * Passive monitoring endpoint.
 *
 * The admin side periodically pulls data:
 *   GET https://client.example/monitoring/data?key=<MONITORING_SECRET_KEY>
 *
 * ENV (client .env):
 *   MONITORING_SECRET_KEY  - shared secret between the client and admin
 */
class MonitoringDataController extends Controller
{
    private static string $url_segment = 'monitoring/data';

    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): HTTPResponse
    {
        $this->authenticate($request);

        return $this->jsonResponse($this->collectPayload());
    }

    // -------------------------------------------------------------------------
    // Payload - everything the admin side needs to know about this site
    // -------------------------------------------------------------------------

    private function collectPayload(): array
    {
        $dbStats = $this->getDatabaseStats();

        return [
            // --- Versions ---
            'php_version'        => PHP_VERSION,
            'ss_version'         => $this->resolveSilverStripeVersion(),
            'ss_recipe_version'  => $this->resolveRecipeVersion(),

            // --- Content ---
            'page_count'         => $this->countPages(),
            'published_count'    => $this->countPublishedPages(),
            'draft_count'        => $this->countDraftPages(),
            'broken_links'       => $this->countBrokenLinks(),
            'object_count'       => $dbStats['object_count'],
            'table_count'        => $dbStats['table_count'],

            // --- Users ---
            'member_count'       => $this->countMembers(),
            'admin_count'        => $this->countAdmins(),

            // --- Environment ---
            'environment'        => $this->resolveEnvironment(),
            'base_url'           => \SilverStripe\Control\Director::absoluteBaseURL(),
            'default_locale'     => \SilverStripe\i18n\i18n::get_locale(),

            // --- System ---
            'php_memory_limit'   => ini_get('memory_limit'),
            'php_max_execution'  => (int) ini_get('max_execution_time'),
            'disk_free_gb'       => $this->diskFreeGb(),
            'disk_total_gb'      => $this->diskTotalGb(),

            // --- Timestamp ---
            'reported_at'        => date('Y-m-d H:i:s'),
        ];
    }

    // --- Versions ---------------------------------------------------------------

    private function resolveSilverStripeVersion(): string
    {
        try {
            $modules = VersionProvider::create()
                ->getModuleVersionFromComposer(['silverstripe/framework']);

            return $modules['silverstripe/framework'] ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function resolveRecipeVersion(): string
    {
        try {
            $modules = VersionProvider::create()
                ->getModuleVersionFromComposer(['silverstripe/recipe-cms']);

            return $modules['silverstripe/recipe-cms'] ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    // --- Pages ------------------------------------------------------------------

    private function countPages(): int
    {
        return (int) SiteTree::get()->count();
    }

    private function countPublishedPages(): int
    {
        return (int) \SilverStripe\Versioned\Versioned::get_by_stage(
            SiteTree::class,
            \SilverStripe\Versioned\Versioned::LIVE
        )->count();
    }

    private function countDraftPages(): int
    {
        return max(0, $this->countPages() - $this->countPublishedPages());
    }

    private function countBrokenLinks(): int
    {
        try {
            return (int) SiteTree::get()
                ->filter('HasBrokenLink', true)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // --- Database ---------------------------------------------------------------

    /**
    * Dynamically collects statistics from information_schema.
    * Excludes _versions and _live variants to avoid double-counting.
     *
     * @return array{object_count: int, table_count: int}
     */
    private function getDatabaseStats(): array
    {
        $dbName = DB::get_conn()->getSelectedDatabase();

        $tables = DB::query(
            sprintf(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = '%s'
                   AND TABLE_NAME NOT LIKE '%%_versions'
                   AND TABLE_NAME NOT LIKE '%%_Live'",
                addslashes($dbName)
            )
        );

        $tableCount  = 0;
        $objectCount = 0;

        foreach ($tables as $row) {
            $tableCount++;
            try {
                $objectCount += (int) DB::query(
                    sprintf('SELECT COUNT(*) AS "cnt" FROM "%s"', $row['TABLE_NAME'])
                )->value();
            } catch (\Throwable) {
                // Skip tables without access
            }
        }

        return [
            'object_count' => $objectCount,
            'table_count'  => $tableCount,
        ];
    }

    // --- Users ------------------------------------------------------------------

    private function countMembers(): int
    {
        try {
            return (int) Member::get()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countAdmins(): int
    {
        try {
            $adminGroup = Group::get()->filter('Code', 'administrators')->first();

            return $adminGroup
                ? (int) $adminGroup->Members()->count()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    // --- System -----------------------------------------------------------------

    private function resolveEnvironment(): string
    {
        return (string) (Environment::getEnv('SS_ENVIRONMENT_TYPE') ?? 'unknown');
    }

    private function diskFreeGb(): float
    {
        $bytes = @disk_free_space(BASE_PATH);

        return $bytes !== false
            ? round($bytes / 1_073_741_824, 2)
            : -1;
    }

    private function diskTotalGb(): float
    {
        $bytes = @disk_total_space(BASE_PATH);

        return $bytes !== false
            ? round($bytes / 1_073_741_824, 2)
            : -1;
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    private function authenticate(HTTPRequest $request): void
    {
        $secret   = (string) (Environment::getEnv('MONITORING_SECRET_KEY') ?? '');
        $provided = (string) ($request->getVar('key') ?? '');

        if (empty($secret)) {
            $this->jsonError(500, 'MONITORING_SECRET_KEY not configured on this server.');
        }

        if (!hash_equals($secret, $provided)) {
            $this->jsonError(403, 'Forbidden – invalid key.');
        }
    }

    private function jsonError(int $code, string $message): never
    {
        $response = HTTPResponse::create(
            json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            $code
        );
        $response->addHeader('Content-Type', 'application/json');
        $response->output();
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function jsonResponse(array $data, int $code = 200): HTTPResponse
    {
        $response = HTTPResponse::create(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            $code
        );
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }
}
