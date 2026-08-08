export function digitsOnly(raw: string): string {
  return raw.replace(/\D/g, "");
}

export function formatCardNumber(raw: string): string {
  const digits = digitsOnly(raw).slice(0, 19);
  return digits.replace(/(\d{4})(?=\d)/g, "$1 ");
}

export function formatExpiry(raw: string): string {
  const digits = digitsOnly(raw).slice(0, 4);
  if (digits.length <= 2) {
    return digits;
  }
  return `${digits.slice(0, 2)}/${digits.slice(2)}`;
}

export function luhnValid(raw: string): boolean {
  const digits = digitsOnly(raw);
  if (digits.length < 13 || digits.length > 19) {
    return false;
  }

  let sum = 0;
  let double = false;
  for (let i = digits.length - 1; i >= 0; i--) {
    let n = Number(digits[i]);
    if (double) {
      n *= 2;
      if (n > 9) {
        n -= 9;
      }
    }
    sum += n;
    double = !double;
  }

  return sum % 10 === 0;
}

/**
 * Validates a card expiry parsed from MM/YY: month 1-12 and the last day of
 * that month is not before today (a card valid through end-of-month is OK).
 */
export function expiryIsValid(month: number, year: number, now: Date = new Date()): boolean {
  if (!Number.isInteger(month) || !Number.isInteger(year) || month < 1 || month > 12) {
    return false;
  }

  const fullYear = year >= 100 ? year : 2000 + year;
  const lastDay = new Date(fullYear, month, 0).getDate();
  const expiry = new Date(fullYear, month - 1, lastDay, 23, 59, 59, 999);

  return expiry.getTime() >= now.getTime();
}

export interface CardExpiryParts {
  month: number | null;
  year: number | null;
}

export function parseExpiry(formatted: string): CardExpiryParts {
  const digits = digitsOnly(formatted);
  if (digits.length !== 4) {
    return { month: null, year: null };
  }

  const month = Number(digits.slice(0, 2));
  const year = Number(digits.slice(2));

  return { month, year };
}
