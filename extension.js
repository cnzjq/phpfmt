'use strict';

const vscode = require('vscode');
const fs = require('fs');
const path = require('path');
const os = require('os');
const cp = require('child_process');

let output = null;              // 输出通道，用于诊断信息
let saveDisposable = null;      // 保存事件监听
let closeDisposable = null;     // 关闭事件监听
let tempFiles = new Set();      // 尚未清理的临时文件
let tempSeq = 0;                // 临时文件名计数器
let notifiedErrors = new Set(); // 已提示过的错误（避免弹窗刷屏）

const PHP_LANGUAGE = 'php';
const FMT_SCRIPT = path.join(__dirname, 'fmt.php');

/** 读取并缓存扩展配置 */
function getConfig() {
    return vscode.workspace.getConfiguration('fmt');
}

/** 输出诊断信息 */
function log(msg) {
    if (output) {
        output.appendLine('[' + new Date().toLocaleTimeString() + '] ' + msg);
    }
}

/** 弹出错误提示（同一内容只提示一次，避免刷屏） */
function notifyError(message) {
    log(message);
    if (notifiedErrors.has(message)) {
        return;
    }
    notifiedErrors.add(message);
    vscode.window.showErrorMessage('PHP格式化：' + message);
}

/** 创建用于保存待格式化内容的临时 php 文件（UTF-8 无 BOM） */
function createTempFile(content) {
    const file = path.join(
        os.tmpdir(),
        'php-fmt-' + process.pid + '-' + (++tempSeq) + '.php'
    );
    fs.writeFileSync(file, content, 'utf8');
    tempFiles.add(file);
    return file;
}

function removeTempFile(file) {
    try {
        if (file && tempFiles.has(file)) {
            tempFiles.delete(file);
            fs.unlinkSync(file);
        }
    } catch (e) {
        // 临时文件删除失败不影响格式化结果，仅在输出通道中记录
        log('清理临时文件失败：' + (e && e.message ? e.message : e));
    }
}

/**
 * 调用 fmt.php 格式化代码。
 * 完整文件走完整模式；选区片段走 --fragment 模式（不要求 <?php 起始标签）。
 * 返回格式化后的文本；失败或内容无变化时返回 null（保留原文）。
 */
async function runFmt(document, range) {
    const cfg = getConfig();

    const phpPath = String(cfg.get('phpPath', 'php') || 'php').trim();
    if (!phpPath) {
        notifyError('未配置 PHP 可执行文件路径，请在设置中配置 fmt.phpPath。');
        return null;
    }
    if (phpPath !== 'php' && !fs.existsSync(phpPath)) {
        notifyError('PHP 可执行文件不存在：' + phpPath);
        return null;
    }
    if (!fs.existsSync(FMT_SCRIPT)) {
        notifyError('未找到格式化脚本：' + FMT_SCRIPT);
        return null;
    }

    const maxLineLength = Math.max(0, parseInt(cfg.get('maxLineLength', 0), 10) || 0);
    const alignEquals = cfg.get('alignEquals', false) === true;
    const alignComments = cfg.get('alignComments', false) === true;

    const isFragment = !!range;
    const original = isFragment ? document.getText(range) : document.getText();

    // 构造命令行参数（不使用 shell，逐参数传递，避免转义问题）
    const args = [];
    if (isFragment) {
        args.push('--fragment');
        const firstLine = document.lineAt(range.start.line).text;
        const indentChars = Math.max(0, range.start.character);
        args.push('--initial-level=' + Math.floor(indentChars / 4));
    }
    if (maxLineLength > 0) {
        args.push('--max-line-length=' + maxLineLength);
    }
    if (alignEquals) {
        args.push('--align-equals');
    }
    if (alignComments) {
        args.push('--align-comments');
    }

    const file = createTempFile(original);
    log('执行格式化：' + phpPath + ' ' + [FMT_SCRIPT].concat(args, [file]).join(' '));

    let result = null;
    try {
        result = await execFormatter(phpPath, args, file);
    } finally {
        removeTempFile(file);
    }

    if (!result.ok) {
        if (result.launchError) {
            notifyError('无法启动 PHP，请检查 fmt.phpPath 配置（当前：' + phpPath + '）。' + result.stderr);
        } else {
            notifyError('格式化失败：' + (result.stderr || result.stdout || ('退出码 ' + result.code)));
        }
        return null;
    }

    if (typeof result.text !== 'string' || result.text === '') {
        return null;
    }
    if (result.text === original) {
        return null; // 内容无变化，无需产生编辑
    }
    return result.text;
}

/** 执行 php fmt.php [选项] <临时文件>，返回 { ok, code, text, stdout, stderr, launchError } */
function execFormatter(phpPath, args, file) {
    return new Promise(function (resolve) {
        let child;
        try {
            // 不使用 shell，参数以数组方式传递，跨平台安全
            child = cp.spawn(phpPath, [FMT_SCRIPT].concat(args, [file]), {
                windowsHide: true
            });
        } catch (e) {
            resolve({ ok: false, launchError: true, stderr: String((e && e.message) || e) });
            return;
        }

        let stdout = '';
        let stderr = '';
        let settled = false;

        function done(res) {
            if (!settled) {
                settled = true;
                resolve(res);
            }
        }

        if (child.stdout) {
            child.stdout.on('data', function (d) { stdout += d; });
        }
        if (child.stderr) {
            child.stderr.on('data', function (d) { stderr += d; });
        }
        child.on('error', function (err) {
            done({ ok: false, launchError: true, code: -1, stdout: stdout, stderr: stderr + String((err && err.message) || err) });
        });
        child.on('close', function (code) {
            let text = null;
            if (code === 0) {
                try {
                    text = fs.readFileSync(file, 'utf8');
                } catch (e) {
                    done({ ok: false, code: code, stdout: stdout, stderr: stderr + ' 读取格式化结果失败：' + String((e && e.message) || e) });
                    return;
                }
            }
            done({ ok: code === 0, code: code, text: text, stdout: stdout, stderr: stderr });
        });
    });
}

/** 全文格式化 */
async function formatDocument(document) {
    return runFmt(document, null);
}

/** 选区片段格式化（--fragment 模式，自动保留起始缩进） */
async function formatRange(document, range) {
    return runFmt(document, range);
}

/** 命令：格式化当前激活的 PHP 文件 */
async function formatActiveEditor() {
    const editor = vscode.window.activeTextEditor;
    if (!editor || editor.document.languageId !== PHP_LANGUAGE) {
        vscode.window.showWarningMessage('PHP格式化：请先打开一个 PHP 文件。');
        return;
    }
    try {
        const text = await formatDocument(editor.document);
        if (text === null) {
            return;
        }
        const edit = new vscode.WorkspaceEdit();
        const fullRange = new vscode.Range(
            editor.document.positionAt(0),
            editor.document.positionAt(editor.document.getText().length)
        );
        edit.replace(editor.document.uri, fullRange, text);
        await vscode.workspace.applyEdit(edit);
    } catch (e) {
        notifyError('格式化出错：' + String((e && e.message) || e));
    }
}

function activate(context) {
    output = vscode.window.createOutputChannel('PHP Fmt');
    context.subscriptions.push(output);

    const provider = {
        provideDocumentFormattingEdits: function (document) {
            return formatDocument(document).then(function (text) {
                if (text === null) {
                    return [];
                }
                return [vscode.TextEdit.replace(
                    new vscode.Range(
                        document.positionAt(0),
                        document.positionAt(document.getText().length)
                    ),
                    text
                )];
            });
        },
        provideDocumentRangeFormattingEdits: function (document, range) {
            return formatRange(document, range).then(function (text) {
                if (text === null) {
                    return [];
                }
                return [vscode.TextEdit.replace(range, text)];
            });
        }
    };

    // 注册格式化器（同时支持格式化文档与格式化选区）
    context.subscriptions.push(
        vscode.languages.registerDocumentFormattingEditProvider(PHP_LANGUAGE, provider)
    );
    context.subscriptions.push(
        vscode.languages.registerDocumentRangeFormattingEditProvider(PHP_LANGUAGE, provider)
    );

    // 命令：格式化当前文件
    context.subscriptions.push(
        vscode.commands.registerCommand('fmt.format', formatActiveEditor)
    );

    // 保存时自动格式化（fmt.formatOnSave，与 VS Code 自带 editor.formatOnSave 相互独立）
    saveDisposable = vscode.workspace.onWillSaveTextDocument(function (event) {
        if (event.document.languageId !== PHP_LANGUAGE) {
            return;
        }
        if (getConfig().get('formatOnSave', false) !== true) {
            return;
        }
        event.waitUntil(
            formatDocument(event.document).then(function (text) {
                if (text === null) {
                    return [];
                }
                const doc = event.document;
                return [vscode.TextEdit.replace(
                    new vscode.Range(
                        doc.positionAt(0),
                        doc.positionAt(doc.getText().length)
                    ),
                    text
                )];
            }).catch(function (e) {
                // 保存时格式化失败不打断保存流程，仅记录日志
                log('保存时自动格式化失败：' + String((e && e.message) || e));
                return [];
            })
        );
    });
    context.subscriptions.push(saveDisposable);

    // 文档关闭后兜底清理遗留的临时文件
    closeDisposable = vscode.workspace.onDidCloseTextDocument(function (document) {
        if (document.languageId !== PHP_LANGUAGE) {
            return;
        }
        tempFiles.forEach(function (file) {
            removeTempFile(file);
        });
    });
    context.subscriptions.push(closeDisposable);
}

function deactivate() {
    if (saveDisposable) {
        saveDisposable.dispose();
    }
    if (closeDisposable) {
        closeDisposable.dispose();
    }
    tempFiles.forEach(function (file) {
        try {
            fs.unlinkSync(file);
        } catch (e) {
            // 忽略清理失败
        }
    });
    tempFiles.clear();
}

module.exports = { activate: activate, deactivate: deactivate };
