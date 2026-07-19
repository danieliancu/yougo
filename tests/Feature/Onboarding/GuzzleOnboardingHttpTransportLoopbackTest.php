<?php

namespace Tests\Feature\Onboarding;

use App\Services\Onboarding\DnsOnboardingHostResolver;
use App\Services\Onboarding\Fetcher\GuzzleOnboardingHttpTransport;
use App\Services\Onboarding\Fetcher\HttpWebsiteSourceFetcher;
use App\Services\Onboarding\OnboardingUrlNormalizer;
use App\Services\Onboarding\OnboardingUrlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\TestCase;

/**
 * Real-socket smoke test: proves DNS pinning actually reaches curl and a real
 * connection is made, not just that an option array looks right (which is all the
 * fast unit-level SpyOnboardingHttpTransport tests can prove). Uses PHP's built-in
 * web server on 127.0.0.1 — zero public internet access. Loopback is normally
 * rejected by OnboardingUrlValidator by design; this test enables the dedicated
 * testing-only config flag (config/onboarding.php: crawl.allow_private_networks_for_testing)
 * for the duration of the test only.
 */
class GuzzleOnboardingHttpTransportLoopbackTest extends TestCase
{
    use RefreshDatabase;

    private $serverProcess;

    private ?int $port = null;

    private ?string $docRoot = null;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        if ($this->docRoot && is_dir($this->docRoot)) {
            @unlink($this->docRoot.'/index.html');
            @rmdir($this->docRoot);
        }

        parent::tearDown();
    }

    public function test_fetch_against_a_real_local_server_uses_the_pinned_ip_connection(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment.');
        }

        $phpBinary = (new PhpExecutableFinder)->find();

        if (! $phpBinary) {
            $this->markTestSkipped('No PHP CLI binary available to start a local server.');
        }

        $this->port = random_int(20000, 40000);
        $this->docRoot = sys_get_temp_dir().'/yougo-onboarding-loopback-'.uniqid();
        mkdir($this->docRoot);
        file_put_contents($this->docRoot.'/index.html', '<html><body>loopback fixture ok</body></html>');

        $this->serverProcess = proc_open(
            [$phpBinary, '-S', "127.0.0.1:{$this->port}", '-t', $this->docRoot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($this->serverProcess)) {
            $this->markTestSkipped('Could not start the local PHP built-in server.');
        }

        // Give the built-in server a brief moment to start listening.
        usleep(300_000);

        config(['onboarding.crawl.allow_private_networks_for_testing' => true]);

        $resolver = new DnsOnboardingHostResolver;
        $fetcher = new HttpWebsiteSourceFetcher(
            new GuzzleOnboardingHttpTransport,
            new OnboardingUrlValidator($resolver),
            new OnboardingUrlNormalizer,
        );

        $document = $fetcher->fetch("http://127.0.0.1:{$this->port}/", ['text/html'], 1_000_000);

        $this->assertStringContainsString('loopback fixture ok', $document->body);
        $this->assertSame(200, $document->statusCode);
    }
}
