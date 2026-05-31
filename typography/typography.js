(function () {
    const intervals = [
        { name: "Minor second", ratio: "16/15", value: 16 / 15 },
        { name: "Major second", ratio: "9/8", value: 9 / 8 },
        { name: "Minor third", ratio: "32/27", value: 32 / 27 },
        { name: "Major third", ratio: "5/4", value: 5 / 4 },
        { name: "Perfect fourth", ratio: "4/3", value: 4 / 3 },
        { name: "Tritone", ratio: "45/32", value: 45 / 32 },
        { name: "Perfect fifth", ratio: "3/2", value: 3 / 2 },
        { name: "Minor sixth", ratio: "8/5", value: 8 / 5 },
        { name: "Major sixth", ratio: "5/3", value: 5 / 3 },
        { name: "Octave", ratio: "2/1", value: 2 },
    ];

    const defaults = {
        mode: "linked",
        intervalIndex: 2,
        rootPx: 9.8,
        bodyNote: 3,
        roles: {
            small: 2,
            code: 3,
            body: 3,
            subtitle: 4,
            h3: 2,
            h2: 7,
            h1: 10,
        },
        lines: {
            small: 4,
            code: 5,
            body: 6,
            subtitle: 7,
            h3: 4,
            h2: 8,
            h1: 11,
        },
        spacing: {
            pageTop: 13,
            pageBottom: 14,
            paragraph: 4,
            section: 11,
            rule: 10,
            code: 4,
        },
    };

    const linkedRoleOffsets = {
        small: -1,
        code: 0,
        body: 0,
        subtitle: 1,
        h3: -1,
        h2: 4,
        h1: 7,
    };

    const linkedLineOffsets = {
        small: 1,
        code: 2,
        body: 3,
        subtitle: 4,
        h3: 1,
        h2: 5,
        h1: 8,
    };

    const linkedSpaceOffsets = {
        pageTop: 10,
        pageBottom: 11,
        paragraph: 1,
        section: 8,
        rule: 7,
        code: 1,
        tight: 0,
    };

    const roleConfig = {
        small: "small",
        code: "code",
        body: "body",
        subtitle: "subtitle",
        h3: "tertiary",
        h2: "section",
        h1: "title",
    };

    const readoutLabels = {
        small: "Small",
        code: "Code",
        body: "Body",
        subtitle: "Subtitle",
        h3: "H3",
        h2: "H2",
        h1: "H1",
    };

    const page = document.querySelector(".typography-page");
    const intervalControl = document.querySelector("#interval-control");
    const rootControl = document.querySelector("#root-control");
    const linkedBodyControl = document.querySelector("[data-linked-body-control]");
    const intervalOutput = document.querySelector("[data-interval-output]");
    const rootOutput = document.querySelector("[data-root-output]");
    const linkedBodyOutput = document.querySelector("[data-linked-body-output]");
    const reset = document.querySelector("[data-type-reset]");
    const controlsToggle = document.querySelector("[data-controls-toggle]");
    const controlsToggleLabel = document.querySelector("[data-controls-toggle-label]");
    const modeControls = Array.from(document.querySelectorAll("[data-mode-control]"));
    const roleControls = Array.from(document.querySelectorAll("[data-role-control]"));
    const roleOutputs = Array.from(document.querySelectorAll("[data-role-output]"));
    const lineControls = Array.from(document.querySelectorAll("[data-line-control]"));
    const lineOutputs = Array.from(document.querySelectorAll("[data-line-output]"));
    const spaceControls = Array.from(document.querySelectorAll("[data-space-control]"));
    const spaceOutputs = Array.from(document.querySelectorAll("[data-space-output]"));
    const linkedReadouts = Array.from(document.querySelectorAll("[data-linked-readout]"));

    const spaceConfig = {
        pageTop: "--space-page",
        pageBottom: "--space-page-end",
        paragraph: "--space-paragraph",
        section: "--space-section",
        rule: "--space-rule",
        code: "--space-code",
    };

    if (!page || !intervalControl || !rootControl || !linkedBodyControl || !intervalOutput || !rootOutput || !linkedBodyOutput) {
        return;
    }

    const formatPx = (value) => `${value.toFixed(2)}px`;
    const formatNote = (noteIndex) => `note${String(noteIndex).padStart(2, "0")}`;

    function setControlsCollapsed(isCollapsed) {
        page.dataset.controlsCollapsed = isCollapsed ? "true" : "false";

        if (controlsToggle) {
            controlsToggle.setAttribute("aria-expanded", (!isCollapsed).toString());
        }

        if (controlsToggleLabel) {
            controlsToggleLabel.textContent = isCollapsed ? "+" : "-";
        }
    }

    setControlsCollapsed(true);

    function getMode() {
        return modeControls.find((control) => control.checked)?.value || defaults.mode;
    }

    function setMode(mode) {
        modeControls.forEach((control) => {
            control.checked = control.value === mode;
        });
    }

    function clamp(min, value, max) {
        return Math.min(Math.max(value, min), max);
    }

    function clampToControl(control, value) {
        return clamp(Number(control.min), value, Number(control.max));
    }

    function findControl(controls, key, dataKey) {
        return controls.find((candidate) => candidate.dataset[dataKey] === key);
    }

    function setControlValue(controls, key, value, dataKey) {
        const control = findControl(controls, key, dataKey);

        if (!control) {
            return;
        }

        control.value = clampToControl(control, value).toString();
    }

    function calculateNotes(rootPx, ratio) {
        const fontSizeGrow = (window.innerWidth / 100) * 0.55 * (rootPx / defaults.rootPx);
        const minFontSize = rootPx / ratio;
        const notes = [Math.max(minFontSize, fontSizeGrow)];

        for (let index = 1; index <= 16; index += 1) {
            notes[index] = notes[index - 1] * ratio;
        }

        return notes;
    }

    function nearestNoteIndex(notes, value) {
        return notes.reduce((nearest, noteValue, index) => {
            const nearestDistance = Math.abs(notes[nearest] - value);
            const distance = Math.abs(noteValue - value);

            return distance < nearestDistance ? index : nearest;
        }, 0);
    }

    function setRoleSize(roleName, noteIndex) {
        const cssName = roleConfig[roleName];

        if (cssName) {
            page.style.setProperty(`--type-${cssName}`, `var(--${formatNote(noteIndex)})`);
        }
    }

    function setLineNote(roleName, noteIndex) {
        const cssName = roleConfig[roleName];

        if (!cssName) {
            return;
        }

        page.style.setProperty(`--type-${cssName}-line`, `var(--${formatNote(noteIndex)})`);
    }

    function setSpaceNote(name, noteIndex) {
        const cssVariables = [].concat(spaceConfig[name] || []);

        cssVariables.forEach((cssVariable) => {
            page.style.setProperty(cssVariable, `var(--${formatNote(noteIndex)})`);
        });
    }

    function getLinkedState(notes) {
        const bodyNote = Number(linkedBodyControl.value);
        const rhythmNote = clamp(0, bodyNote + linkedLineOffsets.body, 16);
        const rhythmPx = notes[rhythmNote];
        const roles = Object.fromEntries(
            Object.entries(linkedRoleOffsets).map(([roleName, offset]) => [
                roleName,
                clamp(0, bodyNote + offset, 16),
            ]),
        );
        const lineNotes = Object.fromEntries(
            Object.entries(linkedLineOffsets).map(([roleName, offset]) => [
                roleName,
                clamp(0, bodyNote + offset, 16),
            ]),
        );
        const spaceNotes = Object.fromEntries(
            Object.entries(linkedSpaceOffsets).map(([spaceName, offset]) => [
                spaceName,
                clamp(0, bodyNote + offset, 16),
            ]),
        );

        return { bodyNote, rhythmNote, rhythmPx, roles, lineNotes, spaceNotes };
    }

    function syncFreeControlsFromLinked(state, notes) {
        Object.entries(state.roles).forEach(([roleName, noteIndex]) => {
            setControlValue(roleControls, roleName, noteIndex, "roleControl");
        });

        Object.entries(state.lineNotes).forEach(([roleName, noteIndex]) => {
            setControlValue(lineControls, roleName, noteIndex, "lineControl");
        });

        Object.entries(state.spaceNotes).forEach(([spaceName, noteIndex]) => {
            if (spaceName !== "tight") {
                setControlValue(spaceControls, spaceName, noteIndex, "spaceControl");
            }
        });
    }

    function syncLinkedControlsFromFree() {
        const bodyControl = findControl(roleControls, "body", "roleControl");

        if (bodyControl) {
            linkedBodyControl.value = clampToControl(linkedBodyControl, Number(bodyControl.value)).toString();
        }
    }

    function updateCoreOutputs(notes, linkedState) {
        const intervalIndex = Number(intervalControl.value);
        const interval = intervals[intervalIndex] || intervals[defaults.intervalIndex];
        const rootPx = Number(rootControl.value);

        intervalOutput.textContent = `${interval.name} · ${interval.ratio}`;
        rootOutput.textContent = `${rootPx.toFixed(rootPx % 1 === 0 ? 0 : 1)}px`;
        intervalControl.setAttribute("aria-valuetext", `${interval.name}, ${interval.ratio}`);
        rootControl.setAttribute("aria-valuetext", `${rootOutput.textContent} root anchor`);

        if (linkedState) {
            linkedBodyOutput.textContent = `${formatNote(linkedState.bodyNote)} · ${formatPx(notes[linkedState.bodyNote])}`;
            linkedBodyControl.setAttribute(
                "aria-valuetext",
                `${formatNote(linkedState.bodyNote)}, ${formatPx(notes[linkedState.bodyNote])}`,
            );
        }
    }

    function updateFreeOutputs(notes) {
        roleOutputs.forEach((output) => {
            const roleName = output.dataset.roleOutput;
            const control = findControl(roleControls, roleName, "roleControl");

            if (!control) {
                return;
            }

            const noteIndex = Number(control.value);
            output.textContent = `${formatNote(noteIndex)} · ${formatPx(notes[noteIndex])}`;
        });

        lineOutputs.forEach((output) => {
            const roleName = output.dataset.lineOutput;
            const control = findControl(lineControls, roleName, "lineControl");

            if (!control) {
                return;
            }

            const noteIndex = Number(control.value);
            output.textContent = `${formatNote(noteIndex)} · ${formatPx(notes[noteIndex])}`;
        });

        spaceOutputs.forEach((output) => {
            const spaceName = output.dataset.spaceOutput;
            const control = findControl(spaceControls, spaceName, "spaceControl");

            if (!control) {
                return;
            }

            const noteIndex = Number(control.value);
            output.textContent = `${formatNote(noteIndex)} · ${formatPx(notes[noteIndex])}`;
        });
    }

    function updateLinkedReadouts(state) {
        linkedReadouts.forEach((readout) => {
            const key = readout.dataset.linkedReadout;

            if (key === "rhythm") {
                readout.textContent = `Body leading ${formatNote(state.rhythmNote)}`;
            } else if (key === "paragraph") {
                readout.textContent = `Paragraph ${formatNote(state.spaceNotes.paragraph)}`;
            } else if (key === "section") {
                readout.textContent = `Section ${formatNote(state.spaceNotes.section)}`;
            } else if (key === "page") {
                readout.textContent = `Page top ${formatNote(state.spaceNotes.pageTop)}`;
            } else if (key === "pageBottom") {
                readout.textContent = `Page bottom ${formatNote(state.spaceNotes.pageBottom)}`;
            } else if (state.roles[key] !== undefined) {
                const suffix = ` / leading ${formatNote(state.lineNotes[key])}`;
                readout.textContent = `${readoutLabels[key]} ${formatNote(state.roles[key])}${suffix}`;
            }
        });
    }

    function applyLinked(notes) {
        const state = getLinkedState(notes);

        page.style.setProperty("--rhythm", `var(--${formatNote(state.rhythmNote)})`);

        Object.entries(state.roles).forEach(([roleName, noteIndex]) => {
            setRoleSize(roleName, noteIndex);
        });

        Object.entries(state.lineNotes).forEach(([roleName, noteIndex]) => {
            setLineNote(roleName, noteIndex);
        });

        setSpaceNote("paragraph", state.spaceNotes.paragraph);
        setSpaceNote("section", state.spaceNotes.section);
        setSpaceNote("rule", state.spaceNotes.rule);
        setSpaceNote("pageTop", state.spaceNotes.pageTop);
        setSpaceNote("pageBottom", state.spaceNotes.pageBottom);
        setSpaceNote("code", state.spaceNotes.code);

        page.style.setProperty("--space-tight", `var(--${formatNote(state.spaceNotes.tight)})`);
        page.style.setProperty("--space-meta", "var(--space-paragraph)");
        page.style.setProperty("--space-project", "var(--space-paragraph)");
        page.style.setProperty("--space-subsection", "var(--space-section)");

        syncFreeControlsFromLinked(state, notes);
        updateLinkedReadouts(state);
        updateCoreOutputs(notes, state);
        updateFreeOutputs(notes);
    }

    function applyFree(notes) {
        roleControls.forEach((control) => {
            const roleName = control.dataset.roleControl;
            const noteIndex = Number(control.value);

            setRoleSize(roleName, noteIndex);
            control.setAttribute("aria-valuetext", `${formatNote(noteIndex)}, ${formatPx(notes[noteIndex])}`);
        });

        lineControls.forEach((control) => {
            const roleName = control.dataset.lineControl;
            const cssName = roleConfig[roleName];
            const noteIndex = Number(control.value);

            if (!cssName) {
                return;
            }

            page.style.setProperty(`--type-${cssName}-line`, `var(--${formatNote(noteIndex)})`);
            control.setAttribute("aria-valuetext", `${formatNote(noteIndex)}, ${formatPx(notes[noteIndex])}`);
        });

        spaceControls.forEach((control) => {
            const spaceName = control.dataset.spaceControl;
            const cssVariables = [].concat(spaceConfig[spaceName] || []);
            const noteIndex = Number(control.value);

            cssVariables.forEach((cssVariable) => {
                page.style.setProperty(cssVariable, `var(--${formatNote(noteIndex)})`);
            });
            control.setAttribute("aria-valuetext", `${formatNote(noteIndex)}, ${formatPx(notes[noteIndex])}`);
        });

        const bodyLineControl = findControl(lineControls, "body", "lineControl");
        const rhythmNote = Number(bodyLineControl?.value || defaults.lines.body);

        page.style.setProperty("--rhythm", `var(--${formatNote(rhythmNote)})`);
        updateCoreOutputs(notes);
        updateFreeOutputs(notes);
    }

    function update() {
        const mode = getMode();
        const intervalIndex = Number(intervalControl.value);
        const rootPx = Number(rootControl.value);
        const interval = intervals[intervalIndex] || intervals[defaults.intervalIndex];
        const notes = calculateNotes(rootPx, interval.value);

        page.dataset.typeMode = mode;
        page.style.setProperty("--fontRatio", interval.value.toString());
        page.style.setProperty("--rootPx", rootPx.toString());

        if (mode === "linked") {
            applyLinked(notes);
        } else {
            applyFree(notes);
        }
    }

    function restoreDefaults() {
        setMode(defaults.mode);
        intervalControl.value = defaults.intervalIndex.toString();
        rootControl.value = defaults.rootPx.toString();
        linkedBodyControl.value = defaults.bodyNote.toString();

        roleControls.forEach((control) => {
            const roleName = control.dataset.roleControl;
            const defaultNote = defaults.roles[roleName];

            if (typeof defaultNote === "number") {
                control.value = defaultNote.toString();
            }
        });

        lineControls.forEach((control) => {
            const roleName = control.dataset.lineControl;
            const defaultNote = defaults.lines[roleName];

            if (typeof defaultNote === "number") {
                control.value = defaultNote.toString();
            }
        });

        spaceControls.forEach((control) => {
            const spaceName = control.dataset.spaceControl;
            const defaultNote = defaults.spacing[spaceName];

            if (typeof defaultNote === "number") {
                control.value = defaultNote.toString();
            }
        });

        update();
    }

    intervalControl.addEventListener("input", update);
    rootControl.addEventListener("input", update);
    linkedBodyControl.addEventListener("input", update);
    modeControls.forEach((control) =>
        control.addEventListener("change", () => {
            if (control.checked && control.value === "linked") {
                syncLinkedControlsFromFree();
            }

            update();
        }),
    );
    roleControls.forEach((control) => control.addEventListener("input", update));
    lineControls.forEach((control) => control.addEventListener("input", update));
    spaceControls.forEach((control) => control.addEventListener("input", update));
    window.addEventListener("resize", update);

    if (reset) {
        reset.addEventListener("click", restoreDefaults);
    }

    if (controlsToggle) {
        controlsToggle.addEventListener("click", () => {
            setControlsCollapsed(page.dataset.controlsCollapsed !== "true");
        });
    }

    update();
})();
