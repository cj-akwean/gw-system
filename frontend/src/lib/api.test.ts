import { describe, it, expect } from "vitest";
import { formatPeso } from "@/lib/api";

describe("formatPeso", () => {
  it("formats amounts with two decimals", () => {
    expect(formatPeso(150)).toBe("₱150.00");
    expect(formatPeso(2054.5)).toBe("₱2,054.50");
  });

  it("handles zero and null", () => {
    expect(formatPeso(0)).toBe("₱0.00");
    expect(formatPeso(null)).toBe("₱0.00");
    expect(formatPeso(undefined)).toBe("₱0.00");
  });

  it("handles string input", () => {
    expect(formatPeso("205.00")).toBe("₱205.00");
  });

  it("returns an em dash for non-finite input", () => {
    expect(formatPeso(NaN)).toBe("—");
    expect(formatPeso("abc")).toBe("—");
  });
});