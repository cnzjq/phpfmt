const vscode = require('vscode');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

let outputChannel;

function getOutputChannel() {
    if (!outputChannel) {
        outputChannel = vscode.window.createOutputChannel('PHP 格式化器');
    }
    return outputChannel;
}

function getConfig() {
    return vscode.workspace.getConfiguration('phpfmt');
}

function getPhpfmtPath() {
    const extensionPath = __dirname;
    const candidates = [
        path.join(extensionPath, 'phpfmt.php'),
        path.join(extensionPath, '..', 'phpfmt.php'),
    ];
    for (const p of candidates) {
        if (fs.existsSync(p)) {
            return p;
        }
    }
    return null;
}

function formatDocument(document, range) {
    const config = getConfig();
    const enabled = config.get('enabled', true);

    if (!enabled) {
        return Promise.resolve([]);
    }

    const phpPath = config.get('phpPath', 'php');
    const indentSize = config.get('indentSize', 4);
    const alignAssignments = config.get('alignAssignments', false);
    const alignComments = config.get('alignComments', false);

    const phpfmtPath = getPhpfmtPath();

    if (!phpfmtPath) {
        vscode.window.showErrorMessage('找不到 phpfmt.php 文件，请确保插件安装完整。');
        return Promise.resolve([]);
    }

    return new Promise((resolve, reject) => {
        let text;
        let isSelection = false;

        if (range) {
            text = document.getText(range);
            isSelection = true;
        } else {
            text = document.getText();
        }

        const args = [
            phpfmtPath,
            '--stdin',
            '--indent=' + indentSize,
        ];

        if (alignAssignments) {
            args.push('--align-assignments');
        }

        if (alignComments) {
            args.push('--align-comments');
        }

        const options = {
            encoding: 'utf-8',
            maxBuffer: 10 * 1024 * 1024,
            env: { ...process.env, LANG: 'en_US.UTF-8' },
        };

        const phpProcess = spawn(phpPath, args, {
            ...options,
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        let stdout = '';
        let stderr = '';

        phpProcess.stdin.setDefaultEncoding('utf-8');
        phpProcess.stdin.write(text);
        phpProcess.stdin.end();

        phpProcess.stdout.on('data', (data) => {
            stdout += data.toString('utf-8');
        });

        phpProcess.stderr.on('data', (data) => {
            stderr += data.toString('utf-8');
        });

        phpProcess.on('error', (err) => {
            vscode.window.showErrorMessage('PHP 进程启动失败：' + err.message);
            resolve([]);
        });

        phpProcess.on('close', (code) => {
            if (code !== 0) {
                getOutputChannel().appendLine('PHP 格式化进程退出码：' + code);
                if (stderr) {
                    getOutputChannel().appendLine('错误信息：' + stderr);
                }
                vscode.window.showErrorMessage('PHP 格式化失败，请检查 PHP 路径配置是否正确。');
                resolve([]);
                return;
            }

            if (stderr) {
                getOutputChannel().appendLine('警告：' + stderr);
            }

            const formatted = stdout.replace(/\r?\n$/, '');
            const original = text.replace(/\r?\n$/, '');

            if (formatted === original) {
                resolve([]);
                return;
            }

            const fullRange = isSelection ? range : new vscode.Range(
                new vscode.Position(0, 0),
                new vscode.Position(document.lineCount - 1, document.lineAt(document.lineCount - 1).text.length)
            );

            resolve([vscode.TextEdit.replace(fullRange, formatted)]);
        });
    });
}

function activate(context) {
    getOutputChannel().appendLine('PHP 格式化器已激活');

    const formatter = vscode.languages.registerDocumentFormattingEditProvider('php', {
        provideDocumentFormattingEdits(document) {
            return formatDocument(document, null);
        }
    });

    const rangeFormatter = vscode.languages.registerDocumentRangeFormattingEditProvider('php', {
        provideDocumentRangeFormattingEdits(document, range) {
            return formatDocument(document, range);
        }
    });

    const disposable = vscode.commands.registerCommand('phpfmt.formatCurrentFile', async () => {
        const editor = vscode.window.activeTextEditor;
        if (!editor) {
            vscode.window.showInformationMessage('请先打开一个 PHP 文件。');
            return;
        }

        if (editor.document.languageId !== 'php') {
            vscode.window.showInformationMessage('当前文件不是 PHP 文件，无法格式化。');
            return;
        }

        const config = getConfig();
        if (!config.get('enabled', true)) {
            vscode.window.showInformationMessage('PHP 格式化功能已被禁用，请在设置中开启。');
            return;
        }

        await vscode.commands.executeCommand('editor.action.formatDocument');
        vscode.window.showInformationMessage('PHP 格式化完成。');
    });

    const saveListener = vscode.workspace.onWillSaveTextDocument((event) => {
        if (event.document.languageId === 'php') {
            const config = getConfig();
            if (config.get('formatOnSave', false)) {
                event.waitUntil(formatDocument(event.document, null));
            }
        }
    });

    context.subscriptions.push(formatter, rangeFormatter, disposable, saveListener);
}

function deactivate() {
    if (outputChannel) {
        outputChannel.dispose();
    }
}

module.exports = { activate, deactivate };
