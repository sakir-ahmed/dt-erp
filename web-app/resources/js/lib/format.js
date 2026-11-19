function numberLocale(locale) {
    return locale === 'bn' ? 'bn-BD' : 'en-BD';
}

export function formatNumber(value, locale, options = {}) {
    return new Intl.NumberFormat(numberLocale(locale), options).format(value);
}

export function formatMoneyAmount(value, locale) {
    return formatNumber(value, locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatStock(milliunits, locale, signed = false) {
    return formatNumber(milliunits / 1000, locale, {
        maximumFractionDigits: 3,
        signDisplay: signed ? 'exceptZero' : 'auto',
    });
}

export function formatDhakaDateTime(timestamp, locale) {
    return new Intl.DateTimeFormat(numberLocale(locale), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Dhaka',
    }).format(new Date(timestamp));
}
