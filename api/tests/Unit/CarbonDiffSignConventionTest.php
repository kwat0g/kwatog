<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pins Carbon's diff sign convention, because a whole class of bugs depends on it.
 *
 * Carbon 2 returned diffIn* as an ABSOLUTE magnitude. Carbon 3 (nesbot/carbon
 * 3.x, pulled in by Laravel 12) returns it SIGNED, as `argument - receiver`, and
 * as a float rather than an int. Upgrading therefore silently inverted every
 * comparison written as `$later->diffInX($earlier) < $threshold`, because the
 * left-hand side became negative and the comparison became permanently true.
 *
 * Four such sites were found and fixed:
 *
 *   PunchSessionizer      an employee's whole punch file collapsed into one day
 *   MrpEngineService      every MRP work order came out urgent
 *   DowntimeAnalytics     MTBF pinned at 0, availability pinned at null
 *   AlertEngineService    the critical AR-overdue alert could never fire
 *
 * None of them threw. They produced quietly wrong business output, which is why
 * they survived a 1,839-test suite.
 *
 * A static guard was prototyped alongside those fixes and deliberately rejected:
 * whether a given call is safe depends on which operand is the earlier instant,
 * which is semantic rather than syntactic. Scanning for an inline comparison
 * flagged five sites and all five were correct, and the broader "no bare diffIn*"
 * rule covered 44 sites — too large an allowlist to be a guard rather than churn.
 *
 * That objection has since been removed from the other side. Every diffIn* call
 * under app/ was audited and made explicit — `, true` where the receiver is
 * provably the earlier instant, `, false` where the sign is load-bearing — so the
 * broad rule now needs no allowlist at all, and
 * {@see self::test_no_diff_call_leaves_its_sign_convention_implicit()} enforces
 * it. The tests below still pin the ASSUMPTION those annotations encode: if a
 * future upgrade changes the convention again, this fails first.
 */
class CarbonDiffSignConventionTest extends TestCase
{
    private const EARLIER = '2026-08-01 00:00:00';

    private const LATER = '2026-08-11 00:00:00';

    public function test_diff_is_positive_when_the_argument_is_later_than_the_receiver(): void
    {
        $earlier = Carbon::parse(self::EARLIER);
        $later = Carbon::parse(self::LATER);

        // This is the safe direction, and the one every fixed site now uses.
        $this->assertSame(10.0, $earlier->diffInDays($later));
    }

    public function test_diff_is_negative_when_the_argument_is_earlier_than_the_receiver(): void
    {
        $earlier = Carbon::parse(self::EARLIER);
        $later = Carbon::parse(self::LATER);

        // This is the trap. Under Carbon 2 this returned +10.0.
        $this->assertSame(-10.0, $later->diffInDays($earlier));
    }

    public function test_a_signed_diff_defeats_a_naive_upper_bound_comparison(): void
    {
        // The exact shape of the PunchSessionizer defect: a session boundary that
        // can never be crossed, because the left-hand side is always negative.
        $firstIn = Carbon::parse(self::EARLIER);
        $tenDaysLater = Carbon::parse(self::LATER);

        $this->assertTrue(
            $tenDaysLater->diffInHours($firstIn) < 18,
            'ten days apart still satisfies "< 18 hours" when the diff is signed'
        );

        $this->assertFalse(
            abs($tenDaysLater->diffInHours($firstIn)) < 18,
            'abs() is what makes the bound mean what it reads as'
        );
    }

    public function test_diff_returns_a_float_not_an_int(): void
    {
        // Callers that store the result in an int column or compare with
        // assertSame must cast. Carbon 2 returned int.
        $this->assertIsFloat(Carbon::parse(self::EARLIER)->diffInDays(Carbon::parse(self::LATER)));
    }

    /**
     * Every diffIn* call under app/ must state its sign convention out loud.
     *
     * Why explicitness, rather than a rule about which operand goes where: the
     * four production defects above were all invisible at the call site. Read
     * `$later->diffInHours($earlier) < 18` and it says what the author meant; it
     * just stopped being true. Nothing in the syntax records whether the author
     * wanted a magnitude or a signed offset, so a reviewer diffing a Carbon
     * upgrade has no way to tell a correct call from a broken one, and the
     * regression is silent — wrong numbers, no exception.
     *
     * A second argument fixes that. `, true` asserts "this is a magnitude, and I
     * have checked the receiver is the earlier instant"; `, false` asserts "the
     * sign carries meaning here, do not fold it". Both survive an upgrade,
     * because both are explicit about the thing the upgrade changed. `abs(...)`
     * counts too — it makes the same claim as `, true`, one level out.
     *
     * The bar is deliberately syntactic and not semantic. This test cannot know
     * which operand is earlier, and does not try; that judgement belongs to the
     * author and the reviewer. What it can do is guarantee the judgement was
     * MADE and is on the page, which is precisely what the four bugs lacked.
     *
     * There is no allowlist. Every one of the 54 call sites present when this
     * guard was written could be annotated without changing behaviour, so an
     * allowlist would only be a place for the rule to erode. If you are here
     * because a genuinely undecidable site appeared, prefer a named local
     * variable and a comment over widening the guard.
     *
     * Note the matching is token-based, not line-based. A line-based regex gets
     * three of the existing sites wrong: two wrap the call in a multi-line
     * `abs(` (ReturnWidgetAnalytics, SupplyChainWidgetAnalytics) and one puts
     * `false` on its own line (EmployeeSkillService). It would also flag the
     * explanatory comments left at the four fixed defects.
     */
    public function test_no_diff_call_leaves_its_sign_convention_implicit(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $this->assertDirectoryExists($appPath, 'Cannot audit diffIn* calls without app/.');

        $violations = [];
        foreach ($this->phpFilesIn($appPath) as $file) {
            foreach ($this->implicitDiffCalls($file) as $violation) {
                $violations[] = $violation;
            }
        }

        $this->assertSame([], $violations, $this->describe($violations));
    }

    /**
     * Locates diffIn* calls in one file that pass no second argument and are not
     * enclosed in abs().
     *
     * Uses PHP's own tokenizer so comments and string literals are skipped for
     * free, and so paren balancing works across line breaks.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    private function implicitDiffCalls(string $file): array
    {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'diffIn')) {
            return [];
        }

        $tokens = token_get_all($source);
        $lines = explode("\n", $source);
        $found = [];

        // One frame per open paren, holding the identifier that preceded it.
        /** @var list<string> $frames */
        $frames = [];

        foreach ($tokens as $index => $token) {
            if ($token === '(') {
                $frames[] = $this->identifierBefore($tokens, $index);

                continue;
            }
            if ($token === ')') {
                array_pop($frames);

                continue;
            }
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (preg_match('/^diffIn[A-Za-z]+$/', $token[1]) !== 1) {
                continue;
            }
            $paren = $this->nextSignificant($tokens, $index);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;   // a method reference, not a call
            }
            // `, true` / `, false` at the top level of the argument list.
            if ($this->hasSecondArgument($tokens, $paren)) {
                continue;
            }
            // abs(...) one or more levels out makes the same claim as `, true`.
            if (in_array('abs', $frames, true)) {
                continue;
            }

            $line = (int) $token[2];
            $found[] = [
                'file' => $file,
                'line' => $line,
                'text' => trim($lines[$line - 1] ?? ''),
            ];
        }

        return $found;
    }

    /**
     * True when the call opened at $parenIndex has a comma at its own nesting
     * depth — i.e. a second argument rather than a comma belonging to a nested
     * call.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function hasSecondArgument(array $tokens, int $parenIndex): bool
    {
        $depth = 0;
        for ($i = $parenIndex, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    return false;   // reached the matching close paren
                }
            } elseif ($token === ',' && $depth === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identifier immediately before $parenIndex, or '' if the paren does not
     * follow one (grouping parens, casts, control structures).
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function identifierBefore(array $tokens, int $parenIndex): string
    {
        for ($i = $parenIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : '';
        }

        return '';
    }

    /**
     * Index of the next token that is not whitespace or a comment.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @return list<string> */
    private function phpFilesIn(string $path): array
    {
        $files = [];
        $walker = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walker as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @param list<array{file: string, line: int, text: string}> $violations */
    private function describe(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        $root = dirname(__DIR__, 2).'/';
        $lines = array_map(
            static fn (array $v): string => sprintf(
                "  %s:%d\n    %s",
                str_replace($root, '', $v['file']),
                $v['line'],
                $v['text'],
            ),
            $violations,
        );

        return sprintf(
            "%d diffIn* call(s) leave the sign convention implicit:\n\n%s\n\n".
            "Carbon 3 returns diffIn* SIGNED, as `argument - receiver`. Add an\n".
            "explicit second argument at each site above:\n".
            "  `, true`  a magnitude — only when the receiver is provably the\n".
            "            earlier instant, otherwise swap the operands first\n".
            "  `, false` the sign is meaningful (an overdue countdown, an offset\n".
            "            that will be subtracted from another offset)\n".
            "Wrapping the call in abs() also satisfies this guard.\n".
            "Do not pick one to silence the failure — picking wrong is silent in\n".
            'production, which is how four of these shipped. See this class docblock.',
            count($violations),
            implode("\n", $lines),
        );
    }
}
