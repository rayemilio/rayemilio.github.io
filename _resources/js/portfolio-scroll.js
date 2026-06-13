(function () {
    const scrollKey = "oopPortfolioScrollY";
    const returnKey = "oopReturnToPortfolio";
    const restoringClass = "portfolio-scroll-restoring";
    const shouldRestore = sessionStorage.getItem(returnKey) === "1" && sessionStorage.getItem(scrollKey) !== null;

    if (shouldRestore) {
        const restoreStyle = document.createElement("style");
        restoreStyle.textContent = `html.${restoringClass} body { visibility: hidden; }`;
        document.head.appendChild(restoreStyle);
        document.documentElement.classList.add(restoringClass);
    }

    function isPlainLeftClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function storePortfolioScroll(event) {
        if (!isPlainLeftClick(event)) {
            return;
        }

        sessionStorage.setItem(scrollKey, String(Math.round(window.scrollY)));
    }

    function markPortfolioReturn(event) {
        if (!isPlainLeftClick(event)) {
            return;
        }

        sessionStorage.setItem(returnKey, "1");
    }

    function restorePortfolioScroll() {
        if (!shouldRestore) {
            return;
        }

        const storedScrollY = Number(sessionStorage.getItem(scrollKey));
        sessionStorage.removeItem(returnKey);
        sessionStorage.removeItem(scrollKey);

        if (!Number.isFinite(storedScrollY)) {
            document.documentElement.classList.remove(restoringClass);
            return;
        }

        if ("scrollRestoration" in history) {
            history.scrollRestoration = "manual";
        }

        window.scrollTo(0, storedScrollY);

        requestAnimationFrame(function () {
            document.documentElement.classList.remove(restoringClass);
        });
    }

    function initPortfolioScroll() {
        document.querySelectorAll(".proj-title a[href]").forEach(function (link) {
            link.addEventListener("click", storePortfolioScroll);
        });

        document.querySelectorAll('a[href="../"]').forEach(function (link) {
            const label = link.textContent.toLowerCase();

            if (label.includes("portfolio")) {
                link.addEventListener("click", markPortfolioReturn);
            }
        });

        restorePortfolioScroll();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPortfolioScroll);
    } else {
        initPortfolioScroll();
    }
})();
