<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Keep-alive pings (the server's own self-ping, UptimeRobot, Render health checks) must stay
 * cheap: no session, no cookie and no database query. Otherwise every ping would wake the
 * Neon database and use up its free compute hours, and the database would stop mid-month.
 */
class KeepAlivePingTest extends TestCase
{
    public static function statusUrls(): array
    {
        return [
            'web ping'   => ['/ping'],
            'web health' => ['/health'],
            'home'       => ['/'],
            'api ping'   => ['/api/ping'],
            'api health' => ['/api/health'],
        ];
    }

    #[DataProvider('statusUrls')]
    public function test_status_url_answers_without_session_or_database(string $url): void
    {
        // Laravel uses the database session driver when SESSION_DRIVER is not set (as on Render).
        // There is no sessions table in this test database, so starting a session would fail.
        config(['session.driver' => 'database']);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->get($url);

        $response->assertOk()->assertJsonStructure(['status', 'timestamp']);
        $response->assertCookieMissing(config('session.cookie'));
        $this->assertSame(0, $queries, "{$url} ran {$queries} database queries");
    }
}
