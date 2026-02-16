(function() {
    function checkFontAwesome() {
        var faIcons = document.querySelectorAll(".searchlens-trending-fa-icon");
        var svgIcons = document.querySelectorAll(".searchlens-trending-svg-icon");

        // Check if Font Awesome is loaded by testing computed styles
        var testIcon = document.createElement("i");
        testIcon.className = "fa-solid fa-magnifying-glass";
        testIcon.style.position = "absolute";
        testIcon.style.left = "-9999px";
        document.body.appendChild(testIcon);

        var computedFont = window.getComputedStyle(testIcon).fontFamily;
        var hasFontAwesome = computedFont.toLowerCase().indexOf("font awesome") !== -1 ||
            computedFont.toLowerCase().indexOf("fontawesome") !== -1;
        document.body.removeChild(testIcon);

        if (hasFontAwesome) {
            faIcons.forEach(function(icon) { icon.style.display = "inline-block"; });
            svgIcons.forEach(function(icon) { icon.style.display = "none"; });
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", checkFontAwesome);
    } else {
        checkFontAwesome();
    }
    // Also check after a short delay for async-loaded Font Awesome
    setTimeout(checkFontAwesome, 500);
})();
