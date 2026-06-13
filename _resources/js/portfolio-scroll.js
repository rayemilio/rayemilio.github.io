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

        document.querySelectorAll('.nav a[href], .mobile-nav a[href]').forEach(function (link) {
            const href = link.getAttribute("href");

            if (href === "capabilities/" || href === "about/" || href === "notes/") {
                link.addEventListener("click", storePortfolioScroll);
            }
        });

        document.querySelectorAll('a[href="../"]').forEach(function (link) {
            const label = link.textContent.toLowerCase();

            if (label.includes("portfolio")) {
                link.addEventListener("click", markPortfolioReturn);
            }
        });

        restorePortfolioScroll();
    }

    function initMobileNavLock() {
        const toggle = document.querySelector(".mobile-nav-toggle");
        const nav = document.querySelector(".mobile-nav");

        if (!toggle || !nav) {
            return;
        }

        let lockedScrollY = 0;

        function lockPage() {
            lockedScrollY = window.scrollY;
            document.documentElement.classList.add("mobile-nav-locked");
            document.body.classList.add("mobile-nav-locked");
            document.body.style.top = `-${lockedScrollY}px`;
        }

        function unlockPage() {
            document.documentElement.classList.remove("mobile-nav-locked");
            document.body.classList.remove("mobile-nav-locked");
            document.body.style.top = "";
            window.scrollTo(0, lockedScrollY);
        }

        toggle.addEventListener("change", function () {
            if (toggle.checked) {
                lockPage();
            } else {
                unlockPage();
            }
        });

        nav.querySelectorAll("a").forEach(function (link) {
            link.addEventListener("click", function () {
                if (!toggle.checked) {
                    return;
                }

                toggle.checked = false;
                unlockPage();
            });
        });

        const desktopQuery = window.matchMedia("(min-width: 1201px)");
        const unlockOnDesktop = function (event) {
            if (!event.matches || !toggle.checked) {
                return;
            }

            toggle.checked = false;
            unlockPage();
        };

        if (desktopQuery.addEventListener) {
            desktopQuery.addEventListener("change", unlockOnDesktop);
        } else {
            desktopQuery.addListener(unlockOnDesktop);
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            initPortfolioScroll();
            initMobileNavLock();
        });
    } else {
        initPortfolioScroll();
        initMobileNavLock();
    }
})();
