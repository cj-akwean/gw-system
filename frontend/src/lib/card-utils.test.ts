import { describe, it, expect } from "vitest";
import {
  digitsOnly,
  expiryIsValid,
  formatCardNumber,
  formatExpiry,
  luhnValid,
  parseExpiry,
} from "@/lib/card-utils";

describe("digitsOnly", () => {
  it("strips everything but digits", () => {
    expect(digitsOnly("4343 4343 4343 4345")).toBe("4343434343434345");
    expect(digitsOnly("12/29")).toBe("1229");
    expect(digitsOnly("abc123!@#")).toBe("123");
  });
});

describe("formatCardNumber", () => {
  it("groups digits by four", () => {
    expect(formatCardNumber("4343434343434345")).toBe("4343 4343 4343 4345");
  });

  it("caps at 19 digits and ignores non-digits", () => {
    expect(formatCardNumber("4343-4343-4343-4343-99999")).toBe("4343 4343 4343 4343 999");
  });

  it("handles partial input", () => {
    expect(formatCardNumber("4343")).toBe("4343");
    expect(formatCardNumber("")).toBe("");
  });
});

describe("formatExpiry", () => {
  it("inserts the slash after two digits", () => {
    expect(formatExpiry("1229")).toBe("12/29");
    expect(formatExpiry("12/29")).toBe("12/29");
    expect(formatExpiry("12")).toBe("12");
    expect(formatExpiry("1")).toBe("1");
    expect(formatExpiry("12345")).toBe("12/34");
  });
});

describe("luhnValid", () => {
  it("accepts valid PayMongo test cards", () => {
    expect(luhnValid("4343434343434345")).toBe(true);
    expect(luhnValid("4120000000000007")).toBe(true);
    expect(luhnValid("5100000000000198")).toBe(true);
  });

  it("rejects invalid checksums", () => {
    expect(luhnValid("4343434343434344")).toBe(false);
  });

  it("rejects out-of-range lengths", () => {
    expect(luhnValid("123")).toBe(false);
    expect(luhnValid("123456789012345678901")).toBe(false);
  });
});

describe("expiryIsValid", () => {
  const now = new Date(2026, 7, 7); // 2026-08-07

  it("accepts future months including the current month's last day", () => {
    expect(expiryIsValid(12, 26, now)).toBe(true);
    expect(expiryIsValid(8, 26, now)).toBe(true); // end of current month
    expect(expiryIsValid(1, 27, now)).toBe(true);
  });

  it("rejects past months, month zero, and month 13", () => {
    expect(expiryIsValid(7, 26, now)).toBe(false); // July 2026 already ended
    expect(expiryIsValid(1, 25, now)).toBe(false);
    expect(expiryIsValid(0, 27, now)).toBe(false);
    expect(expiryIsValid(13, 27, now)).toBe(false);
  });

  it("handles 4-digit years", () => {
    expect(expiryIsValid(12, 2026, now)).toBe(true);
    expect(expiryIsValid(1, 2025, now)).toBe(false);
  });
});

describe("parseExpiry", () => {
  it("parses MM/YY", () => {
    expect(parseExpiry("12/29")).toEqual({ month: 12, year: 29 });
    expect(parseExpiry("8/29")).toEqual({ month: null, year: null });
    expect(parseExpiry("")).toEqual({ month: null, year: null });
  });
});
