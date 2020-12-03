<?php
/**
 * PHPCompatibility, an external standard for PHP_CodeSniffer.
 *
 * @package   PHPCompatibility
 * @copyright 2012-2020 PHPCompatibility Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCompatibility/PHPCompatibility
 */

namespace PHPCompatibility\Helpers;

use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;

/**
 * Trait to help examine the regex parameters in function calls to PCRE functions.
 *
 * Used by the NewPCREModifiersSniff/RemovedPCREModifiersSniff sniffs.
 *
 * @since 10.0.0 Logic split off from the `RemovedPCREModifiersSniff` sniff to this trait.
 */
trait PCRERegexTrait
{

    /**
     * Regex bracket delimiters.
     *
     * @since 7.0.5  This array was originally contained within the `process()` method.
     * @since 10.0.0 Moved from the `RemovedPCREModifiersSniff` to this trait.
     *
     * @var array
     */
    private $doublesSeparators = [
        '{' => '}',
        '[' => ']',
        '(' => ')',
        '<' => '>',
    ];

    /**
     * Retrieve the regex patterns from a PCRE regex parameter.
     *
     * A regex parameter can be either a pattern as a string or an array of pattern strings.
     * In case it's an array, in most PCRE functions, the patterns will be in the array value,
     * but for the `preg_replace_callback_array()` function, the pattern strings are in the keys.
     *
     * This method brings order in this chaos.
     *
     * @since 10.0.0 This logic was originally contained in the `RemovedPCREModifiersSniff`.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile    The file being scanned.
     * @param string                      $functionName The token content (function name) which was matched.
     * @param array                       $paramInfo    A parameter info array for the regex parameter as
     *                                                  retrieved via the methods in the `PassedParameters`
     *                                                  class.
     *
     * @return array A multi-dimensional array of parameter info arrays for the actual patterns.
     *
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException If the $paramInfo passed is invalid.
     */
    public function getRegexPatternsFromParameter(File $phpcsFile, $functionName, array $paramInfo)
    {
        if (isset($paramInfo['start'], $paramInfo['end']) === false) {
            throw new RuntimeException(
                'The $paramInfo parameter must contain a parameter info array as retrieved from the PassedParameters class'
            );
        }

        $tokens         = $phpcsFile->getTokens();
        $patterns       = [];
        $functionNameLc = \strtolower($functionName);

        // Differentiate between an array of patterns passed and a single pattern.
        $nextNonEmpty = $phpcsFile->findNext(Tokens::$emptyTokens, $paramInfo['start'], ($paramInfo['end'] + 1), true);
        if ($nextNonEmpty !== false && ($tokens[$nextNonEmpty]['code'] === \T_ARRAY || $tokens[$nextNonEmpty]['code'] === \T_OPEN_SHORT_ARRAY)) {
            $arrayItems = PassedParameters::getParameters($phpcsFile, $nextNonEmpty);
            if ($functionNameLc === 'preg_replace_callback_array') {
                // For preg_replace_callback_array(), the patterns will be in the array keys.
                foreach ($arrayItems as $itemInfo) {
                    $hasKey = $phpcsFile->findNext(\T_DOUBLE_ARROW, $itemInfo['start'], ($itemInfo['end'] + 1));
                    if ($hasKey === false) {
                        continue;
                    }

                    $itemInfo['end'] = ($hasKey - 1);
                    $itemInfo['raw'] = \trim($phpcsFile->getTokensAsString($itemInfo['start'], ($hasKey - $itemInfo['start'])));
                    $patterns[]      = $itemInfo;
                }
            } else {
                // Otherwise, the patterns will be in the array values.
                foreach ($arrayItems as $itemInfo) {
                    $hasKey = $phpcsFile->findNext(\T_DOUBLE_ARROW, $itemInfo['start'], ($itemInfo['end'] + 1));
                    if ($hasKey !== false) {
                        // Param info array only needs adjusting if this was a keyed array item.
                        $itemInfo['start'] = ($hasKey + 1);
                        $itemInfo['raw']   = \trim($phpcsFile->getTokensAsString($itemInfo['start'], (($itemInfo['end'] + 1) - $itemInfo['start'])));
                    }

                    $patterns[] = $itemInfo;
                }
            }
        } else {
            $patterns[] = $paramInfo;
        }

        return $patterns;
    }

    /**
     * Retrieve the regex modifiers from a regex pattern parameter.
     *
     * @since 7.1.2
     * @since 10.0.0 Moved from the `RemovedPCREModifiersSniff` to this trait.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile   The file being scanned.
     * @param array                       $patternInfo Array containing the start and end token
     *                                                 pointer of the potential regex pattern
     *                                                 and the clean string value of the pattern.
     *
     * @return string A text string containing the modifiers found or an empty string for no modifiers.
     *
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException If the $patternInfo passed is invalid.
     */
    protected function getRegexModifiers(File $phpcsFile, array $patternInfo)
    {
        if (isset($patternInfo['start'], $patternInfo['end']) === false) {
            throw new RuntimeException(
                'The $patternInfo parameter must contain a parameter info array as retrieved from the PassedParameters class'
            );
        }

        $regex  = '';
        $tokens = $phpcsFile->getTokens();

        /*
         * The pattern might be build up of a combination of strings, variables
         * and function calls. We are only concerned with the strings.
         */
        for ($i = $patternInfo['start']; $i <= $patternInfo['end']; $i++) {
            if (isset(Tokens::$stringTokens[$tokens[$i]['code']]) === true) {
                $content = TextStrings::stripQuotes($tokens[$i]['content']);
                if ($tokens[$i]['code'] === \T_DOUBLE_QUOTED_STRING) {
                    $content = $this->stripVariables($content);
                }

                $regex .= \trim($content);
            }
        }

        // Deal with multi-line regexes which were broken up in several string tokens.
        if ($tokens[$patternInfo['start']]['line'] !== $tokens[$patternInfo['end']]['line']) {
            $regex = TextStrings::stripQuotes($regex);
        }

        if ($regex === '') {
            // No string tokens found in the parameter (e.g. if a variable was passed in).
            return '';
        }

        $regexFirstChar = \substr($regex, 0, 1);

        // Make sure that the character identified as the delimiter is valid.
        // Otherwise, it is a false positive caused by string concatenation.
        if (\preg_match('`[a-z0-9\\\\ ]`i', $regexFirstChar) === 1) {
            return '';
        }

        if (isset($this->doublesSeparators[$regexFirstChar])) {
            $regexEndPos = \strrpos($regex, $this->doublesSeparators[$regexFirstChar]);
        } else {
            $regexEndPos = \strrpos($regex, $regexFirstChar);
        }

        if ($regexEndPos === false) {
            // Couldn't match the regex delimiters.
            return '';
        }

        return \substr($regex, $regexEndPos + 1);
    }
}
