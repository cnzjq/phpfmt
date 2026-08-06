<?php
/**
 * PHP代码格式化
 * 支持命令行运行
 * 需要PHP版本>5.6
 *
 * @author 张俊强
 * @version 1.2.0
 *
 */

namespace {
    if (version_compare(PHP_VERSION, '5.6.0', '<')) {
        fwrite(STDERR, "PHP 5.6+ is required\n");
        exit(255);
    }
}

namespace PhpFmt {

    // =====================================================================
    // Token wrapper
    // =====================================================================
    class Token {
        public $id;
        public $text;
        public $line;

        public function __construct($id, string $text, int $line = 0) {
            $this->id = $id;
            $this->text = $text;
            $this->line = $line;
        }
    }

    // =====================================================================
    // Abstract Pass
    // =====================================================================
    abstract class FormatterPass {
        public function tokenize(string $source): array {
            $raw = token_get_all($source);
            $tokens = [];
            $line = 1;
            foreach ($raw as $t) {
                if (is_array($t)) {
                    $tokens[] = new Token($t[0], $t[1], $t[2] ?? $line);
                    $line = $t[2] ?? $line;
                } else {
                    $tokens[] = new Token($t, $t, $line);
                }
            }
            return $tokens;
        }

        public function render(array $tokens): string {
            $out = '';
            foreach ($tokens as $t) {
                if ($t instanceof Token) {
                    $out .= $t->text;
                } elseif (is_array($t)) {
                    $out .= $t[1] ?? $t[0];
                } else {
                    $out .= (string) $t;
                }
            }
            return $out;
        }

        abstract public function candidate(string $source): bool;
        abstract public function format(string $source): string;
    }

    // =====================================================================
    // Utility: Detect found tokens in source
    // =====================================================================
    function getFoundTokens(string $source): array {
        $found = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $found[$token[0]] = true;
            } else {
                $found[$token] = true;
            }
        }
        return $found;
    }

    // =====================================================================
    // Pass: Normalize line endings and trailing whitespace
    // =====================================================================
    final class NormalizeWhitespace extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $source = str_replace(["\r\n", "\r"], "\n", $source);
            $source = preg_replace('/[ \t]+$/m', '', $source);
            return $source;
        }
    }

    // =====================================================================
    // Pass: Remove indentation from empty lines
    // =====================================================================
    final class RemoveEmptyLineIndent extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $lines = explode("\n", $source);
            $result = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    $result[] = '';
                } else {
                    $result[] = $line;
                }
            }
            return implode("\n", $result);
        }
    }

    // =====================================================================
    // Pass: Remove duplicate empty lines
    // =====================================================================
    final class TrimExtraLines extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            return preg_replace('/\n{3,}/', "\n\n", $source);
        }
    }

    // =====================================================================
    // Pass: Normalize PHP short tags to full tags
    // =====================================================================
    final class ShortTagNormalizer extends FormatterPass {
        public function candidate(string $source): bool {
            return preg_match('/<\?(?!php)/', $source) === 1;
        }

        public function format(string $source): string {
            $tokens = token_get_all($source);
            $result = [];

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $tokenType = $token[0];
                    $tokenText = $token[1];

                    if ($tokenType === T_OPEN_TAG) {
                        // Replace short open tag <? with <?php
                        if (strpos($tokenText, '<?php') !== 0) {
                            $tokenText = preg_replace('/^<\?/', '<?php', $tokenText);
                            // Ensure space after <?php
                            if (!preg_match('/^<\?php\s/', $tokenText)) {
                                $tokenText = preg_replace('/^<\?php/', '<?php ', $tokenText);
                            }
                        }
                        $result[] = $tokenText;
                    } elseif ($tokenType === T_OPEN_TAG_WITH_ECHO) {
                        // Replace <?= with <?php echo
                        $tokenText = preg_replace('/^<\?=\s*/', '<?php echo ', $tokenText);
                        $result[] = $tokenText;
                    } else {
                        $result[] = $tokenText;
                    }
                } else {
                    $result[] = $token;
                }
            }

            return implode('', $result);
        }
    }

    // =====================================================================
    // Pass: Normalize spaces around operators and keywords
    // =====================================================================
    final class SpaceNormalizer extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $tokens = $this->tokenize($source);
            $out = [];
            $prevId = null;

            foreach ($tokens as $i => $token) {
                $id = $token->id;
                $text = $token->text;

                if ($id === T_WHITESPACE) {
                    // Collapse inline whitespace
                    if (strpos($text, "\n") === false) {
                        $out[] = new Token(T_WHITESPACE, ' ', 0);
                    } else {
                        // Preserve newline structure
                        $out[] = new Token(T_WHITESPACE, preg_replace('/[ \t]+\n/', "\n", $text), 0);
                    }
                    continue;
                }

                // Check next token for required space separation
                if ($i + 1 < count($tokens)) {
                    $nextId = $tokens[$i + 1]->id;

                    // Control structure keyword followed by (
                    if ($this->isControlStructureKeyword($id) && $nextId === '(') {
                        $out[] = $token;
                        $out[] = new Token(T_WHITESPACE, ' ', 0);
                        $prevId = $id;
                        continue;
                    }

                    // ) followed by {
                    if ($id === ')' && $nextId === '{') {
                        $out[] = $token;
                        $out[] = new Token(T_WHITESPACE, ' ', 0);
                        $prevId = $id;
                        continue;
                    }
                }

                // Operators that should have spaces
                if ($this->isBinaryOperator($id)) {
                    if (($id === '+' || $id === '-') && $this->isUnaryContext($prevId)) {
                        $out[] = $token;
                    } else {
                        $out[] = new Token(T_WHITESPACE, ' ', 0);
                        $out[] = $token;
                        $out[] = new Token(T_WHITESPACE, ' ', 0);
                    }
                } elseif ($this->isNoSpaceToken($id)) {
                    $out[] = $token;
                } else {
                    $out[] = $token;
                }

                $prevId = $id;
            }

            $result = $this->render($out);
            return $this->postProcess($result);
        }

        private function isBinaryOperator($id): bool {
            static $ops = [
                T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP,
                T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
                T_MOD_EQUAL, T_POW_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
                T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_CONCAT_EQUAL,
                T_COALESCE_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR,
                T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
                T_SL, T_SR, T_POW,
                T_COALESCE, T_INSTANCEOF,
                '+', '-', '*', '/', '%', '.', '<', '>', '|', '&', '^', '??', '=',
            ];
            return in_array($id, $ops, true);
        }

        private function isNoSpaceToken($id): bool {
            static $noSpace = [
                T_OBJECT_OPERATOR, T_DOUBLE_COLON,
            ];
            return in_array($id, $noSpace, true);
        }

        private function isUnaryContext($prevId): bool {
            if ($prevId === null) {
                return true;
            }

            static $unaryContexts = [
                ',', '(', '[', '{', ';', '?', ':',
                '=', '+', '-', '*', '/', '%', '.', '<', '>', '|', '&', '^',
                T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
                T_MOD_EQUAL, T_POW_EQUAL, T_CONCAT_EQUAL, T_COALESCE_EQUAL,
                T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
                T_BOOLEAN_AND, T_BOOLEAN_OR,
                T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
                T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
                T_SL, T_SR, T_COALESCE, T_INSTANCEOF,
                T_RETURN, T_ECHO, T_PRINT, T_THROW,
                T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH,
                T_CASE, T_DEFAULT, T_DO,
                T_DOUBLE_ARROW, T_AS, T_YIELD, T_YIELD_FROM,
            ];

            return in_array($prevId, $unaryContexts, true);
        }

        private function isControlStructureKeyword($id): bool {
            return in_array($id, [
                T_IF, T_ELSEIF, T_ELSE, T_FOR, T_FOREACH,
                T_WHILE, T_DO, T_SWITCH, T_CATCH, T_FINALLY,
            ], true);
        }

        private function postProcess(string $source): string {
            // Fix multiple spaces (but preserve indentation)
            $lines = explode("\n", $source);
            $result = [];
            foreach ($lines as $line) {
                $trimmed = ltrim($line);
                $indent = substr($line, 0, strlen($line) - strlen($trimmed));
                // Replace multiple spaces in non-indent part, but preserve content inside comments
                if (strpos($trimmed, '//') !== 0) {
                    $trimmed = preg_replace('/ {2,}/', ' ', $trimmed);
                }
                $result[] = $indent . $trimmed;
            }
            $source = implode("\n", $result);

            // Fix specific patterns (only inline whitespace, not newlines)
            $source = preg_replace('/[^\S\n\r]+,/', ',', $source);
            $source = preg_replace('/[^\S\n\r]+;/', ';', $source);
            $source = preg_replace('/\([^\S\n\r]+/', '(', $source);
            $source = preg_replace('/[^\S\n\r]+\)/', ')', $source);
            $source = preg_replace('/\[[^\S\n\r]+/', '[', $source);
            $source = preg_replace('/[^\S\n\r]+\]/', ']', $source);
            $source = preg_replace('/{[^\S\n\r]+}/', '{}', $source);

            return rtrim($source);
        }
    }

    // =====================================================================
    // Pass: Smart indentation - Token-based structural analysis
    // =====================================================================
    final class SmartIndent extends FormatterPass {
        private $indentSize;

        public function __construct(int $indentSize = 4) {
            $this->indentSize = $indentSize;
        }

        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $tokens = $this->tokenize($source);
            $indentLevel = 0;
            $result = [];
            $inHeredoc = false;
            $heredocTag = '';
            $caseExtraIndent = false;
            $inDoubleQuoteString = false;

            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                $id = $token->id;
                $text = $token->text;

                // Track double-quote string context for {$var} interpolation
                if ($id === '"') {
                    $inDoubleQuoteString = !$inDoubleQuoteString;
                }

                // Handle heredoc/nowdoc
                if ($id === T_START_HEREDOC) {
                    $inHeredoc = true;
                    if (preg_match('/<<<\s*[\'\"]?(\w+)/', $text, $m)) {
                        $heredocTag = $m[1];
                    }
                    $result[] = $text;
                    continue;
                }
                if ($inHeredoc) {
                    if ($id === T_END_HEREDOC && trim($text) === $heredocTag) {
                        $inHeredoc = false;
                    }
                    $result[] = $text;
                    continue;
                }

                // Handle strings and comments as-is
                if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $result[] = $text;
                    continue;
                }

                // Opening braces: {
                if ($id === '{') {
                    $result[] = $text;
                    // Check if there's a newline after this brace, possibly after comments
                    $j = $i + 1;
                    while ($j < count($tokens)) {
                        $tk = $tokens[$j];
                        if ($tk->id === T_WHITESPACE || $tk->id === T_COMMENT || $tk->id === T_DOC_COMMENT) {
                            if (strpos($tk->text, "\n") !== false) {
                                $indentLevel++;
                                break;
                            }
                            $j++;
                        } else {
                            break;
                        }
                    }
                    continue;
                }

                // Closing braces: }
                if ($id === '}') {
                    if (!$inDoubleQuoteString) {
                        $indentLevel = max(0, $indentLevel - 1);
                    }
                    $result[] = $text;
                    continue;
                }

                // Opening array bracket: [ (only when followed by newline)
                if ($id === '[') {
                    $result[] = $text;
                    $j = $i + 1;
                    while ($j < count($tokens)) {
                        $tk = $tokens[$j];
                        if ($tk->id === T_WHITESPACE || $tk->id === T_COMMENT || $tk->id === T_DOC_COMMENT) {
                            if (strpos($tk->text, "\n") !== false) {
                                $indentLevel++;
                                break;
                            }
                            $j++;
                        } else {
                            break;
                        }
                    }
                    continue;
                }

                // Closing array bracket: ] (only when preceded by newline)
                if ($id === ']') {
                    $prevToken = $tokens[$i - 1] ?? null;
                    if ($prevToken && $prevToken->id === T_WHITESPACE && strpos($prevToken->text, "\n") !== false) {
                        $indentLevel = max(0, $indentLevel - 1);
                    }
                    $result[] = $text;
                    continue;
                }

                // Track case/default for extra indent
                if ($id === T_CASE || $id === T_DEFAULT) {
                    $caseExtraIndent = true;
                    $result[] = $text;
                    continue;
                }

                // Handle whitespace with newlines
                if ($id === T_WHITESPACE && strpos($text, "\n") !== false) {
                    $nextToken = $tokens[$i + 1] ?? null;
                    $nextId = $nextToken ? $nextToken->id : null;

                    // Check if next non-whitespace token resets case indent
                    $j = $i + 1;
                    while ($j < count($tokens) && $tokens[$j]->id === T_WHITESPACE) {
                        $j++;
                    }
                    $nextNonWsId = $tokens[$j]->id ?? null;
                    if ($nextNonWsId === T_CASE || $nextNonWsId === T_DEFAULT || $nextNonWsId === '}') {
                        $caseExtraIndent = false;
                    }

                    // Determine if next line should be de-indented
                    $targetIndent = $indentLevel;
                    if ($nextId === '}' || $nextId === ')' || $nextId === ']') {
                        $targetIndent = max(0, $indentLevel - 1);
                    }

                    // Add case body extra indent
                    if ($caseExtraIndent) {
                        $targetIndent++;
                    }

                    $indentStr = str_repeat(" ", $targetIndent * $this->indentSize);

                    // Process whitespace: for each segment after a newline, add proper indent
                    $parts = explode("\n", $text);
                    $newParts = [];
                    foreach ($parts as $idx => $part) {
                        if ($idx === 0) {
                            $newParts[] = $part; // First part stays as-is (before first \n)
                        } else {
                            // This part comes after a \n - always add indent
                            $newParts[] = $indentStr;
                        }
                    }
                    $result[] = implode("\n", $newParts);
                    continue;
                }

                $result[] = $text;
            }

            return implode('', $result);
        }
    }

    // =====================================================================
    // Pass: Align assignment operators
    // =====================================================================
    final class AlignAssignments extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $lines = explode("\n", $source);
            $result = [];
            $group = [];
            $groupIndent = null;

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    $result = array_merge($result, $this->alignGroup($group));
                    $group = [];
                    $groupIndent = null;
                    $result[] = '';
                    continue;
                }

                $pos = $this->findAssignmentPosition($line);
                if ($pos > 0) {
                    $indent = strlen($line) - strlen(ltrim($line));
                    if ($groupIndent !== null && $indent !== $groupIndent) {
                        $result = array_merge($result, $this->alignGroup($group));
                        $group = [];
                    }
                    $groupIndent = $indent;
                    $group[] = $line;
                } else {
                    $result = array_merge($result, $this->alignGroup($group));
                    $group = [];
                    $groupIndent = null;
                    $result[] = $line;
                }
            }
            $result = array_merge($result, $this->alignGroup($group));

            return implode("\n", $result);
        }

        private function alignGroup(array $lines): array {
            if (empty($lines)) {
                return $lines;
            }

            $positions = [];
            $hasAssignment = false;
            foreach ($lines as $line) {
                $pos = $this->findAssignmentPosition($line);
                $positions[] = $pos;
                if ($pos > 0) {
                    $hasAssignment = true;
                }
            }

            if (!$hasAssignment) {
                return $lines;
            }

            $maxPos = max($positions);
            if ($maxPos <= 0) {
                return $lines;
            }

            $result = [];
            foreach ($lines as $i => $line) {
                $pos = $positions[$i];
                if ($pos > 0 && $pos < $maxPos) {
                    $result[] = $this->insertSpaces($line, $pos, $maxPos);
                } else {
                    $result[] = $line;
                }
            }

            return $result;
        }

        private function findAssignmentPosition(string $line): int {
            if (strpos($line, '=') === false) {
                return -1;
            }

            $indent = '';
            $trimmed = ltrim($line);
            if (strlen($trimmed) < strlen($line)) {
                $indent = substr($line, 0, strlen($line) - strlen($trimmed));
            }

            // 跳过 for/foreach 语句条件中的等号（如 for($i = 0; ...)）
            if (preg_match('/^(?:for|foreach)\s*\(/', $trimmed)) {
                return -1;
            }

            $prefix = "<?php ";
            $code = $prefix . $trimmed;
            $tokens = @token_get_all($code);

            $pos = 0;
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $text = $token[1];
                    if ($token[0] === T_DOUBLE_ARROW) {
                        return strlen($indent) + ($pos - strlen($prefix));
                    }
                    $pos += strlen($text);
                } else {
                    if ($token === '=') {
                        return strlen($indent) + ($pos - strlen($prefix));
                    }
                    $pos += strlen($token);
                }
            }

            return -1;
        }

        private function insertSpaces(string $line, int $currentPos, int $targetPos): string {
            $diff = $targetPos - $currentPos;
            if ($diff <= 0) {
                return $line;
            }

            return substr($line, 0, $currentPos) . str_repeat(' ', $diff) . substr($line, $currentPos);
        }
    }

    // =====================================================================
    // Pass: Align trailing comments
    // =====================================================================
    final class AlignComments extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            $lines = explode("\n", $source);
            $result = [];
            $group = [];

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    $result = array_merge($result, $this->alignGroup($group));
                    $group = [];
                    $result[] = '';
                } else {
                    $group[] = $line;
                }
            }
            $result = array_merge($result, $this->alignGroup($group));

            return implode("\n", $result);
        }

        private function alignGroup(array $lines): array {
            if (empty($lines)) {
                return $lines;
            }

            $positions = [];
            $hasComment = false;
            foreach ($lines as $line) {
                $pos = $this->findCommentPosition($line);
                $positions[] = $pos;
                if ($pos > 0) {
                    $hasComment = true;
                }
            }

            if (!$hasComment) {
                return $lines;
            }

            $maxPos = max($positions);
            if ($maxPos <= 0) {
                return $lines;
            }

            $result = [];
            foreach ($lines as $i => $line) {
                $pos = $positions[$i];
                if ($pos > 0 && $pos < $maxPos) {
                    $result[] = $this->insertSpaces($line, $pos, $maxPos);
                } else {
                    $result[] = $line;
                }
            }

            return $result;
        }

        private function findCommentPosition(string $line): int {
            $pos = strrpos($line, '//');
            if ($pos === false) {
                return -1;
            }

            $before = substr($line, 0, $pos);
            if (trim($before) === '') {
                return -1;
            }

            return $pos;
        }

        private function insertSpaces(string $line, int $currentPos, int $targetPos): string {
            $diff = $targetPos - $currentPos;
            if ($diff <= 0) {
                return $line;
            }

            return substr($line, 0, $currentPos) . str_repeat(' ', $diff) . substr($line, $currentPos);
        }
    }

    // =====================================================================
    // Pass: Brace style (K&R vs Allman)
    // =====================================================================
    final class BraceStyle extends FormatterPass {
        private $style;

        public function __construct(string $style = 'knr') {
            $this->style = $style;
        }

        public function candidate(string $source): bool {
            return strpos($source, '{') !== false;
        }

        public function format(string $source): string {
            if ($this->style === 'allman') {
                $lines = explode("\n", $source);
                $result = [];
                foreach ($lines as $line) {
                    $trimmed = ltrim($line);
                    $indent = substr($line, 0, strlen($line) - strlen($trimmed));

                    // 处理 class 声明
                    if (preg_match('/^(?:abstract\s+)?(?:final\s+)?class\s+\w+/', $trimmed)) {
                        if (preg_match('/\{\s*$/', $trimmed)) {
                            $line = rtrim($line);
                            $line = preg_replace('/\s*\{\s*$/', '', $line);
                            $result[] = $line;
                            $result[] = $indent . '{';
                            continue;
                        }
                    }

                    // 处理命名 function 声明
                    if (preg_match('/\bfunction\s+\w+\s*\(/', $trimmed)) {
                        if (preg_match('/\{\s*$/', $trimmed)) {
                            $line = rtrim($line);
                            $line = preg_replace('/\s*\{\s*$/', '', $line);
                            $result[] = $line;
                            $result[] = $indent . '{';
                            continue;
                        }
                    }

                    $result[] = $line;
                }
                $source = implode("\n", $result);
                return $source;
            }
            // Default: K&R - opening brace on same line
            $source = preg_replace('/(\))\s*\n\s*\{/', '$1 {', $source);
            $source = preg_replace('/(else)\s*\n\s*\{/', '$1 {', $source);
            $source = preg_replace('/(try)\s*\n\s*\{/', '$1 {', $source);
            $source = preg_replace('/(catch\s*\([^)]*\))\s*\n\s*\{/', '$1 {', $source);
            $source = preg_replace('/(finally)\s*\n\s*\{/', '$1 {', $source);
            return $source;
        }
    }

    // =====================================================================
    // Pass: Use statement organizer
    // =====================================================================
    final class OrganizeImports extends FormatterPass {
        public function candidate(string $source): bool {
            return strpos($source, 'use ') !== false;
        }

        public function format(string $source): string {
            if (!preg_match_all('/^\s*use\s+([^;]+);/m', $source, $matches)) {
                return $source;
            }

            $uses = $matches[0];
            if (count($uses) <= 1) {
                return $source;
            }

            usort($uses, 'strcasecmp');
            $sorted = array_unique(array_map('trim', $uses));

            // Replace block
            $firstUse = $matches[0][0];
            $lastUse = end($matches[0]);
            $startPos = strpos($source, $firstUse);
            $endPos = strrpos($source, $lastUse) + strlen($lastUse);

            if ($startPos !== false && $endPos !== false) {
                $before = substr($source, 0, $startPos);
                $after = substr($source, $endPos);
                $newBlock = implode("\n", $sorted);
                return $before . $newBlock . $after;
            }

            return $source;
        }
    }

    // =====================================================================
    // Pass: Ensure empty line after namespace and use declarations
    // =====================================================================
    final class NamespaceUseSpacing extends FormatterPass {
        public function candidate(string $source): bool {
            return strpos($source, 'namespace') !== false || strpos($source, 'use ') !== false;
        }

        public function format(string $source): string {
            $lines = explode("\n", $source);
            $result = [];
            $inUseBlock = false;

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                $trimmed = ltrim($line);

                // namespace declaration (simple form, not namespace {...})
                if (preg_match('/^namespace\s+[^;]+;/', $trimmed)) {
                    $result[] = $line;
                    // Ensure next line is empty
                    if ($i + 1 < count($lines) && ltrim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
                    continue;
                }

                // use statement (import use at file top level, no indentation)
                if (preg_match('/^use\s+[^;]+;/', $trimmed) && $line === $trimmed) {
                    $result[] = $line;
                    $inUseBlock = true;
                    continue;
                }

                // Non-use statement, if previously in use block, add empty line after use block
                if ($inUseBlock && $trimmed !== '' && !preg_match('/^use\s+/', $trimmed)) {
                    // Insert empty line after use block
                    if (count($result) > 0 && ltrim($result[count($result) - 1]) !== '') {
                        $result[] = '';
                    }
                    $inUseBlock = false;
                }

                $result[] = $line;
            }

            return implode("\n", $result);
        }
    }

    // =====================================================================
    // Pass: Array formatting - short array syntax
    // =====================================================================
    final class ArrayNormalizer extends FormatterPass {
        public function candidate(string $source): bool {
            $found = getFoundTokens($source);
            return isset($found[T_ARRAY]);
        }

        public function format(string $source): string {
            $tokens = $this->tokenize($source);
            $out = [];
            $parenDepths = []; // Stack: tracks paren depth for each nested array()

            for ($i = 0; $i < count($tokens); $i++) {
                $id = $tokens[$i]->id;

                if ($id === T_ARRAY) {
                    $j = $i + 1;
                    while ($j < count($tokens) && $tokens[$j]->id === T_WHITESPACE) {
                        $j++;
                    }
                    if ($j < count($tokens) && $tokens[$j]->id === '(') {
                        $parenDepths[] = 1; // Start tracking this array's parens
                        $out[] = new Token(null, '[', 0);
                        $i = $j;
                        continue;
                    }
                }

                if ($id === '(') {
                    if (!empty($parenDepths)) {
                        $lastIdx = count($parenDepths) - 1;
                        $parenDepths[$lastIdx]++;
                    }
                    $out[] = new Token(null, '(', 0);
                    continue;
                }

                if ($id === ')') {
                    if (!empty($parenDepths)) {
                        $lastIdx = count($parenDepths) - 1;
                        $parenDepths[$lastIdx]--;
                        if ($parenDepths[$lastIdx] === 0) {
                            array_pop($parenDepths);
                            $out[] = new Token(null, ']', 0);
                            continue;
                        }
                    }
                    $out[] = new Token(null, ')', 0);
                    continue;
                }

                $out[] = $tokens[$i];
            }

            return $this->render($out);
        }
    }

    // =====================================================================
    // Pass: Remove trailing comma in arrays
    // =====================================================================
    final class RemoveTrailingComma extends FormatterPass {
        public function candidate(string $source): bool {
            return true;
        }

        public function format(string $source): string {
            // Remove trailing comma before closing bracket (only inline, not across lines)
            return preg_replace('/,[^\S\n\r]*\]/', ']', $source);
        }
    }

    // =====================================================================
    // Pass: Normalize function spacing
    // =====================================================================
    final class FunctionSpacing extends FormatterPass {
        public function candidate(string $source): bool {
            return strpos($source, 'function') !== false;
        }

        public function format(string $source): string {
            $tokens = $this->tokenize($source);
            $out = [];

            for ($i = 0; $i < count($tokens); $i++) {
                $id = $tokens[$i]->id;
                $text = $tokens[$i]->text;

                if ($id === '}') {
                    $j = $i + 1;
                    while ($j < count($tokens) && $tokens[$j]->id === T_WHITESPACE) {
                        $j++;
                    }

                    if ($j < count($tokens)) {
                        $nextId = $tokens[$j]->id;
                        $isMethod = false;

                        if ($nextId === T_FUNCTION) {
                            $isMethod = true;
                        } elseif (in_array($nextId, [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT])) {
                            $k = $j + 1;
                            while ($k < count($tokens) && $tokens[$k]->id === T_WHITESPACE) {
                                $k++;
                            }
                            if ($k < count($tokens) && $tokens[$k]->id === T_FUNCTION) {
                                $isMethod = true;
                            }
                        }

                        if ($isMethod) {
                            $out[] = new Token(null, '}', 0);
                            $out[] = new Token(T_WHITESPACE, "\n\n", 0);
                            $i = $j - 1;
                            continue;
                        }
                    }
                }

                $out[] = $tokens[$i];
            }

            return $this->render($out);
        }
    }

    // =====================================================================
    // Pass: Comment spacing
    // =====================================================================
    final class CommentSpacing extends FormatterPass {
        public function candidate(string $source): bool {
            $found = getFoundTokens($source);
            return isset($found[T_COMMENT]) || isset($found[T_DOC_COMMENT]);
        }

        public function format(string $source): string {
            $tokens = $this->tokenize($source);
            $out = [];

            foreach ($tokens as $token) {
                $id = $token->id;
                $text = $token->text;

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    // Ensure space after // in inline comments
                    if (strpos($text, '//') === 0) {
                        $text = preg_replace('/^(\/\/)([^ \t\/])/', '$1 $2', $text);
                    }
                    $out[] = new Token($id, $text, 0);
                    continue;
                }

                $out[] = $token;
            }

            return $this->render($out);
        }
    }

    // =====================================================================
    // Pass: String quoting normalization
    // =====================================================================
    final class StringQuoting extends FormatterPass {
        public function candidate(string $source): bool {
            return strpos($source, '"') !== false;
        }

        public function format(string $source): string {
            // Don't actually change quotes - just ensure consistent spacing
            // This pass is a placeholder for more advanced string handling
            return $source;
        }
    }

    // =====================================================================
    // Main Formatter Engine
    // =====================================================================
    class CodeFormatter {
        private $passes = [];
        private $indentSize = 4;
        private $alignAssignments = false;
        private $alignComments = false;

        public function __construct(array $options = []) {
            $this->indentSize = $options['indent_size'] ?? 4;
            $this->alignAssignments = $options['align_assignments'] ?? false;
            $this->alignComments = $options['align_comments'] ?? false;
            $this->registerDefaultPasses();
        }

        private function registerDefaultPasses(): void {
            $this->passes = [
                new NormalizeWhitespace(),
                new TrimExtraLines(),
                new ShortTagNormalizer(),
                new SpaceNormalizer(),
                new FunctionSpacing(),
                new SmartIndent($this->indentSize),
                new BraceStyle('allman'),
                new ArrayNormalizer(),
                new RemoveTrailingComma(),
                new OrganizeImports(),
                new NamespaceUseSpacing(),
                new CommentSpacing(),
                new StringQuoting(),
                new RemoveEmptyLineIndent(),
            ];

            if ($this->alignAssignments) {
                $this->passes[] = new AlignAssignments();
            }

            if ($this->alignComments) {
                $this->passes[] = new AlignComments();
            }
        }

        public function addPass(FormatterPass $pass): void {
            $this->passes[] = $pass;
        }

        public function formatCode(string $source): string {
            foreach ($this->passes as $pass) {
                if ($pass->candidate($source)) {
                    $source = $pass->format($source);
                }
            }
            // Ensure file ends with a single newline
            $source = rtrim($source, "\r\n");
            return $source . "\n\n";
        }
    }
}

// =====================================================================
// CLI Entry Point
// =====================================================================
namespace {
    use PhpFmt\CodeFormatter;

    function fmtShowHelp(): void {
        echo <<<HELP
用法: php phpfmt.php [选项] <文件>

现代化的 PHP 代码格式化器。

选项:
  --help              显示此帮助信息
  --stdin             从标准输入读取
  --indent=N          缩进空格数（默认: 4）
  --align-assignments 对齐等号
  --align-comments    对齐注释
  --dry-run           仅显示更改而不写入文件

Examples:
  php phpfmt.php --stdin < file.php
  php phpfmt.php --indent=4 file.php

HELP;
    }

    // Only run CLI if this file is executed directly
    if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
        $opts = getopt('ihvo:', ['help', 'stdin', 'indent:', 'align-assignments', 'align-comments', 'dry-run']);

        // Determine input source: --stdin, -, or -o -
        $readFromStdin = isset($opts['stdin'])
            || (isset($argv[1]) && $argv[1] === '-')
            || (isset($opts['o']) && $opts['o'] === '-');

        if (isset($opts['help']) || isset($opts['h']) || (!$readFromStdin && $argc < 2)) {
            fmtShowHelp();
            exit(0);
        }

        $options = [
            'indent_size' => isset($opts['indent']) ? (int)$opts['indent'] : 4,
            'align_assignments' => isset($opts['align-assignments']) ? true : false,
            'align_comments' => isset($opts['align-comments']) ? true : false,
        ];

        $formatter = new CodeFormatter($options);

        if ($readFromStdin) {
            $source = file_get_contents('php://stdin');
            echo $formatter->formatCode($source);
            exit(0);
        }

        $file = $argv[$argc - 1] ?? null;
        if (!$file || !file_exists($file)) {
            fwrite(STDERR, "File not found: " . ($file ?? '') . "\n");
            exit(1);
        }
        $source = file_get_contents($file);
        $formatted = $formatter->formatCode($source);
        if (isset($opts['dry-run'])) {
            echo $formatted;
        } else {
            file_put_contents($file, $formatted);
        }
    }
}
