// Same validation rules as legacy booking-calendar.js's fieldRules/validateField.
export function makeFieldRules(i18n) {
    return {
        name: {
            required: true,
            test: (v) => v.trim().length >= 3 && v.trim().indexOf(' ') !== -1,
            error: i18n.errorName || 'Introduceți numele și prenumele',
        },
        email: {
            required: false,
            test: (v) => !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
            error: i18n.errorEmail || 'Adresa de email nu este validă',
        },
        phone: {
            required: true,
            test: (v) => /^[+]?[0-9\s\-()]{6,20}$/.test(v.trim()),
            error: i18n.errorPhone || 'Introduceți un număr de telefon valid',
        },
    };
}
