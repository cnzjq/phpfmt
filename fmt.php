<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "错误：请在命令行（CLI）环境下运行 fmt.php。\n");
    exit(1);
}

/* 为旧版本 PHP 定义缺失的 token 常量（运行在 PHP 7 上时） */
$__neg = -900;
foreach (array(
    'T_FN', 'T_MATCH', 'T_ATTRIBUTE', 'T_ENUM', 'T_READONLY',
    'T_NULLSAFE_OBJECT_OPERATOR', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED',
    'T_NAME_RELATIVE', 'T_COALESCE_EQUAL', 'T_BAD_CHARACTER',
    'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG', 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG',
) as $__name) {
    if (!defined($__name)) {
        define($__name, $__neg);
        $__neg--;
    }
}

/**
 * PHP 代码格式化器 1.3.0
 */
class Fmt
{
    /** @var int 每级缩进空格数 */
    public $size = 4;
    /** @var int 每行最大长度（0 = 忽略行长度） */
    public $maxLen = 0;
    /** @var bool 是否对齐行内注释 */
    public $alignComments = false;
    /** @var bool 是否对齐连续赋值语句的等号 */
    public $alignEquals = false;
    /** @var bool 片段模式 */
    public $fragment = false;
    /** @var int 片段起始缩进级别 */
    public $initialLevel = 0;

    private $out = '';
    private $level = 0;
    private $lineLen = 0;
    private $pendingIndent = null;

    private $prevId = null;
    private $prevText = '';
    private $pk = 'start'; // 上一个已输出 token 的语义标记
    private $ws = '';      // 上次输出以来跳过的原始空白

    private $toks = array();
    private $n = 0;
    private $i = 0;

    private $stack = array();   // 块栈（花括号/替代语法）：array('k' => 类型, 'lvl' => 级别)
    private $parens = array();  // 括号组栈：array('k'、'o'、'split'、'base')
    private $lastParenK = '';
    private $lastParenSplit = false;
    private $lastBlockK = '';

    private $tern = 0;
    private $inCase = false;
    private $caseParen = 0;
    private $inDefault = false;

    private $decl = '';      // 声明上下文：class/enum/anon/fn/match/ctrl/ns
    private $ctrlK = '';     // 控制结构关键字（if/foreach/switch...）
    private $fnNamed = false;
    private $useClosure = false;
    private $altOpener = '';
    private $lastStmtK = ''; // 上条语句关键字（declare/namespace/use 等）

    private $attr = 0;        // >0 表示处于 PHP8 属性 #[...] 内
    private $heredoc = false;
    private $rawStr = '';     // 插值字符串/反引号原始区的界定符
    private $fragTagDone = false;

    // 当前 token 的动态修饰（一元符号、可空问号等）
    private $unarySign = false;
    private $incPrefix = false;
    private $nullableQ = false;
    private $nullsafeQ = false;
    private $ampRef = false;
    private $unionBar = false;

    private static $wordIds = null;
    private static $funcLikeIds = null;
    private static $castIds = null;
    private static $modifierIds = null;
    private static $modRank = null;
    private static $identIds = null;
    private static $ctrlParenIds = null;
    private static $typeIds = null;

    public function __construct()
    {
        if (self::$wordIds !== null) {
            return;
        }
        self::$wordIds = array(
            T_ABSTRACT, T_ARRAY, T_AS, T_BREAK, T_CALLABLE, T_CASE, T_CATCH, T_CLASS,
            T_CLONE, T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO, T_ECHO, T_ELSE,
            T_ELSEIF, T_EMPTY, T_ENDDECLARE, T_ENDFOR, T_ENDFOREACH, T_ENDIF,
            T_ENDSWITCH, T_ENDWHILE, T_ENUM, T_EVAL, T_EXIT, T_EXTENDS, T_FINAL,
            T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION, T_GLOBAL, T_GOTO, T_IF,
            T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE, T_INSTANCEOF, T_INSTEADOF,
            T_INTERFACE, T_ISSET, T_LIST, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
            T_MATCH, T_NAMESPACE, T_NEW, T_PRINT, T_PRIVATE, T_PROTECTED, T_PUBLIC,
            T_READONLY, T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH,
            T_THROW, T_TRAIT, T_TRY, T_UNSET, T_USE, T_VAR, T_WHILE, T_YIELD,
            T_YIELD_FROM,
        );
        self::$funcLikeIds = array(
            T_ARRAY, T_LIST, T_ISSET, T_UNSET, T_EMPTY, T_EXIT, T_EVAL, T_PRINT,
            T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_DECLARE,
        );
        self::$castIds = array(
            T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_ARRAY_CAST,
            T_OBJECT_CAST, T_BOOL_CAST, T_UNSET_CAST,
        );
        self::$modifierIds = array(
            T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY,
        );
        self::$modRank = array(
            T_ABSTRACT => 0, T_FINAL => 1, T_PUBLIC => 2, T_PROTECTED => 2,
            T_PRIVATE => 2, T_READONLY => 3, T_STATIC => 4,
        );
        self::$identIds = array(
            T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STRING,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
        );
        self::$ctrlParenIds = array(
            T_IF, T_ELSEIF, T_WHILE, T_FOREACH, T_SWITCH, T_CATCH, T_MATCH,
        );
        self::$typeIds = array(
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
            T_NS_SEPARATOR, T_ARRAY, T_CALLABLE,
        );
    }

    /* ------------------------------------------------ 主流程 */

    public function format($code)
    {
        if (substr($code, 0, 3) === "\xEF\xBB\xBF") {
            $code = substr($code, 3); // 移除 UTF-8 BOM
        }
        $this->level = $this->initialLevel;

        if ($this->fragment) {
            if (preg_match('/^\s*(<\?php\b|<\?=|<\?)/i', $code)) {
                $this->fragTagDone = true;
            } else {
                $code = '<?php ' . $code; // 片段无起始标签时补一个（不输出）
            }
        } else {
            if (strpos($code, '<?') === false) {
                return $code; // 不含 PHP 标签，按纯文本原样返回
            }
        }

        $raw = @token_get_all($code);
        if (!is_array($raw)) {
            throw new Exception('无法解析代码（token_get_all 失败）');
        }
        $this->toks = array();
        foreach ($raw as $t) {
            $this->toks[] = is_array($t) ? array($t[0], $t[1]) : array($t, $t);
        }
        $this->n = count($this->toks);

        for ($this->i = 0; $this->i < $this->n; $this->i++) {
            $this->step();
        }
        return $this->finish();
    }

    private function finish()
    {
        $out = rtrim($this->out, " \t\n");
        if ($out === '') {
            return '';
        }
        if ($this->alignComments) {
            $out = $this->alignComments($out);
        }
        if ($this->alignEquals) {
            $out = $this->alignEquals($out);
        }
        if ($this->fragment) {
            return $out; // 片段不加结尾换行（由编辑器决定）
        }
        return $out . "\n"; // PSR-12：文件以单个换行结束
    }

    /** 连续赋值语句的等号对齐 */
    private function alignEquals($out)
    {
        $lines = explode("\n", $out);
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            // 找到连续的赋值语句行（含 = 但非 == >= <= != 等）
            $group = array();
            while ($i < $n) {
                $line = $lines[$i];
                // 控制结构行中断等号对齐组
                if (preg_match('/^\s*(for|foreach|while|if|elseif|else|switch|case|default|do|try|catch|finally|return|throw|match)\b/', $line)) {
                    break;
                }
                if (preg_match('/^(\s*\S.*?\S)(\s*=\s*)(\S.*)$/', $line, $m)
                    && preg_match('/(?<![<>!=.+\-*\/%&|^?])=(?![=>])/', $line)) {
                    $group[] = array('left' => $m[1], 'right' => $m[3], 'idx' => $i);
                } elseif (trim($line) === '') {
                    $group[] = null; // 空行不中断组
                } else {
                    break;
                }
                $i++;
            }
            $assigns = array_filter($group, function ($g) {
                return $g !== null;
            });
            if (count($assigns) >= 2) {
                $maxLen = 0;
                foreach ($assigns as $g) {
                    $len = strlen($g['left']);
                    if ($len > $maxLen) {
                        $maxLen = $len;
                    }
                }
                foreach ($assigns as $g) {
                    $lines[$g['idx']] = str_pad($g['left'], $maxLen) . ' = ' . $g['right'];
                }
            }
            if (count($group) === 0) {
                $i++;
            }
        }
        return implode("\n", $lines);
    }

    /** 连续代码行的行内注释对齐 */
    private function alignComments($out)
    {
        $lines = explode("\n", $out);
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            // 找到包含行内注释的连续行组
            if (preg_match('/^(.*\S)\s+(\/\/[^\n]*|#[^\n]*)$/', $lines[$i], $m)) {
                $groupStart = $i;
                $group = array();
                do {
                    if (preg_match('/^(.*\S)(\s+)(\/\/[^\n]*|#[^\n]*)$/', $lines[$i], $m)) {
                        $group[] = array('code' => $m[1], 'comment' => $m[3], 'idx' => $i);
                    } elseif (trim($lines[$i]) === '' ||
                        preg_match('/^\s*(\/\/|\#)/', $lines[$i])) {
                        // 空行或纯注释行：不中断组，但不参与对齐
                        $group[] = null;
                    } else {
                        break;
                    }
                    $i++;
                } while ($i < $n);
                // 过滤出有注释的行
                $commentLines = array_filter($group, function ($g) {
                    return $g !== null;
                });
                if (count($commentLines) >= 2) {
                    // 计算对齐列
                    $maxLen = 0;
                    foreach ($commentLines as $g) {
                        $len = strlen($g['code']);
                        if ($len > $maxLen) {
                            $maxLen = $len;
                        }
                    }
                    // 对齐：code + 至少2空格 + comment
                    foreach ($commentLines as $g) {
                        $padded = str_pad($g['code'], $maxLen) . '  ' . $g['comment'];
                        $lines[$g['idx']] = $padded;
                    }
                }
            } else {
                $i++;
            }
        }
        return implode("\n", $lines);
    }

    /* ------------------------------------------------ 逐 token 处理 */

    private function step()
    {
        list($id, $text) = $this->toks[$this->i];

        // heredoc/nowdoc 原始区：内容原样保留
        if ($this->heredoc) {
            $this->writeTextRaw($text);
            if ($id === T_END_HEREDOC) {
                $this->heredoc = false;
                $this->prevId = T_END_HEREDOC;
                $this->prevText = $text;
                $this->pk = 'plain';
                $this->ws = '';
            }
            return;
        }

        // 插值字符串/反引号原始区
        if ($this->rawStr !== '') {
            $this->writeTextRaw($text);
            if ($id === $this->rawStr && strlen($text) === 1) {
                $this->rawStr = '';
                $this->prevId = $id;
                $this->prevText = $text;
                $this->pk = 'plain';
                $this->ws = '';
            }
            return;
        }

        if ($id === T_WHITESPACE) {
            $this->ws .= $text;
            return;
        }

        // 修饰符排序（abstract/final 在可见性前，static 在可见性后）
        if ($this->tryModifiers($id, $text)) {
            return;
        }

        // use 导入分组、排序与空行
        if ($this->tryUseImports($id, $text)) {
            return;
        }

        $this->emit($id, $text);
    }

    private function emit($id, $text)
    {
        // 插值字符串开始：进入原始区
        if (($id === '"' || $id === '`') && $this->rawStr === '') {
            $this->applyConn($this->connect($id, $text));
            $this->writeTextRaw($text);
            $this->rawStr = $id;
            $this->ws = '';
            return;
        }

        // 起始标签
        if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
            if ($this->fragment && !$this->fragTagDone) {
                $this->fragTagDone = true;
                $this->ws = '';
                $this->pk = 'start';
                return;
            }
            $trimmed = rtrim($text);
            $this->ws .= substr($text, strlen($trimmed)); // 标签尾随空白并入 ws，用于保留空行
            $this->writeTextRaw($trimmed);
            $this->prevId = $id;
            $this->prevText = $trimmed;
            $this->pk = 'open-tag';
            return;
        }

        // 结束标签：其后仅剩空白 HTML 时按 PSR-12 省略（纯 PHP 文件）
        if ($id === T_CLOSE_TAG && $this->tailIsOnlyHtml()) {
            $this->i = $this->n; // 直接结束
            return;
        }

        // 内联 HTML 原样输出
        if ($id === T_INLINE_HTML) {
            $this->writeTextRaw($text);
            $this->prevId = $id;
            $this->prevText = $text;
            $this->pk = 'inline-html';
            $this->ws = '';
            return;
        }

        // heredoc 开始：进入原始区
        if ($id === T_START_HEREDOC) {
            $this->applyConn($this->connect($id, $text));
            $this->writeTextRaw($text);
            $this->heredoc = true;
            $this->prevId = $id;
            $this->prevText = $text;
            $this->pk = 'plain';
            $this->ws = '';
            return;
        }

        // 动态修饰改由 spaceBetween/noAfterPrev 与 post 统一管理

        // PHP7 下 fn/match/enum 以 T_STRING 出现，按上下文识别
        $id = $this->contextualId($id, $text);

        // 缩进预调整（case/default/替代语法结尾等）
        $this->preAdjust($id);

        $text = $this->normalizeText($id, $text);
        $this->applyConn($this->connect($id, $text));
        $this->writeTextRaw($text);

        $this->post($id, $text);
    }

    /* ------------------------------------------------ 连接方式决策 */

    /** 返回 'none' | 'space' | 'nl' | 'nl2' */
    private function connect($id, $text)
    {
        $pk = $this->pk;
        $nl = substr_count($this->ws, "\n");

        if ($pk === 'start') {
            return 'none';
        }
        if ($pk === 'inline-html') {
            return 'none';
        }

        if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
            return 'none';
        }
        if ($id === T_CLOSE_TAG) {
            if ($nl >= 1) {
                $this->pendingIndent = 0; // 模板中关闭标签顶格
                return 'nl';
            }
            return 'space';
        }
        if ($id === T_INLINE_HTML) {
            return 'none';
        }

        // 闭花括号：必须先于 pk 分支判断（空块 {} 时前一个标记是 block-open）
        $st = $this->stackTop();
        if ($id === '}') {
            if ($st !== null && $st['k'] === 'expr') {
                return 'none';
            }
            if ($this->stack === array() || $this->suppressed()) {
                return 'space';
            }
            $this->pendingIndent = ($st['lvl'] - 1) * $this->size;
            return 'nl';
        }

        // 括号组闭括号：拆分组时独占一行并与起始括号对齐
        if ($id === ')' || $id === ']') {
            if ($id === ']' && $this->attr > 0) {
                return $this->pk === 'comma' ? 'space' : 'none';
            }
            $top = $this->parenTop();
            if ($top !== null && $top['split'] && !$this->suppressed()) {
                $this->pendingIndent = $top['base'] * $this->size;
                return 'nl';
            }
            return $this->spaceBetween($id, $text) ? 'space' : 'none';
        }

        // 行注释后必须换行
        if ($pk === 'line-comment') {
            $top = $this->parenTop();
            if ($top !== null) {
                // 在括号组内：缩进与括号组内元素一致
                $this->pendingIndent = ($top['base'] + 1) * $this->size;
            } else {
                // 不在括号组内：使用当前缩进级别
                $this->pendingIndent = $this->level * $this->size;
            }
            return $nl >= 2 ? 'nl2' : 'nl';
        }
        // 块注释/文档注释：跟随原始换行情况
        if ($pk === 'block-comment') {
            return $nl >= 1 ? ($nl >= 2 ? 'nl2' : 'nl') : 'space';
        }
        if ($pk === 'open-tag') {
            return $nl >= 1 ? 'nl' : 'space';
        }

        if ($pk === 'block-open') {
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                return $nl >= 1 ? 'nl' : 'space';
            }
            if ($this->suppressed()) {
                return 'space';
            }
            return 'nl';
        }
        if ($pk === 'stmt-end') {
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                return $nl >= 1 ? ($nl >= 2 ? 'nl2' : 'nl') : 'space';
            }
            if ($this->suppressed()) {
                return 'space';
            }
            // declare / namespace 语句后强制空行
            if ($this->lastStmtK === 'declare' || $this->lastStmtK === 'namespace') {
                return 'nl2';
            }
            // use 导入块结束后（下一条不是 use）强制空行
            if ($this->lastStmtK === 'use' && $id !== T_USE) {
                return 'nl2';
            }
            return $nl >= 2 ? 'nl2' : 'nl';
        }
        if ($pk === 'alt-colon' || $pk === 'case-colon' || $pk === 'label-colon' || $pk === 'attr-end') {
            if ($this->suppressed()) {
                return 'space';
            }
            return 'nl';
        }

        // `}` 之后：else/elseif/catch/finally/do-while 同行；标点紧贴；其余换行（PSR-12）
        if ($pk === 'block-close') {
            if ($id === T_ELSE || $id === T_ELSEIF) {
                return 'space';
            }
            if ($id === T_CATCH || $id === T_FINALLY) {
                return 'space';
            }
            if ($id === T_WHILE && $this->lastBlockK === 'do') {
                return 'space';
            }
            if ($id === ';' || $id === ',' || $id === ')' || $id === ']') {
                return 'none';
            }
            if ($id === T_CLOSE_TAG) {
                return 'space';
            }
            // 类方法之间强制空行
            if ($this->lastBlockK === 'fn'
                && (in_array($id, self::$modifierIds, true) || $id === T_FUNCTION)) {
                return 'nl2';
            }
            return $nl >= 2 ? 'nl2' : 'nl';
        }

        if ($pk === 'comma') {
            // 行内注释（逗号后的 // 或 # 注释）保持同行，不因拆分组而换行
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                return 'space';
            }
            if ($this->suppressed()) {
                return 'space';
            }
            $top = $this->parenTop();
            if ($top !== null && $top['split']) {
                $this->pendingIndent = ($top['base'] + 1) * $this->size;
                return 'nl';
            }
            $st = $this->stackTop();
            if ($st !== null && $st['k'] === 'match' && $this->parens === array()) {
                $this->pendingIndent = $st['lvl'] * $this->size;
                return 'nl';
            }
            return 'space';
        }

        // 花括号：类/枚举/命名函数独占一行，其余同行
        if ($id === '{') {
            $fnSplitParams = ($this->decl === 'fn' && $this->fnNamed
                && $this->lastParenK === 'params' && $this->lastParenSplit);
            if (($this->decl === 'class' || $this->decl === 'enum'
                || ($this->decl === 'fn' && $this->fnNamed)) && !$fnSplitParams) {
                return $this->suppressed() ? 'space' : 'nl';
            }
            return $this->spaceBetween($id, $text) ? 'space' : 'none';
        }

        // 拆分组：`(` 或 `[` 后首个 token 换行；ctrl 组布尔运算符 &&/|| 前换行
        $top = $this->parenTop();
        if ($top !== null && $top['split'] && !$this->suppressed()) {
            // `(` / `[` 后第一个 token：换行并缩进
            if ($this->prevId === '(' || $this->prevId === '[') {
                $this->pendingIndent = ($top['base'] + 1) * $this->size;
                return 'nl';
            }
            // ctrl 组：布尔运算符 && / || 前换行
            if ($top['k'] === 'ctrl'
                && ($id === T_BOOLEAN_AND || $id === T_BOOLEAN_OR) && $this->prevId !== '(') {
                $this->pendingIndent = ($top['base'] + 1) * $this->size;
                return 'nl';
            }
        }

        // 注释跟随原始换行
        if (($id === T_COMMENT || $id === T_DOC_COMMENT) && $nl >= 1) {
            return $nl >= 2 ? 'nl2' : 'nl';
        }

        return $this->spaceBetween($id, $text) ? 'space' : 'none';
    }

    /* ------------------------------------------------ 空隙规则 */

    private function spaceBetween($id, $text)
    {
        $p = $this->prevId;
        if ($p === null) {
            return false;
        }
        $afterForbidden = $this->noAfterPrev($p); // 先读取上一 token 的动态修饰
        // 为当前 token 重置动态修饰（随后由 post 重新判定）
        $this->unarySign = false;
        $this->incPrefix = false;
        $this->nullableQ = false;
        $this->nullsafeQ = false;
        $this->ampRef = false;
        $this->unionBar = false;
        if ($afterForbidden) {
            return false;
        }
        if ($this->noBeforeCur($id, $text)) {
            return false;
        }
        return true; // 默认一个空格
    }

    private function noAfterPrev($p)
    {
        if ($p === '(' || $p === '[') {
            return true;
        }
        if ($p === '~' || $p === '@' || $p === '$') {
            return true;
        }
        if ($p === T_OBJECT_OPERATOR || $p === T_NULLSAFE_OBJECT_OPERATOR
            || $p === T_DOUBLE_COLON || $p === T_NS_SEPARATOR || $p === T_ATTRIBUTE) {
            return true;
        }
        if ($p === T_ELLIPSIS) {
            return true;
        }
        if ($p === '&' || $p === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            || $p === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            return $this->ampRef; // 引用/类型交集后紧贴
        }
        if ($p === '|') {
            return $this->unionBar;
        }
        if ($p === T_INC || $p === T_DEC) {
            return $this->incPrefix;
        }
        if ($p === '+' || $p === '-') {
            return $this->unarySign;
        }
        if ($p === '=') {
            $top = $this->parenTop();
            return ($top !== null && $top['k'] === 'declare'); // declare(strict_types=1)
        }
        if ($p === '{') {
            return true; // 表达式花括号内紧贴
        }
        if ($p === '?') {
            return ($this->nullableQ || $this->nullsafeQ);
        }
        return false;
    }

    private function noBeforeCur($id, $text)
    {
        $p = $this->prevId;

        if ($id === ')' || $id === ']' || $id === ',' || $id === ';') {
            return true;
        }
        if ($id === ':') {
            if ($this->tern > 0) {
                return ($this->prevId === '?'); // 短三元 ?: 紧贴，普通三元冒号前留空格
            }
            return true; // 其余冒号前不留空格
        }
        if ($id === '->' || $id === T_OBJECT_OPERATOR || $id === T_DOUBLE_COLON
            || $id === T_NULLSAFE_OBJECT_OPERATOR || $id === T_NS_SEPARATOR) {
            return !($this->isWordId($p) || $p === ':' || $p === ',');
        }
        if ($id === T_INC || $id === T_DEC) {
            return ($this->isIdentId($p) || $p === ')' || $p === ']'); // 后缀紧贴
        }
        if ($id === '?') {
            // ?-> 空安全（PHP7 分词为 ? 与 ->）
            if ($this->isNullsafeAt()) {
                return true;
            }
            if ($this->isNullableAt()) {
                return false;
            }
            return false; // 三元 ? 前留空格
        }
        if ($id === '(') {
            return $this->callParen($p); // 函数调用紧贴，控制结构留空格
        }
        if ($id === '[') {
            return ($this->isIdentId($p) || $p === ')' || $p === ']');
        }
        if ($id === '!' || $id === '~') {
            return false;
        }
        if ($id === '+' || $id === '-') {
            if ($this->isIdentId($p) || $p === ')' || $p === ']') {
                return false; // 二元运算符
            }
            if ($p === '(' || $p === '[' || $p === '!' || $p === '~' || $p === '+' || $p === '-') {
                return true;
            }
            return false; // = 、=> 、关键字后的一元符号保留空格
        }
        if (in_array($id, self::$castIds, true)) {
            return ($p === '(' || $p === '[' || $p === '!' || $p === '~');
        }
        if ($id === '&' || $id === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            || $id === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            return $this->isUnionAt(); // 类型交集 A&B 紧贴；其余 & 前侧由上一 token 决定
        }
        if ($id === '|') {
            return $this->isUnionAt(); // 类型联合：int|string 紧贴
        }
        if ($id === '=') {
            $top = $this->parenTop();
            if ($top !== null && $top['k'] === 'declare') {
                return true; // declare(strict_types=1) 内不留空格
            }
            return false;
        }
        return false;
    }

    private function callParen($p)
    {
        if ($this->isIdentId($p)) {
            return true;
        }
        if ($p === ')' || $p === ']') {
            return true;
        }
        if (in_array($p, self::$funcLikeIds, true)) {
            return true;
        }
        if ($p === T_FN) {
            return true; // fn($x)
        }
        if ($p === T_CLASS && $this->decl === 'anon') {
            return true; // new class(
        }
        return false;
    }

    /* ------------------------------------------------ 预调整 */

    private function preAdjust($id)
    {
        if ($id === T_CASE) {
            $st = $this->stackTop();
            if ($st !== null && ($st['k'] === 'switch' || $st['k'] === 'alt-switch')) {
                $this->level = $st['lvl'];
                $this->inCase = true;
                $this->caseParen = count($this->parens);
            }
        } elseif ($id === T_DEFAULT) {
            $st = $this->stackTop();
            if ($st !== null && ($st['k'] === 'switch' || $st['k'] === 'alt-switch')) {
                $this->level = $st['lvl'];
                $this->inDefault = true;
            }
        } elseif ($id === T_ELSEIF || $id === T_ELSE) {
            // 替代语法中的 elseif / else
            $st = $this->stackTop();
            if ($st !== null && $st['k'] === 'alt-if' && $this->pk !== 'block-close') {
                $this->level = $st['lvl'] - 1;
                $this->altOpener = ($id === T_ELSEIF) ? 'elseif' : 'else';
            }
        } elseif ($id === T_ENDIF || $id === T_ENDFOREACH || $id === T_ENDFOR
            || $id === T_ENDWHILE || $id === T_ENDSWITCH || $id === T_ENDDECLARE) {
            $want = null;
            if ($id === T_ENDIF) {
                $want = array('alt-if', 'alt-else');
            } elseif ($id === T_ENDFOREACH) {
                $want = array('alt-foreach');
            } elseif ($id === T_ENDFOR) {
                $want = array('alt-for');
            } elseif ($id === T_ENDWHILE) {
                $want = array('alt-while');
            } elseif ($id === T_ENDSWITCH) {
                $want = array('alt-switch');
            } else {
                $want = array('alt-declare');
            }
            $st = $this->stackTop();
            if ($st !== null && in_array($st['k'], $want, true)) {
                $e = array_pop($this->stack);
                $this->level = $e['lvl'] - 1;
            }
        }
    }

    /* ------------------------------------------------ 后置状态 */

    private function post($id, $text)
    {
        $pk = 'plain';

        if ($id === ';') {
            // 记录刚结束的语句类型（用于 declare/namespace 后强制空行）
            if ($this->prevId === ')' && $this->lastParenK === 'declare') {
                $this->lastStmtK = 'declare';
            } elseif ($this->prevId === T_STRING || $this->prevId === T_NAME_QUALIFIED
                || $this->prevId === T_NAME_FULLY_QUALIFIED || $this->prevId === T_NAME_RELATIVE) {
                $this->lastStmtK = $this->detectStmtK();
            } else {
                $this->lastStmtK = 'other';
            }
            $this->decl = '';
            $this->useClosure = false;
            $pk = 'stmt-end';
        } elseif ($id === '?') {
            // 判定并记录：空安全 ?-> / 可空类型 ?Type / 三元
            if ($this->isNullableAt()) {
                $this->nullableQ = true;
            } elseif ($this->isNullsafeAt()) {
                $this->nullsafeQ = true;
            } else {
                $this->tern++;
            }
        } elseif ($id === '&' || $id === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            || $id === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            // 引用 &$var / function &foo() / 类型交集 A&B → 之后紧贴
            $this->ampRef = ($id === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
                || $this->isAmpRefAt()
                || ($this->prevId === T_FUNCTION)
                || $this->isUnionAt();
        } elseif ($id === '|') {
            $this->unionBar = $this->isUnionAt();
        } elseif ($id === '+' || $id === '-') {
            $this->unarySign = !($this->isIdentId($this->prevId) || $this->prevId === ')' || $this->prevId === ']');
        } elseif ($id === T_INC || $id === T_DEC) {
            $this->incPrefix = !($this->isIdentId($this->prevId) || $this->prevId === ')' || $this->prevId === ']');
        } elseif ($id === '{') {
            if ($this->prevId === T_OBJECT_OPERATOR || $this->prevId === T_NULLSAFE_OBJECT_OPERATOR
                || $this->prevId === '$') {
                // 表达式花括号：$obj->{...}、${...}（内容紧贴，不开启新行）
                $this->stack[] = array('k' => 'expr', 'lvl' => $this->level + 1);
                $this->prevId = $id;
                $this->prevText = $text;
                $this->pk = 'plain';
                $this->ws = '';
                return;
            } else {
                $k = 'block';
                if ($this->decl === 'class' || $this->decl === 'enum') {
                    $k = $this->decl;
                } elseif ($this->decl === 'fn') {
                    $k = $this->fnNamed ? 'fn' : 'closure';
                } elseif ($this->decl === 'match') {
                    $k = 'match';
                } elseif ($this->decl === 'anon') {
                    $k = 'anon';
                } elseif ($this->decl === 'ctrl') {
                    $k = ($this->ctrlK === '') ? 'block' : $this->ctrlK;
                }
                $this->level++;
                $this->stack[] = array('k' => $k, 'lvl' => $this->level, 'pd'  => count($this->parens));
            }
            $this->decl = '';
            $pk = 'block-open';
        } elseif ($id === '}') {
            $e = null;
            if ($this->stack !== array()) {
                $e = array_pop($this->stack);
                $this->level = $e['lvl'] - 1;
            }
            $this->lastBlockK = ($e === null) ? '' : $e['k'];
            $this->decl = '';
            $pk = ($e !== null && $e['k'] === 'expr') ? 'plain' : 'block-close';
        } elseif ($id === '(') {
            $k = 'call';
            if ($this->useClosure && $this->prevId === T_USE) {
                $k = 'use';
                $this->useClosure = false;
            } elseif ($this->decl === 'fn') {
                $k = 'params';
            } elseif ($this->prevId === T_FOR) {
                $k = 'for';
            } elseif ($this->prevId === T_DECLARE) {
                $k = 'declare';
            } elseif (in_array($this->prevId, self::$ctrlParenIds, true)) {
                $k = 'ctrl';
            } elseif ($this->prevId === T_ARRAY) {
                $k = 'arr';
            }
            $split = false;
            if ($k === 'ctrl') {
                $split = $this->wantCtrlSplit();
            } elseif ($k === 'params' || $k === 'use' || $k === 'arr' || $k === 'call') {
                $split = $this->wantSplit($k);
            }
            $this->parens[] = array('k' => $k, 'o' => '(', 'split' => $split, 'base' => $this->level);
        } elseif ($id === '[') {
            if ($this->isIdentId($this->prevId) || $this->prevId === ')' || $this->prevId === ']') {
                $this->parens[] = array('k' => 'index', 'o' => '[', 'split' => false, 'base' => $this->level);
            } else {
                $this->parens[] = array('k' => 'arr', 'o' => '[', 'split' => $this->wantSplit('arr'), 'base' => $this->level);
            }
        } elseif ($id === ')') {
            if ($this->parens !== array() && $this->parenTop()['o'] === '(') {
                $e = array_pop($this->parens);
                $this->lastParenK = $e['k'];
                $this->lastParenSplit = $e['split'];
            }
        } elseif ($id === ']') {
            $top = $this->parenTop();
            if ($top !== null && $top['o'] === '[') {
                $e = array_pop($this->parens);
                $this->lastParenK = $e['k'];
                $this->lastParenSplit = $e['split'];
            } elseif ($this->attr > 0) {
                $this->attr = 0;
                $pk = 'attr-end';
            }
        } elseif ($id === ',') {
            $pk = 'comma';
        } elseif ($id === ':') {
            $pk = $this->postColon();
        } elseif ($id === T_ATTRIBUTE) {
            $this->attr = 1;
        } elseif ($id === T_CLOSE_TAG) {
            $pk = 'close-tag';
        } elseif ($id === T_COMMENT) {
            $pk = (strpos($text, '//') === 0 || $text[0] === '#') ? 'line-comment' : 'block-comment';
        } elseif ($id === T_DOC_COMMENT) {
            $pk = 'block-comment';
        } elseif ($this->isCtrlKeyword($id)) {
            $this->decl = 'ctrl';
            $this->ctrlK = strtolower($text);
        } elseif ($id === T_MATCH) {
            $this->decl = 'match';
            $this->ctrlK = 'match';
        } elseif ($id === T_FUNCTION) {
            if ($this->prevId !== T_USE) { // 排除 use function 导入
                $this->decl = 'fn';
                $this->fnNamed = $this->fnIsNamed();
            }
        } elseif ($id === T_CLASS) {
            if ($this->prevId !== T_DOUBLE_COLON) { // 排除 Foo::class
                $this->decl = ($this->prevId === T_NEW) ? 'anon' : 'class';
            }
        } elseif ($id === T_INTERFACE || $id === T_TRAIT) {
            $this->decl = 'class';
        } elseif ($id === T_ENUM) {
            $this->decl = 'enum';
        } elseif ($id === T_NAMESPACE) {
            if ($this->nextTok() === '{') {
                $this->decl = 'ns';
            }
        } elseif ($id === T_USE) {
            if ($this->decl === 'fn' && $this->prevId === ')') {
                $this->useClosure = true; // 闭包 use (...)
            }
        }

        $this->prevId = $id;
        $this->prevText = $text;
        $this->pk = $pk;
        $this->ws = '';
    }

    /** 回溯检测刚结束的语句关键字 */
    private function detectStmtK()
    {
        $depth = 0;
        for ($j = $this->i - 1; $j >= 0; $j--) {
            $tid = $this->toks[$j][0];
            if ($tid === ')' || $tid === ']') {
                $depth++;
            } elseif ($tid === '(' || $tid === '[') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0) {
                if ($tid === ';') {
                    return 'other';
                }
                if ($tid === T_DECLARE) {
                    return 'declare';
                }
                if ($tid === T_NAMESPACE) {
                    return 'namespace';
                }
                if ($tid === T_USE) {
                    return 'use';
                }
            }
        }
        return 'other';
    }

    /** 冒号的处理：返回语义标记 */
    private function postColon()
    {
        $p = $this->prevId;

        if ($this->tern > 0) {
            $this->tern--;
            return 'plain'; // 三元冒号
        }
        if ($p === T_ELSE) {
            // 替代语法中的 else:（前面没有括号）
            $st = $this->stackTop();
            if ($st !== null && ($st['k'] === 'alt-if' || $st['k'] === 'alt-else')) {
                $e = array_pop($this->stack);
                $this->level = $e['lvl'] - 1;
                $this->level++;
                $this->stack[] = array('k' => 'alt-else', 'lvl' => $this->level);
                $this->decl = '';
                $this->ctrlK = '';
                return 'alt-colon';
            }
        }
        if ($this->inCase && count($this->parens) === $this->caseParen) {
            $st = $this->stackTop();
            if ($st !== null) {
                $this->level = $st['lvl'] + 1;
            }
            $this->inCase = false;
            return 'case-colon';
        }
        if ($this->inDefault) {
            $st = $this->stackTop();
            if ($st !== null) {
                $this->level = $st['lvl'] + 1;
            }
            $this->inDefault = false;
            return 'case-colon';
        }
        if ($p === ')' && $this->lastParenK === 'ctrl') {
            // 替代语法：if (...): foreach (...):
            $opener = ($this->altOpener !== '') ? $this->altOpener : $this->ctrlK;
            $this->altOpener = '';
            if ($opener === 'elseif' || $opener === 'else') {
                $st = $this->stackTop();
                if ($st !== null && ($st['k'] === 'alt-if' || $st['k'] === 'alt-else')) {
                    $e = array_pop($this->stack);
                    $this->level = $e['lvl'] - 1;
                }
            }
            $this->level++;
            $kind = 'alt-' . ($opener === 'else' ? 'else' : ($opener === 'elseif' ? 'if' : $opener));
            $this->stack[] = array('k' => $kind, 'lvl' => $this->level);
            $this->decl = '';
            $this->ctrlK = '';
            return 'alt-colon';
        }
        if ($p === ')' && ($this->lastParenK === 'params' || $this->lastParenK === 'use')) {
            return 'ret-colon'; // 返回类型冒号
        }
        if ($this->decl === 'enum') {
            return 'ret-colon'; // enum Foo: string
        }
        return 'label-colon'; // goto 标签
    }

    /* ------------------------------------------------ 修饰符排序 */

    private function tryModifiers($id, $text)
    {
        if (!in_array($id, self::$modifierIds, true)) {
            return false;
        }
        if (!$this->atStmtStart()) {
            return false;
        }
        // 收集连续修饰符
        $run = array();
        $j = $this->i;
        $last = $this->i;
        while ($j < $this->n) {
            $tid = $this->toks[$j][0];
            if ($tid === T_WHITESPACE) {
                $j++;
                continue;
            }
            if (in_array($tid, self::$modifierIds, true)) {
                $run[] = array($tid, $this->toks[$j][1]);
                $last = $j;
                $j++;
                continue;
            }
            break;
        }
        if (count($run) < 2) {
            return false; // 单个修饰符走常规路径
        }
        // 稳定排序：abstract/final → 可见性 → readonly → static
        $order = array();
        foreach ($run as $idx => $m) {
            $order[] = array(self::$modRank[$m[0]], $idx, $m);
        }
        usort($order, array($this, 'cmpMod'));
        foreach ($order as $o) {
            $this->emit($o[2][0], $o[2][1]);
        }
        $this->i = $last;
        return true;
    }

    private function cmpMod($a, $b)
    {
        if ($a[0] === $b[0]) {
            return ($a[1] < $b[1]) ? -1 : 1;
        }
        return ($a[0] < $b[0]) ? -1 : 1;
    }

    /* ---------------------------------------- use 导入分组排序 */

    private function tryUseImports($id, $text)
    {
        if ($id !== T_USE) {
            return false;
        }
        if (!$this->atStmtStart()) {
            return false;
        }
        // 排除闭包 use
        if ($this->decl === 'fn' && $this->prevId === ')') {
            return false;
        }
        // 收集连续的 use 语句（允许中间有空行/注释）
        $stmts = array();
        $j = $this->i;
        $lastEnd = $this->i;
        while ($j < $this->n) {
            $tid = $this->toks[$j][0];
            if ($tid === T_WHITESPACE) {
                $j++;
                continue;
            }
            if ($tid !== T_USE) {
                break;
            }
            // 解析一条 use 语句：从 T_USE 到 ;
            $start = $j;
            $depth = 0;
            $k = $j;
            $group = 'class'; // class/function/const
            $k++;
            while ($k < $this->n) {
                $ktid = $this->toks[$k][0];
                if ($ktid === T_WHITESPACE) {
                    $k++;
                    continue;
                }
                if ($ktid === T_FUNCTION) {
                    $group = 'function';
                } elseif ($ktid === T_CONST) {
                    $group = 'const';
                }
                if ($ktid === '(' || $ktid === '[') {
                    $depth++;
                } elseif ($ktid === ')' || $ktid === ']') {
                    $depth--;
                } elseif ($ktid === ';' && $depth === 0) {
                    break;
                }
                $k++;
            }
            if ($k >= $this->n) {
                break; // 没找到 ; ，不处理
            }
            // 提取用于排序的键（跳过 use / function / const 关键字后的第一个名称）
            $sortKey = '';
            $kk = $start + 1;
            while ($kk < $k) {
                $ktid = $this->toks[$kk][0];
                if ($ktid === T_WHITESPACE) {
                    $kk++;
                    continue;
                }
                if ($ktid === T_FUNCTION || $ktid === T_CONST) {
                    $kk++;
                    continue;
                }
                $sortKey = strtolower($this->toks[$kk][1]);
                break;
            }
            $stmts[] = array(
                'group' => $group,
                'sortKey' => $sortKey,
                'start' => $start,
                'end' => $k,
            );
            $lastEnd = $k;
            $j = $k + 1;
        }
        if (count($stmts) < 2) {
            return false; // 单条 use 走常规路径
        }
        // 分组排序：class → function → const，组内按 sortKey 字母序
        $groupRank = array('class' => 0, 'function' => 1, 'const' => 2);
        usort($stmts, function ($a, $b) use ($groupRank) {
            $ga = $groupRank[$a['group']];
            $gb = $groupRank[$b['group']];
            if ($ga !== $gb) {
                return ($ga < $gb) ? -1 : 1;
            }
            return strcmp($a['sortKey'], $b['sortKey']);
        });
        // 逐条输出，组间空行
        $prevGroup = null;
        foreach ($stmts as $s) {
            if ($prevGroup !== null && $s['group'] !== $prevGroup) {
                // 组间空行：在 connect 中通过 nl2 实现
                $this->ws .= "\n\n"; // 确保空行
            }
            // 输出从 start 到 end（含 ;) 的所有非空白 token
            for ($kk = $s['start']; $kk <= $s['end']; $kk++) {
                $tid = $this->toks[$kk][0];
                $tt = $this->toks[$kk][1];
                if ($tid === T_WHITESPACE) {
                    continue;
                }
                $this->i = $kk;
                $this->emit($tid, $tt);
            }
            $prevGroup = $s['group'];
        }
        $this->i = $lastEnd;
        $this->lastStmtK = 'use';
        return true;
    }

    private function atStmtStart()
    {
        $pk = $this->pk;
        if (in_array($pk, array(
            'start', 'stmt-end', 'block-open', 'block-close', 'alt-colon', 'case-colon',
            'open-tag', 'attr-end', 'line-comment', 'inline-html',
        ), true)) {
            return true;
        }
        if ($pk === 'block-comment' && substr_count($this->ws, "\n") >= 1) {
            return true;
        }
        return false;
    }

    /* ------------------------------------------------ 工具方法 */

    private function isCtrlKeyword($id)
    {
        return in_array($id, array(
            T_IF, T_ELSEIF, T_ELSE, T_FOR, T_FOREACH, T_WHILE, T_SWITCH,
            T_DO, T_TRY, T_CATCH, T_FINALLY, T_DECLARE,
        ), true);
    }

    private function fnIsNamed()
    {
        // function foo( → 命名；function ( 或 function &( → 闭包
        $j = $this->i + 1;
        while ($j < $this->n) {
            $tid = $this->toks[$j][0];
            if ($tid === T_WHITESPACE) {
                $j++;
                continue;
            }
            if ($tid === T_STRING || $tid === T_NAME_QUALIFIED || $tid === T_NAME_FULLY_QUALIFIED) {
                return true;
            }
            if ($tid === '&' || $tid === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
                || $tid === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                $j++;
                continue;
            }
            return false;
        }
        return false;
    }

    private function contextualId($id, $text)
    {
        if ($id !== T_STRING) {
            return $id;
        }
        if ($this->prevId === T_OBJECT_OPERATOR || $this->prevId === T_DOUBLE_COLON
            || $this->prevId === T_NULLSAFE_OBJECT_OPERATOR) {
            return $id;
        }
        $low = strtolower($text);
        if ($low === 'fn' && $this->nextTok() === '(') {
            return T_FN;
        }
        if ($low === 'match' && $this->nextTok() === '(') {
            return T_MATCH;
        }
        if ($low === 'enum' && $this->atStmtStart()
            && in_array($this->nextTok(), array(T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE), true)) {
            return T_ENUM;
        }
        return $id;
    }

    private function normalizeText($id, $text)
    {
        if (in_array($id, self::$wordIds, true)) {
            if ($id === T_YIELD_FROM) {
                return 'yield from';
            }
            return strtolower($text);
        }
        if ($id === T_STRING) {
            $low = strtolower($text);
            if (($low === 'true' || $low === 'false' || $low === 'null')
                && !in_array($this->prevId, array(T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR), true)) {
                return $low;
            }
        }
        if ($id === T_COMMENT) {
            $text = rtrim($text);
            // 单行注释 // 后面确保至少一个空格
            if (strpos($text, '//') === 0 && strlen($text) > 2 && $text[2] !== ' ' && $text[2] !== "\t") {
                $text = '// ' . substr($text, 2);
            }
            return $text;
        }
        if ($id === T_DOC_COMMENT) {
            return rtrim($text);
        }
        return $text;
    }

    /* ---------------- 动态修饰判定（noBeforeCur 与 post 共用） ---------------- */

    private function isNullableAt()
    {
        // ?string / ?int 等可空类型（与类型之间不留空格）
        $nt = $this->nextTok();
        if (!in_array($nt, self::$typeIds, true) || !$this->nextTight()) {
            return false;
        }
        $top = $this->parenTop();
        $inTypeCtx = ($this->pk === 'ret-colon'
            || $this->prevId === '(' || $this->prevId === ','
            || ($top !== null && $top['k'] === 'params'));
        return $inTypeCtx;
    }

    private function isNullsafeAt()
    {
        // ?-> 空安全运算符（PHP7 分词为 ? 与 ->）
        return ($this->nextTok() === T_OBJECT_OPERATOR && $this->nextTight());
    }

    private function isAmpRefAt()
    {
        // 引用传递 &$var / &...$args
        $nt = $this->nextTok();
        return (($nt === T_VARIABLE || $nt === T_ELLIPSIS) && $this->nextTight());
    }

    private function isUnionAt()
    {
        // 类型联合 int|string / 类型交集 A&B
        return (in_array($this->prevId, self::$typeIds, true)
            && in_array($this->nextTok(), self::$typeIds, true)
            && $this->nextTight());
    }

    private function wantCtrlSplit()
    {
        if ($this->maxLen <= 0) {
            return false;
        }
        // 检查控制结构条件中是否包含 && 或 ||（顶层）
        $depth = 1;
        $hasBool = false;
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            $tid = $this->toks[$j][0];
            if ($tid === '(' || $tid === '[') {
                $depth++;
            } elseif ($tid === ')' || $tid === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && ($tid === T_BOOLEAN_AND || $tid === T_BOOLEAN_OR)) {
                $hasBool = true;
            }
        }
        return $hasBool;
    }

    private function wantSplit($kind)
    {
        // 估算单行长度并检查顶层逗号与原始换行
        $depth = 1;
        $hasComma = false;
        $hasNl = false; // 原始代码中括号组内是否有换行
        $len = 1;
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            $tid = $this->toks[$j][0];
            $tt = $this->toks[$j][1];
            if ($tid === '(' || $tid === '[') {
                $depth++;
            } elseif ($tid === ')' || $tid === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($tid === ',') {
                if ($depth === 1) {
                    $hasComma = true;
                }
            } elseif ($tid === T_WHITESPACE) {
                if ($depth === 1 && strpos($tt, "\n") !== false) {
                    $hasNl = true;
                }
            }
            if ($tid !== T_WHITESPACE) {
                $len += strlen($tt) + 1;
            }
        }
        if (!$hasComma) {
            return false;
        }
        if ($this->maxLen <= 0) {
            // 未设置行长度限制时：仅在原始代码已多行时保持拆分
            return $hasNl;
        }
        return $hasNl || ($this->lineLen + $len > $this->maxLen);
    }

    private function suppressed()
    {
        // 在未拆分的括号组内部（如作为参数的闭包）、属性内部：不强制换行
        if ($this->attr > 0) {
            return true;
        }
        $top = $this->parenTop();
        if ($top !== null) {
            $st = $this->stackTop();
            if ($st !== null && $st['k'] !== 'expr' && isset($st['pd']) && $st['pd'] >= count($this->parens)) {
                return false;
            }
            return !$top['split'];
        }
        return false;
    }

    private function tailIsOnlyHtml()
    {
        // 结束标签之后是否仅剩空白 HTML（纯 PHP 文件 → 省略结尾的关闭标签）
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            $tid = $this->toks[$j][0];
            $tt = $this->toks[$j][1];
            if ($tid === T_INLINE_HTML) {
                if (trim($tt) !== '') {
                    return false;
                }
            } elseif ($tid === T_WHITESPACE) {
                continue;
            } else {
                return false;
            }
        }
        return true;
    }

    private function nextTok()
    {
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            $tid = $this->toks[$j][0];
            if ($tid !== T_WHITESPACE) {
                return $tid;
            }
        }
        return -1;
    }

    private function nextTight()
    {
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            return $this->toks[$j][0] !== T_WHITESPACE;
        }
        return false;
    }

    private function parenTop()
    {
        if ($this->parens === array()) {
            return null;
        }
        return $this->parens[count($this->parens) - 1];
    }

    private function stackTop()
    {
        if ($this->stack === array()) {
            return null;
        }
        return $this->stack[count($this->stack) - 1];
    }

    private function isWordId($id)
    {
        return in_array($id, self::$wordIds, true);
    }

    private function isIdentId($id)
    {
        return in_array($id, self::$identIds, true);
    }

    private function applyConn($conn)
    {
        if ($conn === 'nl' || $conn === 'nl2') {
            $this->out .= str_repeat("\n", ($conn === 'nl2') ? 2 : 1);
            $ind = ($this->pendingIndent === null) ? $this->level * $this->size : $this->pendingIndent;
            $this->pendingIndent = null;
            if ($ind > 0) {
                $this->out .= str_repeat(' ', $ind);
            }
            $this->lineLen = $ind;
        } elseif ($conn === 'space') {
            $this->out .= ' ';
            $this->lineLen += 1;
        }
    }

    private function writeTextRaw($text)
    {
        $this->out .= $text;
        $p = strrpos($text, "\n");
        if ($p === false) {
            $this->lineLen += strlen($text);
        } else {
            $this->lineLen = strlen($text) - $p - 1;
        }
    }
}

/* ------------------------------------------------ CLI 入口 */

function fmt_main()
{
    $argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
    array_shift($argv);

    $file = null;
    $maxLen = 0;
    $alignComments = false;
    $alignEquals = false;
    $fragment = false;
    $initLevel = 0;
    $help = false;

    foreach ($argv as $a) {
        if ($a === '--align-comments') {
            $alignComments = true;
        } elseif ($a === '--align-equals') {
            $alignEquals = true;
        } elseif ($a === '--fragment') {
            $fragment = true;
        } elseif ($a === '--help' || $a === '-h') {
            $help = true;
        } elseif (strpos($a, '--max-line-length=') === 0) {
            $maxLen = (int)substr($a, 18);
        } elseif (strpos($a, '--initial-level=') === 0) {
            $initLevel = (int)substr($a, 17);
        } elseif ($a === '') {
            continue;
        } elseif ($a[0] === '-') {
            fwrite(STDERR, "警告：忽略未知选项 {$a}\n");
        } else {
            $file = $a;
        }
    }

    if ($help) {
        $help = <<<'EOT'
fmt.php —— PHP 代码格式化工具（PSR-12，兼容 PHP 7/8）

用法：
  php fmt.php [选项] [文件]
  cat 文件 | php fmt.php [选项]

选项：
  --max-line-length=N   每行最大长度，0 表示不限制（默认 0）
  --align-comments      对齐连续代码行的行内注释
  --align-equals        对齐连续赋值语句的等号
  --fragment            格式化代码片段（不要求 <?php 开头，输出不含起始标签）
  --initial-level=N     片段起始缩进级别（配合 --fragment，用于选区格式化）
  --help, -h            显示本帮助

说明：输出以 UTF-8 写入标准输出；Windows 控制台显示中文可能乱码，不影响字节内容。
EOT;
        fwrite(STDOUT, $help . "\n");
        return 0;
    }

    if ($file !== null) {
        $code = @file_get_contents($file);
        if ($code === false) {
            fwrite(STDERR, "错误：无法读取文件：{$file}\n");
            return 1;
        }
    } else {
        $code = stream_get_contents(STDIN);
        if ($code === false) {
            fwrite(STDERR, "错误：无法读取标准输入。\n");
            return 1;
        }
    }

    $f = new Fmt();
    $f->maxLen = max(0, $maxLen);
    $f->alignComments = $alignComments;
    $f->alignEquals = $alignEquals;
    $f->fragment = $fragment;
    $f->initialLevel = max(0, $initLevel);

    try {
        $out = $f->format($code);
    } catch (Exception $e) {
        fwrite(STDERR, '错误：格式化失败：' . $e->getMessage() . "\n");
        return 1;
    }

    if ($file !== null) {
        file_put_contents($file, $out);
    } else {
        fwrite(STDOUT, $out);
    }
    return 0;
}

exit(fmt_main());
