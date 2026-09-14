<?php
declare(strict_types=1);

namespace Itools\SvgValidator\Tests\Unit;

use Itools\SvgValidator\Result;
use Itools\SvgValidator\SvgValidator;
use Itools\SvgValidator\Tests\Support\SvgValidatorTestCase;
use Itools\SvgValidator\Violation;

/**
 * The shape of what comes back: Result, Violation and its templates, how the error
 * list is deduplicated, ordered and capped, and what rules() returns.
 */
class ResultTest extends SvgValidatorTestCase
{
    //region Result

    public function testOkMeansNoErrors(): void
    {
        $result = new Result([]);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function testAnyErrorMeansNotOk(): void
    {
        $result = new Result([new Violation('element-not-allowed', 'script')]);
        $this->assertFalse($result->ok);
        $this->assertCount(1, $result->errors);
    }

    public function testAcceptedSvgGivesAnEmptyList(): void
    {
        $result = SvgValidator::checkString($this->svg('<rect width="1" height="1"/>'));
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    //endregion
    //region Violation

    public function testFields(): void
    {
        $violation = new Violation('element-not-allowed', 'foreignObject');
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('foreignObject', $violation->detail);
        $this->assertSame('<%s> is not allowed in uploaded SVGs', $violation->template);
        $this->assertSame('<foreignObject> is not allowed in uploaded SVGs', $violation->message);
    }

    /** Every template takes exactly one %s and nothing else printf would interpret, so sprintf(t($template), $detail) always works. */
    public function testEveryTemplateHasOnePlaceholder(): void
    {
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertSame(1, substr_count($template, '%s'), "$code has one %s");
            $this->assertDoesNotMatchRegularExpression('/%(?!s)/', $template, "$code has no other % directive");
        }
    }

    public function testMessageIsTemplateWithDetail(): void
    {
        $violation = $this->assertRejects($this->svg('<use href="https://evil.example/x.svg#a"/>'), 'href-not-allowed');
        $this->assertSame(sprintf($violation->template, $violation->detail), $violation->message);
    }

    /** The recipe from the class docblock: translate the template, then put the encoded detail back in. */
    public function testTranslationRecipe(): void
    {
        $translations = [Violation::TEMPLATES['element-not-allowed'] => '<%s> ist in hochgeladenen SVGs nicht erlaubt'];
        $violation    = $this->assertRejects($this->svg('<script/>'), 'element-not-allowed');
        $this->assertSame('<script> ist in hochgeladenen SVGs nicht erlaubt', sprintf($translations[$violation->template], htmlspecialchars($violation->detail)));
    }

    /** detail and message carry the file's text as-is; callers HTML-encode before output. */
    public function testDetailIsNotEncoded(): void
    {
        $violation = $this->assertRejects('a<b>c', 'not-svg');
        $this->assertSame('a<b>c', $violation->detail);
        $this->assertSame('This is not an SVG file: it starts with a<b>c', $violation->message);
    }

    //endregion
    //region The Error List

    public function testTheSameProblemIsReportedOnce(): void
    {
        $result = SvgValidator::checkString($this->svg('<script/><script/><g><script/></g>'));
        $this->assertCount(1, $result->errors);
    }

    public function testDistinctProblemsInFileOrder(): void
    {
        $result = SvgValidator::checkString($this->svg('<script/><foreignObject/><rect onclick="x"/><script/>'));
        $this->assertSame(['element-not-allowed', 'element-not-allowed', 'event-handler'], array_column($result->errors, 'code'));
        $this->assertSame(['script', 'foreignObject', 'onclick'], array_column($result->errors, 'detail'));
    }

    public function testAtMostFiftyErrors(): void
    {
        $body = '';
        for ($i = 1; $i <= 60; $i++) {
            $body .= "<bad$i/>";
        }
        $result = SvgValidator::checkString($this->svg($body));
        $this->assertCount(50, $result->errors);
        $this->assertSame('bad1', $result->errors[0]->detail);
        $this->assertSame('bad50', $result->errors[49]->detail);
    }

    public function testErrorsAreViolationsWithKnownCodes(): void
    {
        $result = SvgValidator::checkString($this->svg('<script/><rect onclick="x" style="@import x"/>'));
        foreach ($result->errors as $violation) {
            $this->assertInstanceOf(Violation::class, $violation);
            $this->assertArrayHasKey($violation->code, Violation::TEMPLATES);
        }
    }

    //endregion
    //region rules()

    public function testRulesLists(): void
    {
        $rules = SvgValidator::rules();
        $this->assertSame(['elements', 'attributes', 'namespacedAttributes', 'inertNamespaces', 'inertProcessingInstructions', 'imageElements', 'dataImageTypes'], array_keys($rules));
        $this->assertContains('svg', $rules['elements']);
        $this->assertNotContains('script', $rules['elements']);
        $this->assertNotContains('foreignObject', $rules['elements']);
        $this->assertSame([], preg_grep('/^on/i', $rules['attributes']));
        $this->assertSame(['id', 'lang', 'space'], $rules['namespacedAttributes']['http://www.w3.org/XML/1998/namespace']);
        $this->assertSame(['href', 'title'], $rules['namespacedAttributes']['http://www.w3.org/1999/xlink']);
        $this->assertSame(['image', 'feImage'], $rules['imageElements']);
        $this->assertSame(['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg+xml'], $rules['dataImageTypes']);
    }

    //endregion
}
