<?php
declare(strict_types=1);

namespace Itools\SvgValidator\Tests\Integration;

use Itools\SvgValidator\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the error codes and the reject fixtures in step: every code in
 * Violation::TEMPLATES has a fixture named after it, and every fixture names a
 * code that still exists, and docs/what-gets-rejected.md has a section for every code.
 */
class ErrorCodesTest extends TestCase
{
    private const REJECT_DIR = __DIR__ . '/../Support/fixtures/reject';

    /** Codes no file on disk can trigger, with the reason, so the exemption cannot go stale unnoticed. */
    private const NO_FIXTURE = [
        'file-unreadable' => 'a fixture that exists is readable',
    ];

    public function testEveryCodeHasARejectFixture(): void
    {
        foreach (array_keys(Violation::TEMPLATES) as $code) {
            if (isset(self::NO_FIXTURE[$code])) {
                continue;
            }
            $this->assertFileExists(self::REJECT_DIR . "/$code-1.svg", "no reject fixture for $code");
        }
    }

    public function testEveryRejectFixtureNamesAKnownCode(): void
    {
        foreach (glob(self::REJECT_DIR . '/*.svg') as $path) {
            $name = basename($path);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+-\d+\.svg$/', $name, "$name is not named <code>-<n>.svg");
            $code = preg_replace('/-\d+\.svg$/', '', $name);
            $this->assertArrayHasKey($code, Violation::TEMPLATES, "$name names a code that does not exist");
        }
    }

    public function testEveryCodeHasASectionInWhatGetsRejected(): void
    {
        $page = file_get_contents(__DIR__ . '/../../docs/what-gets-rejected.md');
        foreach (array_keys(Violation::TEMPLATES) as $code) {
            $this->assertMatchesRegularExpression("/^### .+ - `$code`$/m", $page, "docs/what-gets-rejected.md has no section for $code");
        }
    }

    public function testExemptCodesStillExist(): void
    {
        foreach (array_keys(self::NO_FIXTURE) as $code) {
            $this->assertArrayHasKey($code, Violation::TEMPLATES, "$code is exempt from needing a fixture but is not a code");
        }
    }
}
