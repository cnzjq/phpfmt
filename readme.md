# php格式化 (phpfmt)

基于 phpfmt.php 的 VS Code PHP 代码格式化插件。

## 功能特点

- **一键格式化**：支持对整个 PHP 文件或选中代码片段进行格式化
- **智能缩进**：基于 Token 分析的自动缩进，支持大括号风格切换
- **等号对齐**：可选的对齐连续赋值语句中等号位置
- **注释对齐**：可选的对齐连续行末尾的 `//` 注释
- **中文界面**：所有配置项、提示信息均为中文

## 安装要求

- VS Code >= 1.70.0
- 系统中已安装 PHP（命令行中可执行 `php`）

## 使用方法

### 格式化整个文件

在 PHP 文件中按下快捷键，或右键选择 **"格式化文档"**：

- `Shift + Alt + F`（Windows/Linux）
- `Shift + Option + F`（macOS）

### 格式化选中代码

选中部分代码后：

- `Ctrl + K Ctrl + F`（Windows/Linux）
- `Cmd + K Cmd + F`（macOS）

### 保存时自动格式化

在 VS Code 设置中开启：

```json
"editor.formatOnSave": true
```

## 配置项

在 VS Code 设置中搜索 `phpfmt`，可调整以下配置：

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `phpfmt.enabled` | `boolean` | `true` | 是否启用 PHP 格式化功能 |
| `phpfmt.phpPath` | `string` | `php` | PHP 可执行文件的路径 |
| `phpfmt.indentSize` | `number` | `4` | 缩进空格数（1~8） |
| `phpfmt.alignAssignments` | `boolean` | `false` | 是否对齐等号（`=`） |
| `phpfmt.alignComments` | `boolean` | `false` | 是否对齐注释（`//`） |

### 配置示例

在 `.vscode/settings.json` 中添加：

```json
{
  "phpfmt.enabled": true,
  "phpfmt.phpPath": "C:\\php\\php.exe",
  "phpfmt.indentSize": 4,
  "phpfmt.alignAssignments": true,
  "phpfmt.alignComments": false
}
```

## 格式化效果示例

### 对齐等号

格式化前：

```php
<?php
$a = 1;
$bb = 2;
$ccc = 3;
```

格式化后（开启 `alignAssignments`）：

```php
<?php
$a   = 1;
$bb  = 2;
$ccc = 3;
```

### 对齐注释

格式化前：

```php
<?php
$a = 1; // comment 1
$bb = 2; // comment 2
$ccc = 3; // comment 3
```

格式化后（开启 `alignComments`）：

```php
<?php
$a = 1;   // comment 1
$bb = 2;  // comment 2
$ccc = 3; // comment 3
```

## 常见问题

### 格式化无反应

1. 检查 `phpfmt.enabled` 是否设置为 `true`
2. 检查 PHP 路径是否正确，在终端中运行 `php -v` 确认 PHP 可用
3. 检查 VS Code 输出面板中的 **"PHP 格式化器"** 通道，查看详细错误信息

### 找不到 PHP

如果系统 PATH 中未包含 PHP，请在设置中手动指定：

```json
"phpfmt.phpPath": "C:\\php\\php.exe"
```

## 许可证

MIT
