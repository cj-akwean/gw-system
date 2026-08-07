import { describe, it, expect } from "vitest";
import { formatRemaining, remainingMs } from "@/lib/countdown";

describe("formatRemaining", () => {
  it("formats milliseconds as mm:ss", () => {
    expect(formatRemaining(600_000)).toBe("10:00");
    expect(formatRemaining(599_000)).toBe("09:59");
    expect(formatRemaining(60_000)).toBe("01:00");
    expect(formatRemaining(1_000)).toBe("00:01");
    expect(formatRemaining(0)).toBe("00:00");
  });

  it("clamps negative and fractional input to zero", () => {
    expect(formatRemaining(-5_000)).toBe("00:00");
    expect(formatRemaining(999)).toBe("00:00");
  });
});

describe("remainingMs", () => {
  it("counts down to zero, never negative", () => {
    expect(remainingMs(10_000, 5_000)).toBe(5_000);
    expect(remainingMs(5_000, 10_000)).toBe(0);
    expect(remainingMs(5_000, 5_000)).toBe(0);
  });
});
