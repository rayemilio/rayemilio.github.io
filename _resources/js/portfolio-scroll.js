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

        document.querySelectorAll('a[href="../"], a[href="../#work"]').forEach(function (link) {
            const label = link.textContent.toLowerCase();
            const href = link.getAttribute("href");

            if (label.includes("portfolio") || label.includes("work") || href === "../") {
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

        function isInternalPageNavigation(link) {
            const url = new URL(link.href, window.location.href);

            return url.origin === window.location.origin && url.pathname !== window.location.pathname;
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

                nav.querySelectorAll(".active").forEach(function (item) {
                    item.classList.remove("active");
                    item.removeAttribute("aria-current");
                });
                link.classList.add("active");
                link.setAttribute("aria-current", "page");

                if (isInternalPageNavigation(link)) {
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

    function initBackLinkFade() {
        if (document.body.classList.contains("nav-page")) {
            return;
        }

        const backLink = document.querySelector(".back");

        if (!backLink) {
            return;
        }

        const startColor = [107, 107, 107];
        const endColor = [208, 208, 208];
        const fadeDistance = 300;
        let frameRequested = false;

        function setBackLinkColor() {
            const progress = Math.min(Math.max(window.scrollY / fadeDistance, 0), 1);
            const color = startColor.map(function (channel, index) {
                return Math.round(channel + (endColor[index] - channel) * progress);
            });

            document.body.style.setProperty("--back-link-color", `rgb(${color.join(", ")})`);
            frameRequested = false;
        }

        function updateBackLinkState() {
            if (frameRequested) {
                return;
            }

            frameRequested = true;
            requestAnimationFrame(setBackLinkColor);
        }

        updateBackLinkState();
        window.addEventListener("scroll", updateBackLinkState, { passive: true });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            initPortfolioScroll();
            initMobileNavLock();
            initBackLinkFade();
        });
    } else {
        initPortfolioScroll();
        initMobileNavLock();
        initBackLinkFade();
    }
})();
