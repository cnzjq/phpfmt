# PHP格式化

一款功能强大的 VS Code 插件，格式化PHP代码增强版，自适配PHP 7/8。。体积小、功能强、无依赖、零配置。

## 功能特性

- **PSR-12 规范化格式化**
  - 智能缩进，不使用制表符；
  - 类/接口/枚举/命名函数的花括号独占一行，控制结构与闭包花括号同行（`else`/`elseif`/`catch`/`finally` 与上一闭花括号同行）；
  - 关键字与 `true`/`false`/`null` 统一小写；
  - 自动排序修饰符（`abstract`/`final` → 可见性 → `readonly` → `static`）；
  - 连续的 `use` 导入自动按 class → function → const 分组排序，组间空行；
  - heredoc/nowdoc、字符串字面量、注释内容原样保留；
  - 输出统一 LF 行尾，纯 PHP 文件省略结尾关闭标签，文件以单个换行结束。
- **每行最大长度控制**：超过设定宽度时智能拆分参数列表、数组等（0 表示不限制）。
- **对齐连续赋值语句的等号（=）**（可配置开关）。
- **对齐连续代码行的行内注释（//）**（可配置开关）。
- **保存文件自动格式化**（可配置开关，独立于 VS Code 自带的 `editor.formatOnSave`）。
- **选区格式化**：支持对选中的 PHP 片段格式化（自动补齐/移除 `<?php` 标签并按选区缩进对齐）。
- 格式化前安全校验：格式化前后比对一致性校验，防止异常输出破坏源码。


## 配置说明

打开 VS Code 设置（`Ctrl + ,`）搜索 `fmt`，或在 `settings.json` 中配置：

| 配置项 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `fmt.phpPath` | string | `"php"` | 可执行 PHP 文件路径。默认 `"php"` 表示从系统 PATH 查找；也可填写完整路径，如 `C:\\php\\php.exe`、`/usr/bin/php`。 |
| `fmt.maxLineLength` | integer | `0` | 每行最大长度，超过时智能拆分；**0 表示不限制**。 |
| `fmt.alignEquals` | boolean | `false` | 是否对齐连续赋值语句的等号（=）。 |
| `fmt.alignComments` | boolean | `false` | 是否对齐连续代码行的行内注释（//）。 |
| `fmt.formatOnSave` | boolean | `false` | 保存 PHP 文件时是否自动格式化。 |

`settings.json` 示例：

```json
{
    "fmt.phpPath": "C:\\php\\php.exe",
    "fmt.maxLineLength": 120,
    "fmt.alignEquals": true,
    "fmt.alignComments": true,
    "fmt.formatOnSave": true
}
```

> 提示：启用 `fmt.formatOnSave` 后，建议关闭 VS Code 自带的 `"editor.formatOnSave": false`，避免保存时重复格式化。

## 使用方法

1. **格式化整个文件**：打开 PHP 文件，`Ctrl + Shift + P` 执行命令 `PHP Fmt: 格式化当前文件`（也可绑定快捷键到命令 `fmt.format`）。
2. **格式化选区**：选中代码片段，`Ctrl + Shift + P` 执行「Format Selection」（默认快捷键 `Ctrl + K Ctrl + F`）。
3. **保存自动格式化**：开启 `fmt.formatOnSave` 后保存文件即自动格式化。

## 问题排查

- 右下角提示「无法启动 PHP」→ 检查 `fmt.phpPath` 配置及 PHP 是否已加入 PATH。
- 输出面板（`Ctrl + Shift + U`）选择 **PHP Fmt** 可查看每次格式化的执行命令与错误详情。

## 联系方式

- 作者：**张俊强**
- 邮箱：[55357571@qq.com](mailto:55357571@qq.com)

## 开源协议

本项目基于 [MIT 协议](LICENSE.txt) 开源。

## 微信交流群

![微信群二维码](https://www.vipqiye.com/static/images/qun.png)