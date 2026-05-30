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

    function highlight(code, language) {
        const patterns = patternsByLanguage[language] || [];
        let highlighted = "";
        let index = 0;

        while (index < code.length) {
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
