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
const mockResolveIntentStatus = vi.fn();
const mockStartPayment = vi.fn();
const mockCreatePaymentMethod = vi.fn();
const mockAttachPaymentMethod = vi.fn();
const mockWritePendingInvoice = vi.fn();
const mockClearPendingInvoice = vi.fn();
const mockLogout = vi.fn();
const mockReplace = vi.fn();
const mockRouter = { replace: mockReplace, push: vi.fn() };
const mockGetSavedPaymentMethods = vi.fn().mockResolvedValue([]);
const mockPayWithSaved = vi.fn();
const mockDeleteSavedPaymentMethod = vi.fn();

vi.mock("@/lib/api", () => ({
  getInvoices: (...args: unknown[]) => mockGetInvoices(...args),
  getInvoice: (...args: unknown[]) => mockGetInvoice(...args),
  resolveIntentStatus: (...args: unknown[]) => mockResolveIntentStatus(...args),
  startPayment: (...args: unknown[]) => mockStartPayment(...args),
  writePendingInvoice: (...args: unknown[]) => mockWritePendingInvoice(...args),
  clearPendingInvoice: (...args: unknown[]) => mockClearPendingInvoice(...args),
  getSavedPaymentMethods: (...args: unknown[]) => mockGetSavedPaymentMethods(...args),
  payWithSaved: (...args: unknown[]) => mockPayWithSaved(...args),
  deleteSavedPaymentMethod: (...args: unknown[]) => mockDeleteSavedPaymentMethod(...args),
  formatPeso: (n: number) => `₱${Number(n).toFixed(2)}`,
  buildReturnUrl: (id: number | string) =>
    `http://localhost/dashboard/pay?id=${id}&from=redirect`,
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
    user: { id: 1, name: "Maria", email: "maria@example.com" },
    logout: mockLogout,
  }),
}));

vi.mock("@/components/portal/dashboard-header", () => ({
  DashboardHeader: () => <div>header</div>,
}));

vi.mock("@/components/portal/saved-card-selector", () => ({
  SavedCardSelector: () => <div data-testid="saved-card-selector" />,
}));

vi.mock("@/components/ui/swipe-button", () => ({
  SwipeButton: ({
    onSwipeComplete,
    text,
    ...props
  }: {
    onSwipeComplete?: () => void;
    text?: string;
    "data-testid"?: string;
  }) => (
    <button type="button" {...props} onClick={onSwipeComplete}>
      {text}
    </button>
  ),
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
    mockResolveIntentStatus.mockReset();
    mockStartPayment.mockReset();
    mockWritePendingInvoice.mockReset();
    mockCreatePaymentMethod.mockReset();
    mockAttachPaymentMethod.mockReset();
    mockLogout.mockReset();
    mockReplace.mockReset();
    mockRouter.push.mockReset();
    mockGetSavedPaymentMethods.mockReset().mockResolvedValue([]);
    mockPayWithSaved.mockReset();
    mockDeleteSavedPaymentMethod.mockReset();
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

  it("shows the context-missing state for an empty id without any API calls", async () => {
    render(<PaymentMethodScreen invoiceId="" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("context-missing-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();
    expect(screen.queryByTestId("unconfirmed-panel")).not.toBeInTheDocument();
    expect(screen.getByTestId("check-my-bills")).toBeInTheDocument();
    expect(mockGetInvoices).not.toHaveBeenCalled();
    expect(mockGetInvoice).not.toHaveBeenCalled();
    expect(mockResolveIntentStatus).not.toHaveBeenCalled();
  });

  it("shows the success toast when the intent status is paid and auto-returns", async () => {
    vi.useFakeTimers();
    mockResolveIntentStatus.mockResolvedValue({ status: "paid", invoice_id: 1 });

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment received/i)).toBeInTheDocument();
    expect(screen.getByText(/emailed to maria@example.com/i)).toBeInTheDocument();
    expect(screen.queryByTestId("paid-panel")).not.toBeInTheDocument();

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
  });

  it("shows the confirming modal until the webhook credits, then the success modal", async () => {
    vi.useFakeTimers();
    mockResolveIntentStatus.mockResolvedValue({ status: "confirmed", invoice_id: 1 });
    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment confirmed with your provider/i)).toBeInTheDocument();
    // No escape that would strand the user before the success confirmation
    // (a "back" click here lost the modal forever).
    expect(
      screen.queryByRole("button", { name: /back to my bills/i })
    ).not.toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("confirming-modal")).not.toBeInTheDocument();
    expect(screen.getByText(/emailed to maria@example.com/i)).toBeInTheDocument();

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
  });

  it("shows the failed panel and lets the user try again on the same bill", async () => {
    mockResolveIntentStatus.mockResolvedValue({ status: "failed", invoice_id: 1 });
    mockGetInvoice.mockResolvedValue(invoice({ id: 1 }));

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("failed-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId("retry-payment"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );
    expect(mockGetInvoice).toHaveBeenCalledWith(1);
  });

  it("shows the confirming modal while the intent is processing, then resolves to paid", async () => {
    vi.useFakeTimers();
    mockResolveIntentStatus.mockResolvedValue({ status: "processing", invoice_id: 1 });
    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
  });

  it("never dead-ends when the intent cannot be resolved", async () => {
    mockResolveIntentStatus.mockResolvedValue({ status: "unknown" });

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("unconfirmed-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();
  });

  it("keeps checking when the intent-status request fails transiently", async () => {
    mockResolveIntentStatus.mockRejectedValue(
      Object.assign(new Error("boom"), { status: 500, message: "boom" })
    );

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("unconfirmed-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();
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

  it("points a redirect return to the bills list instead of a dead end", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockRejectedValue(
      Object.assign(new Error("Forbidden"), { status: 403 })
    );

    render(<PaymentMethodScreen invoiceId="1" returnedFromRedirect />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("check-my-bills")).toBeInTheDocument()
    );
    expect(
      screen.getByText(/If you just completed a payment/i)
    ).toBeInTheDocument();
  });

  it("shows the checking state when the status probe fails, then resolves on retry", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice
      .mockRejectedValueOnce(
        Object.assign(new Error("boom"), { status: 500, message: "boom" })
      )
      .mockResolvedValueOnce(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("unconfirmed-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId("check-again"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
  });

  it("never turns an unrecognized response into not-available", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockResolvedValue({} as never);

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("unconfirmed-panel")).toBeInTheDocument()
    );
    expect(screen.queryByText(/isn't available for payment/i)).not.toBeInTheDocument();
  });

  it("resolves the checking state automatically once the webhook reports paid", async () => {
    vi.useFakeTimers();
    mockGetInvoices
      .mockResolvedValueOnce([invoice({ id: 2 })])
      .mockResolvedValueOnce([]);
    mockGetInvoice
      .mockRejectedValueOnce(
        Object.assign(new Error("boom"), { status: 500, message: "boom" })
      )
      .mockResolvedValueOnce(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="1" />);
    await vi.waitFor(() =>
      expect(screen.getByTestId("unconfirmed-panel")).toBeInTheDocument()
    );

    act(() => {
      vi.advanceTimersByTime(15_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
  });

  it("shows the success toast when the webhook beat the UI", async () => {
    vi.useFakeTimers();
    mockGetInvoices.mockResolvedValue([invoice({ id: 2 })]);
    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));

    render(<PaymentMethodScreen invoiceId="1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment received/i)).toBeInTheDocument();

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
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
    expect(screen.getByText("Coming soon")).toBeInTheDocument();
    expect(screen.queryByText("Visa and Mastercard (coming soon)")).not.toBeInTheDocument();
    expect(screen.getByTestId("pay-amount")).toHaveTextContent("₱150.00");
  });

  it("disables e-wallet methods but keeps Card for bills over the ₱100,000 cap", async () => {
    mockGetInvoices.mockResolvedValue([invoice({ total_amount: 100_001 })]);

    render(<PaymentMethodScreen invoiceId="1" />);
    await vi.waitFor(() =>
      expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument()
    );

    expect(screen.getByTestId("qr-ph-row")).toBeDisabled();
    expect(screen.getByTestId("gcash-row")).toBeDisabled();
    expect(screen.getByText(/over ₱100,000/i)).toBeInTheDocument();
    expect(screen.getByTestId("method-card-card")).not.toBeDisabled();
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
    expect(mockWritePendingInvoice).toHaveBeenCalledWith("1", {
      paymentIntentId: "pi_1",
      method: "gcash",
    });
    expect(mockCreatePaymentMethod).toHaveBeenCalledWith("gcash");
    expect(mockAttachPaymentMethod).toHaveBeenCalledWith({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_gcash_1",
      returnUrl: "http://localhost/dashboard/pay?id=1&from=redirect",
    });
  });

  it("shows the confirming modal on a redirect return while the webhook lags", async () => {
    vi.useFakeTimers();
    mockResolveIntentStatus.mockResolvedValue({ status: "confirmed", invoice_id: 1 });
    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));

    // The invoice is still unpaid in the list (webhook not credited yet) —
    // the intent resolution must still win and show the confirming modal,
    // never the interactive pay screen.
    mockGetInvoices.mockResolvedValue([invoice()]);

    render(
      <PaymentMethodScreen
        invoiceId="1"
        paymentIntentId="pi_1"
        returnedFromRedirect
      />
    );

    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("method-card-ewallet")).not.toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
  });

  it("keeps the confirming modal through connection loss and recovers", async () => {
    vi.useFakeTimers();
    mockResolveIntentStatus.mockResolvedValue({ status: "confirmed", invoice_id: 1 });
    // Reject until overridden — the poll must never flip the outcome on
    // network errors, and the hint appears after two consecutive failures.
    mockGetInvoice.mockRejectedValue(
      Object.assign(new Error("net down"), { status: 0, message: "net down" })
    );

    render(<PaymentMethodScreen invoiceId="" paymentIntentId="pi_1" />);

    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );

    act(() => {
      vi.advanceTimersByTime(5_000);
    });
    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    // Network errors never flip the outcome — after two failures the modal
    // hints at the connection problem and keeps waiting.
    expect(screen.getByTestId("confirming-modal")).toBeInTheDocument();
    await vi.waitFor(() =>
      expect(screen.getByTestId("connection-trouble")).toBeInTheDocument()
    );

    mockGetInvoice.mockResolvedValue(invoice({ id: 1, status: "paid" }));
    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("connection-trouble")).not.toBeInTheDocument();
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
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.getByText(/Payment received/i)).toBeInTheDocument();
    // The pay screen stays visible under the toast — no white page swap.
    expect(screen.getByTestId("method-card-ewallet")).toBeInTheDocument();

    // No auto-redirect - the modal waits for the user.
    act(() => {
      vi.advanceTimersByTime(4_000);
    });
    expect(mockRouter.push).not.toHaveBeenCalled();

    fireEvent.click(screen.getByTestId("success-ok"));
    expect(mockRouter.push).toHaveBeenCalledWith("/dashboard");
  });

  it("selecting Card shows the card form on the review step", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );
    expect(screen.getByTestId("card-number")).toBeInTheDocument();
    expect(screen.getByTestId("card-cvc")).toBeInTheDocument();
    expect(screen.getByTestId("card-first-name")).toBeInTheDocument();
    expect(screen.getByTestId("card-address")).toBeInTheDocument();
    expect(screen.getByTestId("card-zip")).toBeInTheDocument();
    expect(screen.getByText(/Email on file: maria@example.com/)).toBeInTheDocument();
    expect(mockStartPayment).not.toHaveBeenCalled();
  });

  it("blocks Pay with invalid card input and shows inline errors", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "4343 4343 4343 4344" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "01/20" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "12" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "  " },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(screen.getAllByRole("alert").length).toBeGreaterThan(0)
    );
    expect(mockStartPayment).not.toHaveBeenCalled();
    expect(mockCreatePaymentMethod).not.toHaveBeenCalled();
  });

  it("pays with a valid card client-side and redirects for 3DS", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_card_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "awaiting_next_action",
      imageUrl: null,
      redirectUrl: "https://checkout.paymongo.com/3ds/xyz",
      expiresAt: null,
      lastPaymentError: null,
    });

    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "4343 4343 4343 4345" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "12/29" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "123" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "Maria" },
    });
    fireEvent.change(screen.getByTestId("card-last-name"), {
      target: { value: "Santos" },
    });
    fireEvent.change(screen.getByTestId("card-address"), {
      target: { value: "123 Purok 3" },
    });
    fireEvent.change(screen.getByTestId("card-city"), {
      target: { value: "Guinobatan" },
    });
    fireEvent.change(screen.getByTestId("card-zip"), {
      target: { value: "4503" },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(assignSpy).toHaveBeenCalledWith(
        "https://checkout.paymongo.com/3ds/xyz"
      )
    );
    expect(mockWritePendingInvoice).toHaveBeenCalledWith("1", {
      paymentIntentId: "pi_1",
      method: "card",
    });
    expect(mockCreatePaymentMethod).toHaveBeenCalledWith("card", {
      details: {
        card_number: "4343434343434345",
        exp_month: 12,
        exp_year: 29,
        cvc: "123",
      },
      billing: {
        name: "Maria Santos",
        email: "maria@example.com",
        address: {
          line1: "123 Purok 3",
          city: "Guinobatan",
          postal_code: "4503",
          country: "PH",
        },
      },
    });
    expect(mockAttachPaymentMethod).toHaveBeenCalledWith({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_card_1",
      returnUrl: "http://localhost/dashboard/pay?id=1&from=redirect",
    });
  });

  it("surfaces a decline from last_payment_error and lets the user retry", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_card_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "awaiting_payment_method",
      imageUrl: null,
      redirectUrl: null,
      expiresAt: null,
      lastPaymentError: "Card declined: insufficient funds",
    });

    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "5100 0000 0000 0198" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "12/29" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "123" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "Maria" },
    });
    fireEvent.change(screen.getByTestId("card-last-name"), {
      target: { value: "Santos" },
    });
    fireEvent.change(screen.getByTestId("card-address"), {
      target: { value: "123 Purok 3" },
    });
    fireEvent.change(screen.getByTestId("card-city"), {
      target: { value: "Guinobatan" },
    });
    fireEvent.change(screen.getByTestId("card-zip"), {
      target: { value: "4503" },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    expect(
      await screen.findByText(/Card declined: insufficient funds/i)
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /try again/i }));

    await vi.waitFor(() =>
      expect(screen.getByTestId("card-number")).toBeInTheDocument()
    );
    expect(
      (screen.getByTestId("card-number") as HTMLInputElement).value
    ).toBe("5100 0000 0000 0198");
  });

  it("shows the confirming modal when a no-3DS charge is still processing", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_card_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "processing",
      imageUrl: null,
      redirectUrl: null,
      expiresAt: null,
      lastPaymentError: null,
    });
    mockResolveIntentStatus.mockResolvedValue({ status: "processing", invoice_id: 1 });

    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "4343 4343 4343 4345" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "12/29" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "123" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "Maria" },
    });
    fireEvent.change(screen.getByTestId("card-last-name"), {
      target: { value: "Santos" },
    });
    fireEvent.change(screen.getByTestId("card-address"), {
      target: { value: "123 Purok 3" },
    });
    fireEvent.change(screen.getByTestId("card-city"), {
      target: { value: "Guinobatan" },
    });
    fireEvent.change(screen.getByTestId("card-zip"), {
      target: { value: "4503" },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(mockResolveIntentStatus).toHaveBeenCalledWith("pi_1")
    );
    expect(assignSpy).not.toHaveBeenCalled();
    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("card-processing")).not.toBeInTheDocument();
    // The pending record carries the intent so a refresh recovers the modal.
    expect(mockWritePendingInvoice).toHaveBeenCalledWith("1", {
      paymentIntentId: "pi_1",
      method: "card",
    });
  });

  it("locks the flow and confirms a frictionless succeeded charge immediately", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_card_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "succeeded",
      imageUrl: null,
      redirectUrl: null,
      expiresAt: null,
      lastPaymentError: null,
    });
    mockResolveIntentStatus.mockResolvedValue({ status: "confirmed", invoice_id: 1 });

    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "4343 4343 4343 4345" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "12/29" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "123" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "Maria" },
    });
    fireEvent.change(screen.getByTestId("card-last-name"), {
      target: { value: "Santos" },
    });
    fireEvent.change(screen.getByTestId("card-address"), {
      target: { value: "123 Purok 3" },
    });
    fireEvent.change(screen.getByTestId("card-city"), {
      target: { value: "Guinobatan" },
    });
    fireEvent.change(screen.getByTestId("card-zip"), {
      target: { value: "4503" },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(mockResolveIntentStatus).toHaveBeenCalledWith("pi_1")
    );
    await vi.waitFor(() =>
      expect(screen.getByTestId("confirming-modal")).toBeInTheDocument()
    );
    // The modal's backdrop covers the pay screen — nothing is clickable.
    expect(mockWritePendingInvoice).toHaveBeenCalledWith("1", {
      paymentIntentId: "pi_1",
      method: "card",
    });
  });

  it("shows the success modal right away when a frictionless charge is already credited", async () => {
    mockStartPayment.mockResolvedValue(intentInfo);
    mockCreatePaymentMethod.mockResolvedValue("pm_card_1");
    mockAttachPaymentMethod.mockResolvedValue({
      status: "succeeded",
      imageUrl: null,
      redirectUrl: null,
      expiresAt: null,
      lastPaymentError: null,
    });
    mockResolveIntentStatus.mockResolvedValue({ status: "paid", invoice_id: 1 });

    await renderReady();
    fireEvent.click(screen.getByTestId("method-card-card"));
    await vi.waitFor(() =>
      expect(screen.getByTestId("review-step")).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId("card-number"), {
      target: { value: "4343 4343 4343 4345" },
    });
    fireEvent.change(screen.getByTestId("card-expiry"), {
      target: { value: "12/29" },
    });
    fireEvent.change(screen.getByTestId("card-cvc"), {
      target: { value: "123" },
    });
    fireEvent.change(screen.getByTestId("card-first-name"), {
      target: { value: "Maria" },
    });
    fireEvent.change(screen.getByTestId("card-last-name"), {
      target: { value: "Santos" },
    });
    fireEvent.change(screen.getByTestId("card-address"), {
      target: { value: "123 Purok 3" },
    });
    fireEvent.change(screen.getByTestId("card-city"), {
      target: { value: "Guinobatan" },
    });
    fireEvent.change(screen.getByTestId("card-zip"), {
      target: { value: "4503" },
    });

    fireEvent.click(screen.getByTestId("pay-now"));

    await vi.waitFor(() =>
      expect(screen.getByTestId("success-modal")).toBeInTheDocument()
    );
    expect(screen.queryByTestId("card-processing")).not.toBeInTheDocument();
  });
});
