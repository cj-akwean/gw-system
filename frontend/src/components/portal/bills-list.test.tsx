import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { BillsList } from "./bills-list";
import type { PortalInvoice } from "@/lib/api";

const mockGetInvoices = vi.fn();
const mockLogout = vi.fn();
const mockReplace = vi.fn();
const mockRouter = { replace: mockReplace, push: vi.fn() };

vi.mock("@/lib/api", () => ({
  getInvoices: (...args: unknown[]) => mockGetInvoices(...args),
  formatPeso: (n: number) => `₱${n.toFixed(2)}`,
  ApiError: class extends Error {
    status: number;
    constructor(message: string, status: number) {
      super(message);
      this.status = status;
    }
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => mockRouter,
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ logout: mockLogout }),
}));

const invoice = (overrides: Partial<PortalInvoice> = {}): PortalInvoice => ({
  id: 1,
  invoice_number: "GW-2026-00001",
  billing_period_start: "2026-07-01",
  billing_period_end: "2026-07-31",
  due_date: "2026-08-15",
  previous_balance: 0,
  base_amount: 150,
  penalty_amount: 0,
  total_amount: 150,
  status: "unpaid",
  service_connection: {
    account_number: "GW-00001",
    meter_number: "MTR-00001",
    registered_name: "Maria Santos",
    barangay: "Poblacion",
  },
  ...overrides,
});

describe("BillsList", () => {
  beforeEach(() => {
    mockGetInvoices.mockReset();
    mockLogout.mockReset();
    mockReplace.mockReset();
  });

  it("shows a loading state while fetching", () => {
    mockGetInvoices.mockReturnValue(new Promise(() => {}));

    render(<BillsList />);

    expect(screen.getByText(/loading your bills/i)).toBeInTheDocument();
  });

  it("renders unpaid bills with totals", async () => {
    mockGetInvoices.mockResolvedValue([
      invoice({ id: 1, total_amount: 150, status: "overdue" }),
      invoice({ id: 2, invoice_number: "GW-2026-00002", total_amount: 250 }),
    ]);

    render(<BillsList />);

    expect(await screen.findByTestId("invoice-1")).toBeInTheDocument();
    expect(screen.getByTestId("invoice-2")).toBeInTheDocument();
    expect(screen.getByText(/2 unpaid bills/i)).toBeInTheDocument();
    expect(screen.getByText(/₱400.00 total/i)).toBeInTheDocument();
    expect(screen.getByText("overdue")).toBeInTheDocument();
  });

  it("shows previous balance and penalty rows only when nonzero", async () => {
    mockGetInvoices.mockResolvedValue([
      invoice({ id: 1, previous_balance: 50, penalty_amount: 5 }),
      invoice({ id: 2 }),
    ]);

    render(<BillsList />);

    expect(await screen.findByTestId("invoice-1")).toBeInTheDocument();
    expect(screen.getAllByText("Previous balance")).toHaveLength(1);
    expect(screen.getAllByText("Penalty")).toHaveLength(1);
    expect(screen.queryByTestId("invoice-2")).toBeInTheDocument();
  });

  it("shows an empty state when there are no unpaid bills", async () => {
    mockGetInvoices.mockResolvedValue([]);

    render(<BillsList />);

    expect(await screen.findByText(/all caught up/i)).toBeInTheDocument();
    expect(
      screen.getByText(/no unpaid bills on your linked accounts/i)
    ).toBeInTheDocument();
  });

  it("shows an error state and retries", async () => {
    mockGetInvoices
      .mockRejectedValueOnce(
        Object.assign(new Error("boom"), { status: 500, message: "boom" })
      )
      .mockResolvedValueOnce([invoice()]);

    const user = userEvent.setup();
    render(<BillsList />);

    expect(await screen.findByText(/couldn't load your bills/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /try again/i }));

    expect(await screen.findByTestId("invoice-1")).toBeInTheDocument();
  });

  it("redirects to /auth and logs out on a 401", async () => {
    mockGetInvoices.mockRejectedValue(
      Object.assign(new Error("Unauthenticated."), { status: 401 })
    );
    mockLogout.mockResolvedValue(undefined);

    render(<BillsList />);

    await vi.waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockReplace).toHaveBeenCalledWith("/auth");
    });
  });

  it("renders a Pay button per bill that opens the payment screen", async () => {
    mockGetInvoices.mockResolvedValue([
      invoice({ id: 1 }),
      invoice({ id: 2, invoice_number: "GW-2026-00002" }),
    ]);

    const user = userEvent.setup();
    render(<BillsList />);

    await screen.findByTestId("invoice-1");

    await user.click(screen.getByTestId("pay-1"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard/pay?id=1");

    await user.click(screen.getByTestId("pay-2"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard/pay?id=2");
  });
});