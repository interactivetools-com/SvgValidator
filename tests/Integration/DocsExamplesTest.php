<?php
declare(strict_types=1);

namespace Itools\SvgValidator\Tests\Integration;

use Itools\SvgValidator\SvgValidator;
use Itools\SvgValidator\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the doc examples true. The SVG examples are read straight from the pages: every
 * xml fence that ends with an outcome comment is run, and the outcome must match:
 *
 *     <!-- rejected: element-not-allowed, detail "script" -->
 *     <!-- accepted -->
 *
 * The PHP examples cannot be run from the page, so their output comments are mirrored by
 * hand below; editing one of those examples means editing its mirror.
 */
class DocsExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const PAGES_WITH_SVG_EXAMPLES = [
        'docs/what-gets-rejected.md',
        'docs/what-gets-through.md',
    ];

    //region SVG Examples Read From the Pages

    /** @return iterable<string, array{string, string, string}> svg, expected outcome, where it came from */
    public static function svgExampleProvider(): iterable
    {
        foreach (self::PAGES_WITH_SVG_EXAMPLES as $page) {
            $markdown = file_get_contents(self::ROOT . "/$page");
            preg_match_all('/```xml\n(.*?)```/s', $markdown, $fences);
            foreach ($fences[1] as $n => $fence) {
                if (!preg_match('/\n<!-- (accepted|rejected: ([a-z0-9-]+), detail "(.*)") -->\n$/', $fence, $outcome)) {
                    continue;   // an xml fence that is not an example, such as a header snippet
                }
                $svg      = substr($fence, 0, -strlen($outcome[0]) + 1);
                $expected = $outcome[1] === 'accepted' ? 'accepted' : "$outcome[2]: $outcome[3]";
                yield "$page example $n" => [$svg, $expected, "$page example $n"];
            }
        }
    }

    /** @dataProvider svgExampleProvider */
    public function testSvgExampleOutcome(string $svg, string $expected, string $where): void
    {
        $result   = SvgValidator::checkString($svg);
        $reported = array_map(fn(Violation $v) => "$v->code: $v->detail", $result->errors);
        if ($expected === 'accepted') {
            $this->assertTrue($result->ok, "$where should be accepted but reported: " . implode(' | ', $reported));
        } else {
            $this->assertContains($expected, $reported, "$where should report [$expected] but reported: " . implode(' | ', $reported));
        }
    }

    public function testEveryPageHasAtLeastOneExample(): void
    {
        $pages = array_unique(array_map(fn(array $case) => explode(' example ', $case[2])[0], iterator_to_array(self::svgExampleProvider())));
        foreach (self::PAGES_WITH_SVG_EXAMPLES as $page) {
            $this->assertContains($page, $pages, "$page has no runnable xml examples");
        }
    }

    //endregion
    //region PHP Examples Mirrored by Hand

    /** README quick start and getting-started.md: the message for a script element */
    public function testScriptMessage(): void
    {
        $result = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertSame('<script> is not allowed in uploaded SVGs', $result->errors[0]->message);
    }

    /** getting-started.md, Reading a Result: the four Violation fields */
    public function testViolationFields(): void
    {
        $violation = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>')->errors[0];
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('script', $violation->detail);
        $this->assertSame('<%s> is not allowed in uploaded SVGs', $violation->template);
        $this->assertSame('<script> is not allowed in uploaded SVGs', $violation->message);
    }

    /** getting-started.md, Checking a String */
    public function testCheckStringAccepts(): void
    {
        $this->assertTrue(SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>')->ok);
    }

    /** getting-started.md, Showing Errors: the image message */
    public function testImageMessage(): void
    {
        $result = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/photo.jpg"/></svg>');
        $this->assertSame('Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not https://example.com/photo.jpg', $result->errors[0]->message);
    }

    /** common-patterns.md, Logging Rejections */
    public function testLogSummary(): void
    {
        $result  = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script/></svg>');
        $summary = implode(', ', array_map(fn($v) => "$v->code($v->detail)", $result->errors));
        $this->assertSame('event-handler(onload), element-not-allowed(script)', $summary);
    }

    /** common-patterns.md, Checking Files Already on Disk: the two expected rejections */
    public function testLinkedImageAndExternalLink(): void
    {
        $linked = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><image href="photo.jpg"/></svg>');
        $this->assertSame('image-href-not-allowed: photo.jpg', "{$linked->errors[0]->code}: {$linked->errors[0]->detail}");

        $link = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><a href="https://example.com/"><rect/></a></svg>');
        $this->assertSame('href-not-allowed: https://example.com/', "{$link->errors[0]->code}: {$link->errors[0]->detail}");
    }

    /** troubleshooting.md headings that quote a detail: flowRoot, nbsp, PNG bytes, the missing file */
    public function testTroubleshootingHeadings(): void
    {
        $ns = 'xmlns="http://www.w3.org/2000/svg"';
        $this->assertSame('<flowRoot> is not allowed in uploaded SVGs', SvgValidator::checkString("<svg $ns><flowRoot/></svg>")->errors[0]->message);
        $this->assertStringStartsWith("The SVG is not well-formed XML: Entity 'nbsp' not defined", SvgValidator::checkString("<svg $ns><text>a&nbsp;b</text></svg>")->errors[0]->message);
        $this->assertSame('This is not an SVG file: it starts with \211PNG', SvgValidator::checkString("\x89PNG")->errors[0]->message);
        $this->assertSame('Cannot read file php3F.tmp', SvgValidator::checkFile('/tmp/php3F.tmp')->errors[0]->message);
    }

    //endregion
}
