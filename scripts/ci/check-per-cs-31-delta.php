<?php

declare(strict_types=1);

namespace Maatify\Product\Tooling;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Verifies the repository-owned normative differences between PER-CS 3.0 and 3.1.
 *
 * The verifier is intentionally lexical and token-aware. It never rewrites source,
 * parses external input, or relies on a package-specific transitive dependency.
 */
final class PerCs31DeltaVerifier
{
    /**
     * @param list<string> $paths
     * @return int 0 when the delta is clean, 1 for violations, or 2 for execution errors.
     */
    public static function run(array $paths): int
    {
        try {
            $files = self::collectFiles($paths);
            if ($files === []) {
                throw new RuntimeException('No PHP files found in the configured verification scope.');
            }

            $violations = [];
            $advisories = [];
            foreach ($files as $file) {
                $source = file_get_contents($file);
                if ($source === false) {
                    throw new RuntimeException('Unable to read ' . $file . '.');
                }

                self::scanFile($file, $source, $violations, $advisories);
            }

            sort($violations);
            sort($advisories);
            foreach ($advisories as $advisory) {
                fwrite(STDOUT, 'ADVISORY ' . $advisory . PHP_EOL);
            }
            foreach ($violations as $violation) {
                fwrite(STDERR, 'VIOLATION ' . $violation . PHP_EOL);
            }

            if ($violations !== []) {
                fwrite(STDERR, sprintf(
                    'PER-CS 3.1 delta verification failed: %d violation(s) in %d file(s).%s',
                    count($violations),
                    count($files),
                    PHP_EOL,
                ));

                return 1;
            }

            fwrite(STDOUT, sprintf(
                'PER-CS 3.1 delta verification passed for %d file(s).%s',
                count($files),
                PHP_EOL,
            ));

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'PER-CS 3.1 delta verifier error: ' . $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function collectFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = self::normalizePath($path);
                continue;
            }

            if (!is_dir($path)) {
                throw new RuntimeException('Verification path does not exist: ' . $path . '.');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                    continue;
                }

                $file = self::normalizePath($entry->getPathname());
                if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                    continue;
                }
                if (preg_match('~(?:^|/)(?:vendor|\.git)(?:/|$)~', $file) === 1) {
                    continue;
                }

                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $violations
     * @param list<string> $advisories
     */
    private static function scanFile(
        string $file,
        string $source,
        array &$violations,
        array &$advisories,
    ): void {
        $tokens = self::lex($source);

        self::scanCloneCalls($file, $source, $tokens, $advisories);
        self::scanSwitches($file, $source, $tokens, $violations);
        self::scanPipes($file, $source, $tokens, $violations);
        self::scanEmptyClosures($file, $source, $tokens, $violations);
        self::scanAnonymousClassAttributes($file, $source, $tokens, $violations);
        self::scanEnumConstants($file, $tokens, $violations);
        self::scanMultilineArrays($file, $source, $tokens, $violations);
        self::scanHeaderOrder($file, $tokens, $violations);
        self::scanNamespacedDeclarations($file, $tokens, $violations);
        self::scanPsr1Naming($file, $tokens, $violations);
    }

    /**
     * PER-CS header block order: the file-level docblock comes right after the opening tag and
     * before every `declare`, `namespace`, and `use` statement. Within the header region (the
     * opening tag and those statements, up to the first other statement), a doc comment that follows
     * one of those statements is reported, unless it documents the declaration that follows it
     * (a class-like, function, constant, or attribute) or is a single inline `@var` annotation of
     * the statement that follows it.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanHeaderOrder(string $file, array $tokens, array &$violations): void
    {
        $headerStatementSeen = false;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (in_array($token['id'], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            if ($token['id'] === T_DOC_COMMENT) {
                if (!$headerStatementSeen) {
                    continue;
                }
                $next = self::nextMeaningful($tokens, $index + 1);
                $documentsDeclaration = $next !== null && (
                    in_array($tokens[$next]['id'], [
                        T_ABSTRACT, T_FINAL, T_READONLY, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
                        T_FUNCTION, T_CONST, T_ATTRIBUTE,
                    ], true)
                );
                $inlineVar = preg_match('~\A/\*\*\s*@(?:phpstan-|psalm-)?var\s[^\n]*\*/\z~', $token['text']) === 1;
                if (!$documentsDeclaration && !$inlineVar) {
                    self::addViolation(
                        $violations,
                        $file,
                        $token['line'],
                        'header-docblock-order',
                        'The file-level docblock MUST precede the declare, namespace, and use statements.',
                    );
                }

                return;
            }
            if (in_array($token['id'], [T_DECLARE, T_NAMESPACE, T_USE], true)) {
                $end = self::nextText($tokens, $index + 1, ';');
                $brace = self::nextText($tokens, $index + 1, '{');
                if ($end === null || ($brace !== null && $brace < $end)) {
                    return;
                }
                $headerStatementSeen = true;
                $index = $end;
                continue;
            }

            return;
        }
    }

    /**
     * Every class, interface, trait, and enum declaration MUST be inside a namespace. Anonymous
     * classes and `::class` constants are not declarations.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanNamespacedDeclarations(string $file, array $tokens, array &$violations): void
    {
        $namespaced = false;
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE) {
                $next = self::nextMeaningful($tokens, $index + 1);
                if ($next !== null && in_array($tokens[$next]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespaced = true;
                }
                continue;
            }
            if (!in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            $previous = self::previousMeaningful($tokens, $index - 1);
            if ($previous !== null && in_array($tokens[$previous]['id'], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            if ($token['id'] === T_CLASS && $previous !== null && $tokens[$previous]['text'] === ')') {
                continue;
            }
            $name = self::nextMeaningful($tokens, $index + 1);
            if ($name === null || $tokens[$name]['id'] !== T_STRING) {
                continue;
            }
            if (!$namespaced) {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'namespace-required',
                    'Every class, interface, trait, and enum MUST be declared inside a namespace.',
                );
            }
        }
    }

    /**
     * PSR-1 naming, which PER-CS extends: a class, interface, trait, or enum name is StudlyCaps; a
     * class constant name is upper case with underscore separators; a method name is camelCase.
     * Enum cases are not constants and are not checked. Magic methods (`__construct` and the like)
     * keep their reserved double-underscore prefix. Anonymous class bodies are checked like named
     * ones; functions nested deeper than a class body (closures) are not methods.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanPsr1Naming(string $file, array $tokens, array &$violations): void
    {
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            $previous = self::previousMeaningful($tokens, $index - 1);
            if ($previous !== null && $tokens[$previous]['id'] === T_DOUBLE_COLON) {
                continue;
            }
            $anonymous = $previous !== null && $tokens[$previous]['id'] === T_NEW;
            $cursor = self::nextMeaningful($tokens, $index + 1);
            if ($cursor === null) {
                continue;
            }
            if (!$anonymous) {
                if ($tokens[$cursor]['id'] !== T_STRING) {
                    continue;
                }
                if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $tokens[$cursor]['text']) !== 1) {
                    self::addViolation(
                        $violations,
                        $file,
                        $tokens[$cursor]['line'],
                        'psr1-class-name',
                        sprintf('Class, interface, trait, and enum names MUST be declared in StudlyCaps; found "%s".', $tokens[$cursor]['text']),
                    );
                }
            } elseif ($tokens[$cursor]['text'] === '(') {
                $cursor = self::matching($tokens, $cursor, '(', ')');
                if ($cursor === null) {
                    continue;
                }
            }
            $open = self::nextText($tokens, $cursor, '{');
            $close = $open === null ? null : self::matchingBrace($tokens, $open);
            if ($open === null || $close === null) {
                continue;
            }
            self::scanClassBodyNames($file, $tokens, $open, $close, $violations);
        }
    }

    /**
     * Checks the constants and methods declared directly in one class-like body.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanClassBodyNames(string $file, array $tokens, int $open, int $close, array &$violations): void
    {
        $depth = 0;
        for ($member = $open + 1; $member < $close; $member++) {
            $text = $tokens[$member]['text'];
            if (in_array($tokens[$member]['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) || ($tokens[$member]['id'] === null && $text === '{')) {
                $depth++;
                continue;
            }
            if ($tokens[$member]['id'] === null && $text === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($tokens[$member]['id'] === T_FUNCTION) {
                $name = self::nextMeaningful($tokens, $member + 1, $close);
                if ($name !== null && $tokens[$name]['text'] === '&') {
                    $name = self::nextMeaningful($tokens, $name + 1, $close);
                }
                if ($name === null || $tokens[$name]['text'] === '(') {
                    continue;
                }
                if (preg_match('/\A(?:__)?[a-z][A-Za-z0-9]*\z/', $tokens[$name]['text']) !== 1) {
                    self::addViolation(
                        $violations,
                        $file,
                        $tokens[$name]['line'],
                        'psr1-method-name',
                        sprintf('Method names MUST be declared in camelCase; found "%s".', $tokens[$name]['text']),
                    );
                }
                continue;
            }
            if ($tokens[$member]['id'] !== T_CONST) {
                continue;
            }
            $nesting = 0;
            for ($cursor = $member + 1; $cursor < $close; $cursor++) {
                $piece = $tokens[$cursor]['text'];
                if ($tokens[$cursor]['id'] === null && in_array($piece, ['(', '[', '{'], true)) {
                    $nesting++;
                } elseif ($tokens[$cursor]['id'] === null && in_array($piece, [')', ']', '}'], true)) {
                    $nesting--;
                } elseif ($nesting === 0 && $tokens[$cursor]['id'] === null && $piece === ';') {
                    break;
                } elseif ($nesting === 0 && $tokens[$cursor]['id'] === null && $piece === '=') {
                    $name = self::previousMeaningful($tokens, $cursor - 1);
                    if ($name !== null && preg_match('/\A[A-Z][A-Z0-9_]*\z/', $tokens[$name]['text']) !== 1) {
                        self::addViolation(
                            $violations,
                            $file,
                            $tokens[$name]['line'],
                            'psr1-constant-name',
                            sprintf('Class constants MUST be declared in upper case with underscore separators; found "%s".', $tokens[$name]['text']),
                        );
                    }
                }
            }
            $member = $cursor;
        }
    }

    /**
     * The closing brace that matches an opening `{`, counting the `{` of string interpolation.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            $id = $tokens[$index]['id'];
            if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) || ($id === null && $tokens[$index]['text'] === '{')) {
                $depth++;
            } elseif ($id === null && $tokens[$index]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int|null, text: string, line: int, offset: int, end: int}>
     */
    private static function lex(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $line = 1;
        foreach (token_get_all($source) as $rawToken) {
            if (is_array($rawToken)) {
                $text = $rawToken[1];
                $tokenLine = $rawToken[2];
                $id = $rawToken[0];
            } else {
                $text = $rawToken;
                $tokenLine = $line;
                $id = null;
            }

            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'line' => $tokenLine,
                'offset' => $offset,
                'end' => $offset + strlen($text),
            ];
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }

        return self::normalizePipeTokens($tokens);
    }

    /**
     * Normalizes PHP 8.4's adjacent raw pipe tokens to the PHP 8.5 T_PIPE
     * representation while keeping separated or commented forms invalid.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return list<array{id: int|null, text: string, line: int, offset: int, end: int}>
     */
    private static function normalizePipeTokens(array $tokens): array
    {
        $normalized = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token['id'] === null && $token['text'] === '|') {
                $next = $index + 1;
                if ($next < $count
                    && $tokens[$next]['id'] === null
                    && $tokens[$next]['text'] === '>'
                    && $token['end'] === $tokens[$next]['offset']
                ) {
                    $token['text'] = '|>';
                    $token['end'] = $tokens[$next]['end'];
                    $normalized[] = $token;
                    $index = $next;
                    continue;
                }

                if ($next < $count && $tokens[$next]['id'] === T_WHITESPACE) {
                    $afterWhitespace = $next + 1;
                    if ($afterWhitespace < $count
                        && $tokens[$afterWhitespace]['id'] === null
                        && $tokens[$afterWhitespace]['text'] === '>'
                    ) {
                        $token['text'] = '| >';
                        $token['end'] = $tokens[$afterWhitespace]['end'];
                        $normalized[] = $token;
                        $index = $afterWhitespace;
                        continue;
                    }
                }
            }

            $normalized[] = $token;
        }

        return $normalized;
    }

    /**
     * Reports the PER-CS 3.1 clone recommendation without turning SHOULD into a hard failure.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $advisories
     */
    private static function scanCloneCalls(
        string $file,
        string $source,
        array $tokens,
        array &$advisories,
    ): void {
        unset($source);
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_CLONE) {
                continue;
            }

            $next = self::nextMeaningful($tokens, $index + 1);
            if ($next === null || $tokens[$next]['text'] === '(') {
                continue;
            }

            $advisories[] = self::location($file, $token['line'], 'clone-parentheses')
                . 'clone() SHOULD use parentheses.';
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanSwitches(
        string $file,
        string $source,
        array $tokens,
        array &$violations,
    ): void {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_SWITCH) {
                continue;
            }

            $open = self::findSwitchOpeningBrace($tokens, $index);
            if ($open === null) {
                continue;
            }
            $close = self::matching($tokens, $open, '{', '}');
            if ($close === null) {
                self::addViolation($violations, $file, $token['line'], 'switch-body', 'Unclosed switch body.');
                continue;
            }

            $cases = self::directCases($tokens, $open, $close);
            foreach ($cases as $casePosition => $caseIndex) {
                $colon = self::caseColon($tokens, $caseIndex, $close);
                if ($colon === null) {
                    self::addViolation($violations, $file, $tokens[$caseIndex]['line'], 'switch-case', 'Case condition has no colon.');
                    continue;
                }

                self::checkCaseCondition($file, $source, $tokens, $caseIndex, $colon, $violations);

                $nextCase = $cases[$casePosition + 1] ?? $close;
                $bodyStart = self::nextMeaningful($tokens, $colon + 1);
                if ($bodyStart === null || $bodyStart >= $nextCase) {
                    continue;
                }
                if ($tokens[$bodyStart]['text'] === '{') {
                    self::addViolation(
                        $violations,
                        $file,
                        $tokens[$bodyStart]['line'],
                        'switch-case-braces',
                        'A case body MUST NOT be wrapped in braces.',
                    );
                }

                if (self::caseIsEmpty($tokens, $colon + 1, $nextCase)) {
                    continue;
                }
                if (self::hasFallthroughComment($tokens, $colon + 1, $nextCase)) {
                    continue;
                }
                if (!self::caseTerminates($tokens, $colon + 1, $nextCase)) {
                    self::addViolation(
                        $violations,
                        $file,
                        $tokens[$caseIndex]['line'],
                        'switch-case-termination',
                        'Every non-empty case MUST end with a terminating statement or a clear fall-through comment.',
                    );
                }
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function findSwitchOpeningBrace(array $tokens, int $switchIndex): ?int
    {
        $parentheses = 0;
        for ($index = $switchIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $text = $tokens[$index]['text'];
            if ($text === '(') {
                $parentheses++;
                continue;
            }
            if ($text === ')') {
                $parentheses--;
                continue;
            }
            if ($text === '{' && $parentheses === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return list<int>
     */
    private static function directCases(array $tokens, int $open, int $close): array
    {
        $cases = [];
        $depth = 0;
        for ($index = $open + 1; $index < $close; $index++) {
            $text = $tokens[$index]['text'];
            if ($text === '{') {
                $depth++;
                continue;
            }
            if ($text === '}') {
                $depth--;
                continue;
            }
            if ($depth === 0 && in_array($tokens[$index]['id'], [T_CASE, T_DEFAULT], true)) {
                $cases[] = $index;
            }
        }

        return $cases;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function caseColon(array $tokens, int $caseIndex, int $close): ?int
    {
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;
        for ($index = $caseIndex + 1; $index < $close; $index++) {
            $text = $tokens[$index]['text'];
            if ($text === '(') {
                $parentheses++;
            } elseif ($text === ')') {
                $parentheses--;
            } elseif ($text === '[') {
                $brackets++;
            } elseif ($text === ']') {
                $brackets--;
            } elseif ($text === '{') {
                $braces++;
            } elseif ($text === '}') {
                $braces--;
            } elseif ($text === ':' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function checkCaseCondition(
        string $file,
        string $source,
        array $tokens,
        int $caseIndex,
        int $colon,
        array &$violations,
    ): void {
        if ($tokens[$caseIndex]['id'] === T_DEFAULT) {
            return;
        }

        $first = self::nextMeaningful($tokens, $caseIndex + 1);
        $last = self::previousMeaningful($tokens, $colon - 1);
        if ($first === null || $last === null) {
            return;
        }

        $multiline = $tokens[$last]['line'] > $tokens[$caseIndex]['line']
            || str_contains(
                substr($source, $tokens[$caseIndex]['end'], $tokens[$colon]['offset'] - $tokens[$caseIndex]['end']),
                "\n",
            );
        if (!$multiline) {
            return;
        }

        if ($tokens[$first]['text'] !== '(' || $tokens[$first]['line'] !== $tokens[$caseIndex]['line']) {
            self::addViolation(
                $violations,
                $file,
                $tokens[$caseIndex]['line'],
                'switch-case-condition',
                'A multiline complex case condition MUST open parentheses on the case line.',
            );
        }
        if ($tokens[$last]['text'] !== ')' || trim(self::lineAt($source, $tokens[$last]['offset'])) !== '):') {
            self::addViolation(
                $violations,
                $file,
                $tokens[$caseIndex]['line'],
                'switch-case-condition',
                'A multiline complex case condition MUST close with a line containing only ):.',
            );
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function caseIsEmpty(array $tokens, int $start, int $end): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (!self::ignorable($tokens[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function hasFallthroughComment(array $tokens, int $start, int $end): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (!in_array($tokens[$index]['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match('/\b(?:no\s+break|fall[- ]?through|deliberate(?:ly)?\s+fall)/i', $tokens[$index]['text']) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the last top-level statement, so a return nested inside an if-block
     * cannot be mistaken for termination of the enclosing case.
     *
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function caseTerminates(array $tokens, int $start, int $end): bool
    {
        $segmentStart = $start;
        $lastStatementStart = $start;
        $lastStatementEnd = $end;
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;
        for ($index = $start; $index < $end; $index++) {
            $text = $tokens[$index]['text'];
            if ($text === '(') {
                $parentheses++;
            } elseif ($text === ')') {
                $parentheses--;
            } elseif ($text === '[') {
                $brackets++;
            } elseif ($text === ']') {
                $brackets--;
            } elseif ($text === '{') {
                $braces++;
            } elseif ($text === '}') {
                $braces--;
            } elseif ($text === ';' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                $lastStatementStart = $segmentStart;
                $lastStatementEnd = $index;
                $segmentStart = $index + 1;
            }
        }

        if (self::nextMeaningful($tokens, $segmentStart, $end) !== null) {
            $lastStatementStart = $segmentStart;
            $lastStatementEnd = $end;
        }

        $first = self::nextMeaningful($tokens, $lastStatementStart, $lastStatementEnd);
        if ($first === null) {
            return false;
        }

        return in_array($tokens[$first]['id'], [T_BREAK, T_CONTINUE, T_RETURN, T_THROW, T_GOTO, T_EXIT], true);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanPipes(
        string $file,
        string $source,
        array $tokens,
        array &$violations,
    ): void {
        foreach ($tokens as $index => $token) {
            $isPipeToken = $token['text'] === '|>'
                && ($token['id'] === null || (defined('T_PIPE') && $token['id'] === T_PIPE));
            $isSeparatedPipe = $token['text'] !== '|>'
                && $token['id'] === null
                && str_contains($token['text'], '|')
                && str_contains($token['text'], '>');
            if (!$isPipeToken && !$isSeparatedPipe) {
                continue;
            }

            if ($isSeparatedPipe) {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'pipe-spacing',
                    'The |> operator MUST not contain whitespace between | and >.',
                );
                continue;
            }

            $before = $source[$token['offset'] - 1] ?? '';
            $after = $source[$token['end']] ?? '';
            if ($before === '' || !ctype_space($before) || $after === '' || !ctype_space($after)) {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'pipe-spacing',
                    'The |> operator MUST have at least one space on both sides.',
                );
            }

            $previous = self::previousMeaningful($tokens, $index - 1);
            $next = self::nextMeaningful($tokens, $index + 1);
            if ($previous === null || $next === null) {
                continue;
            }

            $previousLine = $tokens[$previous]['line'];
            $nextLine = $tokens[$next]['line'];
            if ($nextLine > $token['line']) {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'pipe-placement',
                    'A multiline pipe operator MUST not end the preceding line.',
                );
            }

            if ($token['line'] > $previousLine) {
                $prefix = self::linePrefix($source, $token['offset']);
                $baseIndent = self::pipeBaseIndent($source, $tokens, $index);
                $expectedIndent = self::oneIndentDeeper($baseIndent);
                if ($prefix !== $expectedIndent) {
                    self::addViolation(
                        $violations,
                        $file,
                        $token['line'],
                        'pipe-placement',
                        'A multiline pipe operator MUST be indented exactly one level relative to its chain.',
                    );
                }
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function pipeBaseIndent(string $source, array $tokens, int $pipeIndex): string
    {
        $firstPipe = $pipeIndex;
        for ($index = $pipeIndex - 1; $index >= 0; $index--) {
            if (in_array($tokens[$index]['text'], [';', '{', '}'], true)) {
                break;
            }
            if ($tokens[$index]['text'] === '|>') {
                $firstPipe = $index;
            }
        }

        $operand = self::previousMeaningful($tokens, $firstPipe - 1);
        if ($operand === null) {
            return '';
        }

        return self::leadingIndent($source, $tokens[$operand]['offset']);
    }

    private static function oneIndentDeeper(string $baseIndent): string
    {
        return $baseIndent . (str_contains($baseIndent, "\t") ? "\t" : '    ');
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanEmptyClosures(
        string $file,
        string $source,
        array $tokens,
        array &$violations,
    ): void {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_FUNCTION) {
                continue;
            }

            $next = self::nextMeaningful($tokens, $index + 1);
            if ($next === null) {
                continue;
            }
            if ($tokens[$next]['text'] === '&') {
                $next = self::nextMeaningful($tokens, $next + 1);
            }
            if ($next === null || $tokens[$next]['text'] !== '(') {
                continue;
            }

            $parametersEnd = self::matching($tokens, $next, '(', ')');
            if ($parametersEnd === null) {
                continue;
            }
            $bodyOpen = self::nextText($tokens, $parametersEnd + 1, '{');
            if ($bodyOpen === null) {
                continue;
            }
            $bodyClose = self::matching($tokens, $bodyOpen, '{', '}');
            if ($bodyClose === null || self::nextMeaningful($tokens, $bodyOpen + 1, $bodyClose) !== null) {
                continue;
            }

            $previous = self::previousMeaningful($tokens, $bodyOpen - 1);
            $bodyText = substr($source, $tokens[$bodyOpen]['offset'], $tokens[$bodyClose]['end'] - $tokens[$bodyOpen]['offset']);
            if ($previous === null
                || $tokens[$bodyOpen]['line'] !== $tokens[$previous]['line']
                || $bodyText !== '{}'
            ) {
                self::addViolation(
                    $violations,
                    $file,
                    $tokens[$bodyOpen]['line'],
                    'empty-closure',
                    'An empty closure MUST use {} on the same line as its preceding symbol.',
                );
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanAnonymousClassAttributes(
        string $file,
        string $source,
        array $tokens,
        array &$violations,
    ): void {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_NEW) {
                continue;
            }

            $attribute = self::nextMeaningful($tokens, $index + 1);
            if ($attribute === null || $tokens[$attribute]['id'] !== T_ATTRIBUTE) {
                continue;
            }

            $attributeLines = [];
            $attributeIndex = $attribute;
            $valid = $tokens[$attributeIndex]['line'] > $token['line'];
            while ($attributeIndex !== null && $tokens[$attributeIndex]['id'] === T_ATTRIBUTE) {
                $attributeLines[] = $attributeIndex;
                $attributeEnd = self::attributeEnd($tokens, $attributeIndex);
                if ($attributeEnd === null) {
                    $valid = false;
                    break;
                }
                $attributeIndex = self::nextMeaningful($tokens, $attributeEnd + 1);
            }

            if (!$valid || $attributeLines === [] || $attributeIndex === null || $tokens[$attributeIndex]['id'] !== T_CLASS) {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'anonymous-class-attributes',
                    'Attributes on an anonymous class MUST start after new and precede class on following aligned lines.',
                );
                continue;
            }

            $newIndent = self::leadingIndent($source, $token['offset']);
            $expectedIndent = self::oneIndentDeeper($newIndent);
            $attributeIndent = self::linePrefix($source, $tokens[$attributeLines[0]]['offset']);
            $classIndent = self::linePrefix($source, $tokens[$attributeIndex]['offset']);
            $lastAttribute = $attributeLines[array_key_last($attributeLines)];
            $attributesAreCorrectlyIndented = true;
            foreach ($attributeLines as $attributeLine) {
                if ($tokens[$attributeLine]['line'] <= $token['line']
                    || self::linePrefix($source, $tokens[$attributeLine]['offset']) !== $expectedIndent
                ) {
                    $attributesAreCorrectlyIndented = false;
                    break;
                }
            }
            if (!$attributesAreCorrectlyIndented
                || $tokens[$attributeIndex]['line'] <= $tokens[$lastAttribute]['line']
                || $attributeIndent !== $classIndent
                || $classIndent !== $expectedIndent
            ) {
                self::addViolation(
                    $violations,
                    $file,
                    $tokens[$attributeIndex]['line'],
                    'anonymous-class-attributes',
                    'The anonymous class declaration MUST start on a new line aligned with its attributes.',
                );
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function attributeEnd(array $tokens, int $start): ?int
    {
        $depth = 1;
        for ($index = $start + 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['id'] !== null) {
                continue;
            }
            if ($tokens[$index]['text'] === '[') {
                $depth++;
            } elseif ($tokens[$index]['text'] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanEnumConstants(string $file, array $tokens, array &$violations): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_ENUM) {
                continue;
            }

            $open = self::nextText($tokens, $index + 1, '{');
            if ($open === null) {
                continue;
            }
            $close = self::matching($tokens, $open, '{', '}');
            if ($close === null) {
                continue;
            }

            $depth = 0;
            for ($member = $open + 1; $member < $close; $member++) {
                if ($tokens[$member]['text'] === '{') {
                    $depth++;
                    continue;
                }
                if ($tokens[$member]['text'] === '}') {
                    $depth--;
                    continue;
                }
                if ($depth !== 0 || $tokens[$member]['id'] !== T_PROTECTED) {
                    continue;
                }

                $next = self::nextMeaningful($tokens, $member + 1, $close);
                if ($next !== null && $tokens[$next]['id'] === T_CONST) {
                    self::addViolation(
                        $violations,
                        $file,
                        $tokens[$member]['line'],
                        'enum-constant-visibility',
                        'Non-public enum constants MUST use private instead of protected.',
                    );
                }
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @param list<string> $violations
     */
    private static function scanMultilineArrays(
        string $file,
        string $source,
        array $tokens,
        array &$violations,
    ): void {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== null || $token['text'] !== '[' || !self::looksLikeArrayLiteral($tokens, $index)) {
                continue;
            }

            $close = self::matching($tokens, $index, '[', ']');
            if ($close === null || !str_contains(substr($source, $token['end'], $tokens[$close]['offset'] - $token['end']), "\n")) {
                continue;
            }

            if (trim(self::linePrefix($source, $token['offset'])) === '') {
                self::addViolation(
                    $violations,
                    $file,
                    $token['line'],
                    'multiline-array-opening',
                    'The opening bracket of a multiline array MUST not be alone on its line.',
                );
            }
        }
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     */
    private static function looksLikeArrayLiteral(array $tokens, int $index): bool
    {
        $previous = self::previousMeaningful($tokens, $index - 1);
        if ($previous === null) {
            return true;
        }

        if (in_array($tokens[$previous]['text'], ['=', '=>', '(', '[', ',', ':', '?'], true)) {
            return true;
        }

        return in_array($tokens[$previous]['id'], [T_RETURN, T_YIELD, T_PRINT, T_ECHO], true);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function nextMeaningful(array $tokens, int $start, ?int $end = null): ?int
    {
        $end ??= count($tokens);
        for ($index = $start; $index < $end; $index++) {
            if (!self::ignorable($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function previousMeaningful(array $tokens, int $start): ?int
    {
        for ($index = $start; $index >= 0; $index--) {
            if (!self::ignorable($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function nextText(array $tokens, int $start, string $text): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['text'] === $text) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array{id: int|null, text: string, line: int, offset: int, end: int} $token
     */
    private static function ignorable(array $token): bool
    {
        return in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int, end: int}> $tokens
     * @return ?int
     */
    private static function matching(array $tokens, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;
        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['id'] !== null) {
                continue;
            }
            if ($tokens[$index]['text'] === $opening) {
                $depth++;
            } elseif ($tokens[$index]['text'] === $closing) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private static function normalizePath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private static function linePrefix(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");

        return substr($source, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));
    }

    private static function leadingIndent(string $source, int $offset): string
    {
        preg_match('/\A[ \t]*/', self::linePrefix($source, $offset), $matches);

        return $matches[0] ?? '';
    }

    private static function lineAt(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineEnd = strpos($source, "\n", $offset);
        if ($lineEnd === false) {
            $lineEnd = strlen($source);
        }

        return substr($source, $lineStart === false ? 0 : $lineStart + 1, $lineEnd - ($lineStart === false ? 0 : $lineStart + 1));
    }

    /**
     * @param list<string> $violations
     */
    private static function addViolation(
        array &$violations,
        string $file,
        int $line,
        string $rule,
        string $message,
    ): void {
        $violations[] = self::location($file, $line, $rule) . $message;
    }

    private static function location(string $file, int $line, string $rule): string
    {
        return sprintf('%s:%d [%s] ', $file, $line, $rule);
    }
}

$paths = array_slice($argv, 1);
if ($paths === []) {
    $paths = ['.php-cs-fixer.dist.php', 'src', 'tests', 'consumer-verification', 'examples', 'scripts/ci'];
}

exit(PerCs31DeltaVerifier::run($paths));
