<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS\Fixer\PSR2;

use Symfony\CS\AbstractFixer;
use Symfony\CS\Tokenizer\Token;
use Symfony\CS\Tokenizer\Tokens;
use Symfony\CS\Tokenizer\TokensAnalyzer;

/**
 * Fixer for rules defined in PSR2 ¶4.1, ¶4.4, ¶5.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
final class BracesFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function fix(\SplFileInfo $file, Tokens $tokens)
    {
        $this->fixCommentBeforeBrace($tokens);
        $this->fixMissingControlBraces($tokens);
        $this->fixIndents($tokens);
        $this->fixControlContinuationBraces($tokens);
        $this->fixSpaceAroundToken($tokens);
        $this->fixDoWhile($tokens);
        $this->fixLambdas($tokens);
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'The body of each structure MUST be enclosed by braces. Braces should be properly placed. Body of braces should be properly indented.';
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should be run after the ElseIfFixer and DuplicateSemicolonFixer
        return -25;
    }

    private function fixCommentBeforeBrace(Tokens $tokens)
    {
        $controlTokens = $this->getControlTokens();

        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind($controlTokens)) {
                continue;
            }

            $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $index);
            $afterParenthesisIndex = $tokens->getNextNonWhitespace($parenthesisEndIndex);
            $afterParenthesisToken = $tokens[$afterParenthesisIndex];

            if (!$afterParenthesisToken->isComment()) {
                continue;
            }

            $afterCommentIndex = $tokens->getNextMeaningfulToken($afterParenthesisIndex);
            $afterCommentToken = $tokens[$afterCommentIndex];

            if (!$afterCommentToken->equals('{')) {
                continue;
            }

            $tokenTmp = $tokens[$afterCommentIndex];
            $tokens[$afterCommentIndex - 1]->setContent(rtrim($tokens[$afterCommentIndex - 1]->getContent(), " \t"));

            for ($i = $afterCommentIndex; $i > $afterParenthesisIndex; --$i) {
                $tokens[$i] = $tokens[$i - 1];
            }

            $tokens[$afterParenthesisIndex] = $tokenTmp;
            $tokens->insertAt($afterParenthesisIndex + 1, new Token(array(T_WHITESPACE, "\n")));

            // Collapse whitespace tokens if the last moved token is a whitespace and the next token is one too.
            // + 1 is needed to get the last moved token because of the inserted token.
            $lastMovedToken = $tokens[$afterCommentIndex + 1];
            $followingToken = $tokens[$afterCommentIndex + 2];

            if ($lastMovedToken->isWhitespace() && $followingToken->isWhitespace()) {
                $followingToken->setContent($lastMovedToken->getContent().$followingToken->getContent());
                $lastMovedToken->clear();
            }
        }
    }

    private function fixControlContinuationBraces(Tokens $tokens)
    {
        $controlContinuationTokens = $this->getControlContinuationTokens();

        for ($index = count($tokens) - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind($controlContinuationTokens)) {
                continue;
            }

            $prevIndex = $tokens->getPrevNonWhitespace($index);
            $prevToken = $tokens[$prevIndex];

            if (!$prevToken->equals('}')) {
                continue;
            }

            $tokens->ensureWhitespaceAtIndex($index - 1, 1, ' ');
        }
    }

    private function fixDoWhile(Tokens $tokens)
    {
        for ($index = count($tokens) - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_DO)) {
                continue;
            }

            $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $index);
            $startBraceIndex = $tokens->getNextNonWhitespace($parenthesisEndIndex);
            $endBraceIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $startBraceIndex);
            $nextNonWhitespaceIndex = $tokens->getNextNonWhitespace($endBraceIndex);
            $nextNonWhitespaceToken = $tokens[$nextNonWhitespaceIndex];

            if (!$nextNonWhitespaceToken->isGivenKind(T_WHILE)) {
                continue;
            }

            $tokens->ensureWhitespaceAtIndex($nextNonWhitespaceIndex - 1, 1, ' ');
        }
    }

    private function fixIndents(Tokens $tokens)
    {
        $classyTokens = Token::getClassyTokenKinds();
        $classyAndFunctionTokens = array_merge(array(T_FUNCTION), $classyTokens);
        $controlTokens = $this->getControlTokens();
        $indentTokens = array_filter(
            array_merge($classyAndFunctionTokens, $controlTokens),
            function ($item) {
                return T_SWITCH !== $item;
            }
        );
        $tokensAnalyzer = new TokensAnalyzer($tokens);

        for ($index = 0, $limit = count($tokens); $index < $limit; ++$index) {
            $token = $tokens[$index];

            // if token is not a structure element - continue
            if (!$token->isGivenKind($indentTokens)) {
                continue;
            }

            // do not change indent for lambda functions
            if ($token->isGivenKind(T_FUNCTION) && $tokensAnalyzer->isLambda($index)) {
                continue;
            }

            // do not change indent for `while` in `do ... while ...`
            if ($token->isGivenKind(T_WHILE) && $tokensAnalyzer->isWhilePartOfDoWhile($index)) {
                continue;
            }

            if ($token->isGivenKind($classyAndFunctionTokens)) {
                $startBraceIndex = $tokens->getNextTokenOfKind($index, array(';', '{'));
                $startBraceToken = $tokens[$startBraceIndex];
            } else {
                $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $index);
                $startBraceIndex = $tokens->getNextNonWhitespace($parenthesisEndIndex);
                $startBraceToken = $tokens[$startBraceIndex];
            }

            // structure without braces block - nothing to do, e.g. do { } while (true);
            if (!$startBraceToken->equals('{')) {
                continue;
            }

            $endBraceIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $startBraceIndex);

            $indent = $this->detectIndent($tokens, $index);

            // fix indent near closing brace
            $tokens->ensureWhitespaceAtIndex($endBraceIndex - 1, 1, "\n".$indent);

            // fix indent between braces
            $lastCommaIndex = $tokens->getPrevTokenOfKind($endBraceIndex - 1, array(';', '}'));

            $nestLevel = 1;
            for ($nestIndex = $lastCommaIndex; $nestIndex >= $startBraceIndex; --$nestIndex) {
                $nestToken = $tokens[$nestIndex];

                if ($nestToken->equals(')')) {
                    $nestIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $nestIndex, false);
                    continue;
                }

                if (1 === $nestLevel && $nestToken->equalsAny(array(';', '}'))) {
                    $nextNonWhitespaceNestIndex = $tokens->getNextNonWhitespace($nestIndex);
                    $nextNonWhitespaceNestToken = $tokens[$nextNonWhitespaceNestIndex];

                    if (
                        // next Token is not a comment
                        !$nextNonWhitespaceNestToken->isComment() &&
                        // and it is not a `$foo = function () {};` situation
                        !($nestToken->equals('}') && $nextNonWhitespaceNestToken->equalsAny(array(';', ',', ']', array(CT_ARRAY_SQUARE_BRACE_CLOSE)))) &&
                        // and it is not a `${"a"}->...` and `${"b{$foo}"}->...` situation
                        !($nestToken->equals('}') && $tokens[$nestIndex - 1]->equalsAny(array('"', "'", array(T_CONSTANT_ENCAPSED_STRING))))
                    ) {
                        if (
                            $nextNonWhitespaceNestToken->isGivenKind($this->getControlContinuationTokens()) ||
                            (
                                $nextNonWhitespaceNestToken->isGivenKind(T_WHILE) &&
                                $tokensAnalyzer->isWhilePartOfDoWhile($nextNonWhitespaceNestIndex)
                            )
                        ) {
                            $whitespace = ' ';
                        } else {
                            $nextToken = $tokens[$nestIndex + 1];
                            $nextWhitespace = '';

                            if ($nextToken->isWhitespace()) {
                                $nextWhitespace = rtrim($nextToken->getContent(), " \t");

                                if (strlen($nextWhitespace) && "\n" === $nextWhitespace[strlen($nextWhitespace) - 1]) {
                                    $nextWhitespace = substr($nextWhitespace, 0, -1);
                                }
                            }

                            $whitespace = $nextWhitespace."\n".$indent;

                            if (!$nextNonWhitespaceNestToken->equals('}')) {
                                $whitespace .= '    ';
                            }
                        }

                        $tokens->ensureWhitespaceAtIndex($nestIndex + 1, 0, $whitespace);
                    }
                }

                if ($nestToken->equals('}')) {
                    ++$nestLevel;
                    continue;
                }

                if ($nestToken->equals('{')) {
                    --$nestLevel;
                    continue;
                }
            }

            // fix indent near opening brace
            if (isset($tokens[$startBraceIndex + 2]) && $tokens[$startBraceIndex + 2]->equals('}')) {
                $tokens->ensureWhitespaceAtIndex($startBraceIndex + 1, 0, "\n".$indent);
            } elseif (!$tokens[$index]->isClassy()) {
                $nextToken = $tokens[$startBraceIndex + 1];
                $nextNonWhitespaceToken = $tokens[$tokens->getNextNonWhitespace($startBraceIndex)];

                // set indent only if it is not a case, when comment is following { in same line
                if (
                    !$nextNonWhitespaceToken->isComment()
                    || !($nextToken->isWhitespace() && $nextToken->isWhitespace(" \t"))
                    && substr_count($nextToken->getContent(), "\n") === 1 // preserve blank lines
                ) {
                    $tokens->ensureWhitespaceAtIndex($startBraceIndex + 1, 0, "\n".$indent.'    ');
                }
            } else {
                $tokens->ensureWhitespaceAtIndex($startBraceIndex + 1, 0, "\n".$indent.'    ');
            }

            if ($token->isGivenKind($classyTokens)) {
                $tokens->ensureWhitespaceAtIndex($startBraceIndex - 1, 1, "\n".$indent);
            } elseif ($token->isGivenKind(T_FUNCTION)) {
                $closingParenthesisIndex = $tokens->getPrevTokenOfKind($startBraceIndex, array(')'));
                $prevToken = $tokens[$closingParenthesisIndex - 1];

                if ($prevToken->isWhitespace() && false !== strpos($prevToken->getContent(), "\n")) {
                    $tokens->ensureWhitespaceAtIndex($startBraceIndex - 1, 1, ' ');
                } else {
                    $tokens->ensureWhitespaceAtIndex($startBraceIndex - 1, 1, "\n".$indent);
                }
            } else {
                $tokens->ensureWhitespaceAtIndex($startBraceIndex - 1, 1, ' ');
            }

            // reset loop limit due to collection change
            $limit = count($tokens);
        }
    }

    private function fixLambdas(Tokens $tokens)
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);

        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_FUNCTION) || !$tokensAnalyzer->isLambda($index)) {
                continue;
            }

            $nextIndex = $tokens->getNextTokenOfKind($index, array('{'));

            $tokens->ensureWhitespaceAtIndex($nextIndex - 1, 1, ' ');
        }
    }

    private function fixMissingControlBraces(Tokens $tokens)
    {
        $controlTokens = $this->getControlTokens();

        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind($controlTokens)) {
                continue;
            }

            $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $index);
            $tokenAfterParenthesis = $tokens[$tokens->getNextMeaningfulToken($parenthesisEndIndex)];

            // if Token after parenthesis is { then we do not need to insert brace, but to fix whitespace before it
            if ($tokenAfterParenthesis->equals('{')) {
                $tokens->ensureWhitespaceAtIndex($parenthesisEndIndex + 1, 0, ' ');
                continue;
            }

            // do not add braces for cases:
            // - structure without block, e.g. while ($iter->next());
            // - structure with block, e.g. while ($i) {...}, while ($i) : {...} endwhile;
            if ($tokenAfterParenthesis->equalsAny(array(';', '{', ':'))) {
                continue;
            }

            $statementEndIndex = $this->findStatementEnd($tokens, $parenthesisEndIndex);

            // insert closing brace
            $tokens->insertAt($statementEndIndex + 1, array(new Token(array(T_WHITESPACE, ' ')), new Token('}')));

            // insert missing `;` if needed
            if (!$tokens[$statementEndIndex]->equalsAny(array(';', '}'))) {
                $tokens->insertAt($statementEndIndex + 1, new Token(';'));
            }

            // insert opening brace
            $tokens->insertAt($parenthesisEndIndex + 1, new Token('{'));
            $tokens->ensureWhitespaceAtIndex($parenthesisEndIndex + 1, 0, ' ');
        }
    }

    private function fixSpaceAroundToken(Tokens $tokens)
    {
        $controlTokens = $this->getControlTokens();

        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if ($token->isGivenKind($controlTokens) || $token->isGivenKind(CT_USE_LAMBDA)) {
                $nextNonWhitespaceIndex = $tokens->getNextNonWhitespace($index);

                if (!$tokens[$nextNonWhitespaceIndex]->equals(':')) {
                    $tokens->ensureWhitespaceAtIndex($index + 1, 0, ' ');
                }

                $prevToken = $tokens[$index - 1];

                if (!$prevToken->isWhitespace() && !$prevToken->isComment() && !$prevToken->isGivenKind(T_OPEN_TAG)) {
                    $tokens->ensureWhitespaceAtIndex($index - 1, 1, ' ');
                }
            }
        }
    }

    private function detectIndent(Tokens $tokens, $index)
    {
        static $goBackTokens = array(T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC);

        $token = $tokens[$index];

        if ($token->isGivenKind($goBackTokens) || $token->isClassy() || $token->isGivenKind(T_FUNCTION)) {
            $prevIndex = $tokens->getPrevNonWhitespace($index);
            $prevToken = $tokens[$prevIndex];

            if ($prevToken->isGivenKind($goBackTokens)) {
                return $this->detectIndent($tokens, $prevIndex);
            }
        }

        $prevIndex = $index - 1;
        $prevToken = $tokens[$prevIndex];

        if ($prevToken->equals('}')) {
            return $this->detectIndent($tokens, $prevIndex);
        }

        // if can not detect indent:
        if (!$prevToken->isWhitespace()) {
            return '';
        }

        $explodedContent = explode("\n", $prevToken->getContent());

        // proper decect indent for code: `    } else {`
        if (1 === count($explodedContent)) {
            if ($tokens[$index - 2]->equals('}')) {
                return $this->detectIndent($tokens, $index - 2);
            }
        }

        return end($explodedContent);
    }

    private function findParenthesisEnd(Tokens $tokens, $structureTokenIndex)
    {
        $nextIndex = $tokens->getNextNonWhitespace($structureTokenIndex);
        $nextToken = $tokens[$nextIndex];

        // return if next token is not opening parenthesis
        if (!$nextToken->equals('(')) {
            return $structureTokenIndex;
        }

        return $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $nextIndex);
    }

    private function findStatementEnd(Tokens $tokens, $parenthesisEndIndex)
    {
        $nextIndex = $tokens->getNextMeaningfulToken($parenthesisEndIndex);
        $nextToken = $tokens[$nextIndex];

        if (!$nextToken) {
            return $parenthesisEndIndex;
        }

        if ($nextToken->equals('{')) {
            return $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $nextIndex);
        }

        if ($nextToken->isGivenKind($this->getControlTokens())) {
            $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $nextIndex);

            $endIndex = $this->findStatementEnd($tokens, $parenthesisEndIndex);

            if ($nextToken->isGivenKind(array(T_IF, T_TRY))) {
                $openingTokenKind = $nextToken->getId();

                while (true) {
                    $nextIndex = $tokens->getNextMeaningfulToken($endIndex);
                    $nextToken = isset($nextIndex) ? $tokens[$nextIndex] : null;
                    if ($nextToken && $nextToken->isGivenKind($this->getControlContinuationTokensForOpeningToken($openingTokenKind))) {
                        $parenthesisEndIndex = $this->findParenthesisEnd($tokens, $nextIndex);

                        $endIndex = $this->findStatementEnd($tokens, $parenthesisEndIndex);

                        if ($nextToken->isGivenKind($this->getFinalControlContinuationTokensForOpeningToken($openingTokenKind))) {
                            return $endIndex;
                        }
                    } else {
                        break;
                    }
                }
            }

            return $endIndex;
        }

        $index = $parenthesisEndIndex;

        while (true) {
            $token = $tokens[++$index];

            // if there is some block in statement (eg lambda function) we need to skip it
            if ($token->equals('{')) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $index);
                continue;
            }

            if ($token->equals(';')) {
                return $index;
            }

            if ($token->isGivenKind(T_CLOSE_TAG)) {
                return $tokens->getPrevNonWhitespace($index);
            }
        }

        throw new \RuntimeException('Statement end not found');
    }

    private function getControlTokens()
    {
        static $tokens = null;

        if (null === $tokens) {
            $tokens = array(
                T_DECLARE,
                T_DO,
                T_ELSE,
                T_ELSEIF,
                T_FOR,
                T_FOREACH,
                T_IF,
                T_WHILE,
                T_TRY,
                T_CATCH,
                T_SWITCH,
            );

            if (defined('T_FINALLY')) {
                $tokens[] = T_FINALLY;
            }
        }

        return $tokens;
    }

    private function getControlContinuationTokens()
    {
        static $tokens = null;

        if (null === $tokens) {
            $tokens = array(
                T_ELSE,
                T_ELSEIF,
                T_CATCH,
            );

            if (defined('T_FINALLY')) {
                $tokens[] = T_FINALLY;
            }
        }

        return $tokens;
    }

    private function getControlContinuationTokensForOpeningToken($openingTokenKind)
    {
        if ($openingTokenKind === T_IF) {
            return array(
                T_ELSE,
                T_ELSEIF,
            );
        }

        if ($openingTokenKind === T_TRY) {
            $tokens = array(T_CATCH);
            if (defined('T_FINALLY')) {
                $tokens[] = T_FINALLY;
            }

            return $tokens;
        }

        return array();
    }

    private function getFinalControlContinuationTokensForOpeningToken($openingTokenKind)
    {
        if ($openingTokenKind === T_IF) {
            return array(T_ELSE);
        }

        if ($openingTokenKind === T_TRY && defined('T_FINALLY')) {
            return array(T_FINALLY);
        }

        return array();
    }
}
