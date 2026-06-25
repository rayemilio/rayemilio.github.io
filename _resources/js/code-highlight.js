(function () {
    const patternsByLanguage = {
        css: [
            ["comment", /^\/\*[\s\S]*?\*\//],
            ["at-rule", /^@[a-zA-Z-]+/],
            ["property", /^--[a-zA-Z0-9-]+/],
            ["property", /^(width|min-width|margin|font-size|line-height|margin-top)(?=\s*:)/],
            ["function", /^(calc|clamp|var)(?=\()/],
            ["selector", /^(root|body)(?=\s*\{)/],
            ["keyword", /^(auto|inherit|only|screen)(?![a-zA-Z0-9-])/],
            ["number", /^\d+(?:\.\d+)?(?:px|vw|vh|rem|em|%)?/],
            ["operator", /^[+\-*/]/],
            ["punctuation", /^[{}():;,]/],
        ],
        shell: [
            ["comment", /^#[^\n]*/],
            ["string", /^"[^"\n]*"|^'[^'\n]*'/],
            ["command", /^(cd|open|python3|npm|pytest)(?![a-zA-Z0-9_-])/],
            ["flag", /^--?[a-zA-Z0-9-]+/],
            ["path", /^[.~\/]?[a-zA-Z0-9_./+@-]+/],
        ],
        typescript: [
            ["comment", /^\/\/[^\n]*/],
            ["comment", /^\/\*[\s\S]*?\*\//],
            ["string", /^`(?:\\[\s\S]|[^`\\])*`/],
            ["string", /^"[^"\\\n]*(?:\\.[^"\\\n]*)*"|^'[^'\\\n]*(?:\\.[^'\\\n]*)*'/],
            [
                "keyword",
                /^(async|await|break|case|class|const|continue|default|else|export|extends|false|for|from|function|if|import|interface|let|new|null|return|switch|true|type|undefined|var|while)(?![a-zA-Z0-9_$])/,
            ],
            ["property", /^(Array|Blob|Promise|Record|boolean|never|number|string|unknown|void)(?![a-zA-Z0-9_$])/],
            ["function", /^[a-zA-Z_$][a-zA-Z0-9_$]*(?=\()/],
            ["path", /^[A-Z][a-zA-Z0-9_$]*/],
            ["number", /^\d+(?:\.\d+)?/],
            ["operator", /^(=>|===|!==|==|!=|<=|>=|\?\?|\|\||&&|[=+\-*/%<>!?:|&.]+)/],
            ["punctuation", /^[{}()[\],;]/],
        ],
        tree: [
            ["tree", /^[├└│─]+/],
            ["path", /^[a-zA-Z0-9_./()[\]-]+/],
        ],
        diagram: [
            ["tree", /^[+|─\-→]+/],
            ["path", /^[a-zA-Z0-9_./()[\]-]+/],
        ],
        url: [
            ["function", /^https?:\/\//],
            ["path", /^[a-zA-Z0-9.-]+/],
            ["punctuation", /^[/#?=&]/],
            ["property", /^[a-zA-Z0-9_-]+/],
        ],
    };

    function escapeHtml(value) {
        return value.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");
    }

    function highlightToken(className, value) {
        return `<span class="code-token-${className}">${escapeHtml(value)}</span>`;
    }

    function highlightInvisibleCharacter(value) {
        if (value === " ") {
            return '<span class="code-invisible code-space">·</span>';
        }

        if (value === "\t") {
            return '<span class="code-invisible code-tab">→···</span>';
        }

        if (value === "\n") {
            return '<span class="code-invisible code-return">¬</span>\n';
        }

        return "";
    }

    function hasLineContentBefore(code, index) {
        const lineStart = code.lastIndexOf("\n", index - 1) + 1;

        return /\S/.test(code.slice(lineStart, index));
    }

    function highlight(code, language) {
        const patterns = patternsByLanguage[language] || [];
        let highlighted = "";
        let index = 0;

        while (index < code.length) {
            if ((language === "tree" || language === "diagram") && hasLineContentBefore(code, index)) {
                const description = code.slice(index).match(/^ {2,}([^\n]+)/);

                if (description) {
                    highlighted += description[0]
                        .slice(0, description[0].length - description[1].length)
                        .split("")
                        .map(highlightInvisibleCharacter)
                        .join("");
                    highlighted += highlightToken("comment", description[1]);
                    index += description[0].length;
                    continue;
                }
            }

            if (code[index] === " " || code[index] === "\t" || code[index] === "\n") {
                highlighted += highlightInvisibleCharacter(code[index]);
                index += 1;
                continue;
            }

            const remaining = code.slice(index);
            const match = patterns
                .map(([className, pattern]) => ({ className, match: remaining.match(pattern) }))
                .find((candidate) => candidate.match);

            if (match) {
                const value = match.match[0];

                highlighted += highlightToken(match.className, value);
                index += value.length;
            } else {
                highlighted += escapeHtml(code[index]);
                index += 1;
            }
        }

        return highlighted;
    }

    document.querySelectorAll(".code-block pre[data-code-language]").forEach((block) => {
        block.innerHTML = highlight(block.textContent, block.dataset.codeLanguage);
    });
})();
