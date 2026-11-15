// Translations in the browser. The server prints the current language's texts into
// <script id="i18n-data">; this file exposes them as window.OrbitI18n for every script
// (classic and module). Missing keys fall back to English, then to the key itself.
(function () {
    const element = document.getElementById('i18n-data');
    const data = element ? JSON.parse(element.textContent) : { lang: 'en', dir: 'ltr', strings: {} };

    function t(key, params = {}) {
        let text = data.strings[key] ?? key;
        Object.entries(params).forEach(([name, value]) => {
            text = text.split(`{${name}}`).join(String(value));
        });
        return text;
    }

    window.OrbitI18n = {
        lang: data.lang,
        dir: data.dir,
        // Arabic month/day names with Western digits, so numbers read the same in both languages.
        locale: data.lang === 'ar' ? 'ar-u-nu-latn' : 'en-US',
        t,
        unit: (unit) => t(`unit.${unit}`),
    };
})();
