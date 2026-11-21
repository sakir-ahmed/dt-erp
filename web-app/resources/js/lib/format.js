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

export function formatBytes(bytes, locale) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '';
    const unit = bytes >= 1_048_576 ? 'megabyte' : bytes >= 1024 ? 'kilobyte' : 'byte';
    return new Intl.NumberFormat(numberLocale(locale), {
        style: 'unit',
        unit,
        maximumFractionDigits: 1,
    }).format(bytes >= 1_048_576 ? bytes / 1_048_576 : bytes >= 1024 ? bytes / 1024 : bytes);
}
