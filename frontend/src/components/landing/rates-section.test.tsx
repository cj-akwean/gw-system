import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { RatesSection } from "./rates-section";

const mockGetRates = vi.fn();

vi.mock("@/lib/api", () => ({
  getRates: (...args: unknown[]) => mockGetRates(...args),
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("next/link", () => ({
  default: ({
    href,
    children,
    ...props
  }: {
    href: string;
    children: React.ReactNode;
  }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

vi.mock("@/components/ui/liquid-ocean", () => ({
  LiquidOcean: () => <div data-testid="liquid-ocean" />,
}));

let mockUseAuth: () => { isAuthenticated: boolean; ready: boolean };

const ratesPayload = {
  schedule: {
    name: "Standard Flat Rate",
    type: "flat",
    flat_rate: 10,
    effective_from: "2026-01-01",
    tiers: [],
  },
  penalty: {
    percent_per_month: 2,
    grace_period_days: 15,
    disconnection_after_days: 60,
  },
};

describe("RatesSection", () => {
  beforeEach(() => {
    mockGetRates.mockReset();
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });
  });

  it("shows a loading state while fetching", () => {
    mockGetRates.mockReturnValue(new Promise(() => {}));

    render(<RatesSection />);

    expect(screen.getByText(/loading current rates/i)).toBeInTheDocument();
  });

  it("renders the live rate, features, and guest CTA", async () => {
    mockGetRates.mockResolvedValue(ratesPayload);

    render(<RatesSection />);

    expect(await screen.findByText("₱10.00")).toBeInTheDocument();
    expect(screen.getByText(/flat rate per cubic meter, effective/i)).toBeInTheDocument();
    expect(screen.getByText(/2% monthly penalty/i)).toBeInTheDocument();
    expect(screen.getByText(/15-day grace period/i)).toBeInTheDocument();
    expect(screen.getByText(/disconnection after 60 days/i)).toBeInTheDocument();

    const payLink = screen.getByRole("link", { name: "Pay My Bill" });
    expect(payLink).toHaveAttribute("href", "/auth");
  });

  it("points Pay My Bill at the dashboard for signed-in users", async () => {
    mockGetRates.mockResolvedValue(ratesPayload);
    mockUseAuth = () => ({ isAuthenticated: true, ready: true });

    render(<RatesSection />);

    await screen.findByText("₱10.00");

    expect(screen.getByRole("link", { name: "Pay My Bill" })).toHaveAttribute(
      "href",
      "/dashboard"
    );
  });

  it("falls back to office contact text when rates fail to load", async () => {
    mockGetRates.mockRejectedValue(new Error("boom"));

    render(<RatesSection />);

    expect(
      await screen.findByText(/contact the office/i)
    ).toBeInTheDocument();
  });
});
