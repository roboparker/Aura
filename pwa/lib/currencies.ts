/**
 * Static ISO-4217 currency list for the money custom-field editor. The
 * API validates currency codes through Symfony's Intl component and pins
 * amounts to minor units; the PWA derives the number of decimal places
 * from the currency rather than storing it (see kind-editors money note).
 *
 * `fractionDigits` mirrors the CLDR defaults Intl.NumberFormat uses, so
 * the editor's preview and the server agree on precision without a round
 * trip. Codes not in this list still validate server-side — the picker is
 * a convenience, not the source of truth.
 */
export interface CurrencyInfo {
  code: string;
  name: string;
  fractionDigits: number;
}

export const CURRENCIES: readonly CurrencyInfo[] = [
  { code: "USD", name: "US Dollar", fractionDigits: 2 },
  { code: "EUR", name: "Euro", fractionDigits: 2 },
  { code: "GBP", name: "British Pound", fractionDigits: 2 },
  { code: "JPY", name: "Japanese Yen", fractionDigits: 0 },
  { code: "CHF", name: "Swiss Franc", fractionDigits: 2 },
  { code: "CAD", name: "Canadian Dollar", fractionDigits: 2 },
  { code: "AUD", name: "Australian Dollar", fractionDigits: 2 },
  { code: "NZD", name: "New Zealand Dollar", fractionDigits: 2 },
  { code: "CNY", name: "Chinese Yuan", fractionDigits: 2 },
  { code: "HKD", name: "Hong Kong Dollar", fractionDigits: 2 },
  { code: "SGD", name: "Singapore Dollar", fractionDigits: 2 },
  { code: "INR", name: "Indian Rupee", fractionDigits: 2 },
  { code: "SEK", name: "Swedish Krona", fractionDigits: 2 },
  { code: "NOK", name: "Norwegian Krone", fractionDigits: 2 },
  { code: "DKK", name: "Danish Krone", fractionDigits: 2 },
  { code: "PLN", name: "Polish Złoty", fractionDigits: 2 },
  { code: "CZK", name: "Czech Koruna", fractionDigits: 2 },
  { code: "HUF", name: "Hungarian Forint", fractionDigits: 2 },
  { code: "RON", name: "Romanian Leu", fractionDigits: 2 },
  { code: "BRL", name: "Brazilian Real", fractionDigits: 2 },
  { code: "MXN", name: "Mexican Peso", fractionDigits: 2 },
  { code: "ARS", name: "Argentine Peso", fractionDigits: 2 },
  { code: "CLP", name: "Chilean Peso", fractionDigits: 0 },
  { code: "COP", name: "Colombian Peso", fractionDigits: 2 },
  { code: "ZAR", name: "South African Rand", fractionDigits: 2 },
  { code: "NGN", name: "Nigerian Naira", fractionDigits: 2 },
  { code: "KES", name: "Kenyan Shilling", fractionDigits: 2 },
  { code: "EGP", name: "Egyptian Pound", fractionDigits: 2 },
  { code: "AED", name: "UAE Dirham", fractionDigits: 2 },
  { code: "SAR", name: "Saudi Riyal", fractionDigits: 2 },
  { code: "ILS", name: "Israeli New Shekel", fractionDigits: 2 },
  { code: "TRY", name: "Turkish Lira", fractionDigits: 2 },
  { code: "RUB", name: "Russian Ruble", fractionDigits: 2 },
  { code: "UAH", name: "Ukrainian Hryvnia", fractionDigits: 2 },
  { code: "KRW", name: "South Korean Won", fractionDigits: 0 },
  { code: "TWD", name: "New Taiwan Dollar", fractionDigits: 2 },
  { code: "THB", name: "Thai Baht", fractionDigits: 2 },
  { code: "IDR", name: "Indonesian Rupiah", fractionDigits: 2 },
  { code: "MYR", name: "Malaysian Ringgit", fractionDigits: 2 },
  { code: "PHP", name: "Philippine Peso", fractionDigits: 2 },
  { code: "VND", name: "Vietnamese Đồng", fractionDigits: 0 },
  { code: "KWD", name: "Kuwaiti Dinar", fractionDigits: 3 },
  { code: "BHD", name: "Bahraini Dinar", fractionDigits: 3 },
  { code: "OMR", name: "Omani Rial", fractionDigits: 3 },
];

const BY_CODE = new Map(CURRENCIES.map((c) => [c.code, c]));

export const findCurrency = (code: string): CurrencyInfo | undefined =>
  BY_CODE.get(code.toUpperCase());

/**
 * Fraction digits for a currency code. Falls back to Intl (and then 2)
 * for codes outside the curated list so previews stay sensible.
 */
export const currencyFractionDigits = (code: string): number => {
  const known = findCurrency(code);
  if (known) return known.fractionDigits;
  try {
    const fmt = new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: code,
    });
    const digits = fmt.resolvedOptions().maximumFractionDigits;
    return typeof digits === "number" ? digits : 2;
  } catch {
    return 2;
  }
};
