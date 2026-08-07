import {
  describe,
  it,
  expect,
  vi,
  beforeEach,
  afterEach,
} from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { PaymentMethodScreen } from "@/components/portal/payment-method";
import type { PortalInvoice } from "@/lib/api";

const mockGetInvoices = vi.fn();
const mockGetInvoice = vi.fn();
const mockStartPayment = vi.fn();
const mockCreatePaymentMethod = vi.fn();
const mockAttachPaymentMethod = vi.fn();
const mockLogout = vi.fn();
const mockReplace = vi.fn();
const mockRouter = { replace: mockReplace, push: vi.fn() };

vi.mock("@/lib/api", () => ({
  getInvoices: (...args: unknown[]) => mockGetInvoices(...args),
  getInvoice: (...args: unknown[]) => mockGetInvoice(...args),
  startPayment: (...args: unknown[]) => mockStartPayment(...args),
  formatPeso: (n: number) => `₱${Number(n).toFixed(2)}`,
  buildReturnUrl: (id: number | string) =>
    `http://localhost/dashboard/pay?id=${id}&from=gcash`,
  ApiError: class extends Error {
    status: number;
    constructor(message: string, status: number) {
      super(message);
      this.status = status;
    }
  },
}));

vi.mock("@/lib/paymongo", () => ({
  createPaymentMethod: (...args: unknown[]) => mockCreatePaymentMethod(...args),
  attachPaymentMethod: (...args: unknown[]) => mockAttachPaymentMethod(...args),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => mockRouter,
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({
    user: { name: "Maria", email: "maria@example.com" },
    logout: mockLogout,
  }),
}));

vi.mock("@/components/portal/dashboard-header", () => ({
  DashboardHeader: () => <div>header</div>,
}));

const QR_IMAGE = "data:image/png;base64,iVBORw0KGgo=";

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

const intentInfo = {
  client_key: "ck_1",
  payment_intent_id: "pi_1",
  expiry_seconds: 600,
};

const qrAttachResult = () => ({
  status: "awaiting_next_action",
  imageUrl: QR_IMAGE,
  redirectUrl: null,
  expiresAt: new Date(Date.now() + 600_000).toISOString(),
});

const assignSpy = vi.fn();

async function renderReady() {
  mockGetInvoices.mockResolvedValue([invoice()]);
  render(<PaymentMethodScreen invoiceId="1" />);
  await vi.waitFor(() =>
    expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
  );
}

function selectMethod(method: "qrph" | "gcash" = "qrph") {
  fireEvent.click(screen.getByTestId(method === "gcash" ? "gcash-row" : "qr-ph-row"));
}

async function goToReview(method: "qrph" | "gcash" = "qrph") {
  await renderReady();
  selectMethod(method);
  await vi.waitFor(() =>
    expect(screen.getByTestId("review-step")).toBeInTheDocument()
  );
}

function clickPay() {
  fireEvent.click(screen.getByTestId("pay-now"));
}

async function flushAsync() {
  await act(async () => {
    for (let i = 0; i < 5; i++) await Promise.resolve();
  });
}

describe("PaymentMethodScreen", () => {
  beforeEach(() => {
    mockGetInvoices.mockReset();
    mockGetInvoice.mockReset();
    mockStartPayment.mockReset();
    mockCreatePaymentMethod.mockReset();
    mockAttachPaymentMethod.mockReset();
    mockLogout.mockReset();
    mockReplace.mockReset();
    mockRouter.push.mockReset();
    Object.defineProperty(window, "location", {
      writable: true,
      value: {
        ...window.location,
        search: "",
        pathname: "/dashboard/pay/1",
        assign: assignSpy,
      },
    });
    assignSpy.mockReset();
    window.sessionStorage.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("shows a loading state while fetching the invoice", () => {
    mockGetInvoices.mockReturnValue(new Promise(() => {}));

    render(<PaymentMethodScreen invoiceId="1" />);

    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("shows not-payable when the invoice is not in the unpaid list", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockRejectedValue(
      Object.assign(new Error("Forbidden"), { status: 403 })
    );

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByText(/isn't available for payment/i)).toBeInTheDocument()
    );
  });

  it("shows the paid panel when the webhook beat the UI", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("paid-panel")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment received/i)).toBeInTheDocument();
  });

  it("logs out on a 401 from the invoice status check", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockRejectedValue(
      Object.assign(new Error("Unauthenticated."), { status: 401 })
    );
    mockLogout.mockResolvedValue(undefined);

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockReplace).toHaveBeenCalledWith("/auth");
    });
  });

  it("shows an error state and retries the load", async () => {
    mockGetInvoices
      .mockRejectedValueOnce(
        Object.assign(new Error("boom"), { status: 500, message: "boom" })
      )
      .mockResolvedValueOnce([invoice()]);

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByText(/couldn't load this bill/i)).toBeInTheDocument()
    );

    fireEvent.click(screen.getByRole("button", { name: /try again/i }));

    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );
  });

  it("redirects to /auth on a 401 while loading", async () => {
    mockGetInvoices.mockRejectedValue(
      Object.assign(new Error("Unauthenticated."), { status: 401 })
    );
    mockLogout.mockResolvedValue(undefined);

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockReplace).toHaveBeenCalledWith("/auth");
    });
  });

  it("renders the three method cards with E-wallet recommended", async () => {
    await renderReady();

    expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument();
    expect(screen.getByTestId("method-card-card")).toBeInTheDocument();
    expect(screen.getByTestId("method-card-digital-wallet")).toBeInTheDocument();
    expect(screen.getByText("Recommended")).toBeInTheDocument();
    expect(screen.getAllByText("Coming soon")).toHaveLength(2);
    expect(screen.getByTestId("pay-amount")).toHaveTextContent("₱150.00");
  });

  it("disables e-wallet methods for bills over the ₱100,000 cap", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ total_amount: 100_001 })]);

    render(<PaymentMethodScreen invoiceId="1" />);
    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );

    expect(screen.getByTestId("qr-ph-row")).toBeDisabled();
    expect(screen.getByTestId("gcash-row")).toBeDisabled();
    expect(screen.getByText(/over ₱100,000/i)).toBeInTheDocument();
  });

  it("shows the review step on method selection without auto-attaching", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);

    await renderReady();
    selectMethod();

    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );
    expect(screen.getByText("QR Ph (scan QR)")).toBeInTheDocument();
    expect(screen.getByTestId("review-total")).toHaveTextContent("₱150.00");
    expect(mockStartPayment).not.toHaveBeenCalled();
    expect(mockCreatePaymentMethod).not.toHaveBeenCalled();
    expect(mockAttachPaymentMethod).not.toHaveBeenCalled();
  });

  it("shows line items and hides zero-value rows on the review step", async () => {
    mockGetInvoices.mockResolvedValue([
      invoice({ id: 1, previous_balance: 50, penalty_amount: 5, total_amount: 205 }),
    ]);

    render(<PaymentMethodScreen invoiceId="1" />);
    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );
    selectMethod();

    expect(await screen.findByTestId("review-step")).toBeInTheDocument();
    expect(screen.getByText("Current charges")).toBeInTheDocument();
    expect(screen.getByText("Arrears")).toBeInTheDocument();
    expect(screen.getByText("Penalty")).toBeInTheDocument();
    expect(screen.getByTestId("review-total")).toHaveTextContent("₱205.00");
  });

  it("Change returns to the method step without any API calls", async () => {
    await goToReview();
    fireEvent.click(screen.getByTestId("change-method"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("review-step")).not.toBeInTheDocument();
    expect(mockStartPayment).not.toHaveBeenCalled();
    expect(mockCreatePaymentMethod).not.toHaveBeenCalled();
  });

  it("generates and shows the QR with a countdown from the backend expiry", async () => {
    vi.useFakeTimers();
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_qr_1");
    mockAttachPaymentMethod.mockImplementation(() => Promise.resolve(qrAttachResult()));

    await goToReview();
    clickPay();
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toHaveAttribute("src", QR_IMAGE);
    expect(mockCreatePaymentMethod).toHaveBeenCalledWith("qrph", {
      expiry_seconds: 600,
    });
    expect(mockAttachPaymentMethod).toHaveBeenCalledWith({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_qr_1",
    });
    expect(screen.getByTestId("countdown")).toHaveTextContent("10:00");

    act(() => {
      vi.advanceTimersByTime(1_000);
    });
    expect(screen.getByTestId("countdown")).toHaveTextContent("09:59");
  });

  it("shows the expired state at zero and lets the user get a new QR", async () => {
    vi.useFakeTimers();
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_qr_1");
    mockAttachPaymentMethod.mockImplementation(() => Promise.resolve(qrAttachResult()));

    await goToReview();
    clickPay();
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(601_000);
    });

    expect(screen.getByTestId("qr-expired")).toBeInTheDocument();
    expect(screen.getByText(/QR code expired/i)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId("get-new-qr"));
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toHaveAttribute("src", QR_IMAGE);
    expect(mockCreatePaymentMethod).toHaveBeenCalledTimes(2);
    expect(screen.getByTestId("countdown")).toHaveTextContent("10:00");
  });

  it("does not start a second payment on a double Pay click", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_qr_1");
    mockAttachPaymentMethod.mockImplementation(() => Promise.resolve(qrAttachResult()));

    await goToReview();
    clickPay();
    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("qr-image")).toBeInTheDocument()
    );
    expect(mockCreatePaymentMethod).toHaveBeenCalledTimes(1);
    expect(mockAttachPaymentMethod).toHaveBeenCalledTimes(1);
  });

  it("resumes a still-valid stored QR without re-attaching", async () => {
    window.sessionStorage.setItem(
      "gw-qr:pi_1",
      JSON.stringify({
        intentId: "pi_1",
        imageUrl: QR_IMAGE,
        deadline: Date.now() + 300_000,
      })
    );
    mockStartPayment.mockResolvedValue(intentInfo);

    await goToReview();
    clickPay();
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toHaveAttribute("src", QR_IMAGE);
    expect(mockCreatePaymentMethod).not.toHaveBeenCalled();
    expect(mockAttachPaymentMethod).not.toHaveBeenCalled();
    expect(screen.getByTestId("countdown")).toHaveTextContent(/^\d{2}:\d{2}$/);
  });

  it("re-attaches when the stored QR has already expired", async () => {
    window.sessionStorage.setItem(
      "gw-qr:pi_1",
      JSON.stringify({
        intentId: "pi_1",
        imageUrl: QR_IMAGE,
        deadline: Date.now() - 5_000,
      })
    );
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_qr_2");
    mockAttachPaymentMethod.mockImplementation(() => Promise.resolve(qrAttachResult()));

    await goToReview();
    clickPay();
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toBeInTheDocument();
    expect(mockCreatePaymentMethod).toHaveBeenCalledWith("qrph", {
      expiry_seconds: 600,
    });
  });

  it("shows an error panel when starting fails and retries", async () => {
    mockStartPayment
      .mockRejectedValueOnce(
        new Error("Payment gateway unavailable. Please try again.")
      )
      .mockResolvedValueOnce(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_qr_1");
    mockAttachPaymentMethod.mockImplementation(() => Promise.resolve(qrAttachResult()));

    await goToReview();
    clickPay();
    await flushAsync();

    expect(screen.getByText(/payment couldn't start/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /try again/i }));
    await flushAsync();

    expect(screen.getByTestId("qr-image")).toBeInTheDocument();
  });

  it("logs out on a 401 from startPayment", async () => {
    mockStartPayment.mockRejectedValue(
      Object.assign(new Error("Unauthenticated."), { status: 401 })
    );
    mockLogout.mockResolvedValue(undefined);

    await goToReview();
    clickPay();

    await vi.waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockReplace).toHaveBeenCalledWith("/auth");
    });
  });

  it("redirects to GCash with the return url", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_gcash_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "awaiting_next_action",
      imageUrl: null,
      redirectUrl: "https://checkout.paymongo.com/gcash/xyz",
      expiresAt: null,
    });

    await goToReview("gcash");
    clickPay();

    await vi.waitFor(() =>
      expect(assignSpy).toHaveBeenCalledWith(
        "https://checkout.paymongo.com/gcash/xyz"
      )
    );
    expect(mockCreatePaymentMethod).toHaveBeenCalledWith("gcash");
    expect(mockAttachPaymentMethod).toHaveBeenCalledWith({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_gcash_1",
      returnUrl: "http://localhost/dashboard/pay?id=1&from=gcash",
    });
  });

  it("shows a pending banner when returning from the GCash redirect", async () => {
    mockGetInvoices.mockResolvedValue([invoice()]);

    render(<PaymentMethodScreen invoiceId="1" returnedFromGcash />);

    expect(await screen.findByTestId("pending-banner")).toBeInTheDocument();
  });

  it("does not show a pending banner on a normal visit", async () => {
    await renderReady();

    expect(screen.queryByTestId("pending-banner")).not.toBeInTheDocument();
  });

  it("shows the paid panel once the invoice leaves the unpaid list", async () => {
    vi.useFakeTimers();
    mockGetInvoices
      .mockResolvedValueOnce([invoice()])
      .mockResolvedValueOnce([]);

    render(<PaymentMethodScreen invoiceId="1" />);
    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );

    act(() => {
      vi.advanceTimersByTime(15_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("paid-panel")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment received/i)).toBeInTheDocument();
  });
});
