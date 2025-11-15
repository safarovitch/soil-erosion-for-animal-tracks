import { ref, shallowRef } from "vue";

const resolveTranslation = (dictionary, key) => {
    if (!dictionary || typeof dictionary !== "object") {
        return undefined;
    }

    if (Object.prototype.hasOwnProperty.call(dictionary, key)) {
        return dictionary[key];
    }

    return key.split(".").reduce((acc, part) => acc?.[part], dictionary);
};

export default {
    install(app, { translations = {}, locale } = {}) {
        const localeRef = ref(locale || "en");
        const translationsRef = shallowRef(translations || {});

        const setTranslations = (nextTranslations = {}) => {
            if (nextTranslations && typeof nextTranslations === "object") {
                translationsRef.value = nextTranslations;
            } else {
                translationsRef.value = {};
            }
        };

        const setLocale = (nextLocale, nextTranslations) => {
            if (typeof nextLocale === "string" && nextLocale.length > 0) {
                localeRef.value = nextLocale;
            }

            if (typeof nextTranslations !== "undefined") {
                setTranslations(nextTranslations);
            }
        };

        const __ = (key, replace = {}) => {
            if (!key || typeof key !== "string") {
                return key ?? "";
            }

            const dictionary = translationsRef.value || {};
            let value = resolveTranslation(dictionary, key);
            const finalReplace =
                replace && typeof replace === "object" ? replace : {};

            if (typeof value === "string") {
                Object.keys(finalReplace).forEach((r) => {
                    value = value.replace(`:${r}`, finalReplace[r]);
                });

                return value;
            }

            return key;
        };

        const i18n = {
            get locale() {
                return localeRef.value;
            },
            get translations() {
                return translationsRef.value;
            },
            t: __,
            __,
            setLocale,
            setTranslations,
        };

        app.config.globalProperties.__ = __;
        app.config.globalProperties.$__ = __;
        app.config.globalProperties.$i18n = i18n;
        app.provide("__", __);
        app.provide("i18n", i18n);

        if (typeof globalThis !== "undefined") {
            globalThis.__ = __;
            globalThis.$i18n = i18n;
        }
    },
};