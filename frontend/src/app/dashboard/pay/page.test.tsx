import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import PayPage from "@/app/dashboard/pay/page";

const mockReplace = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
  useSearchParams: () => mockSearchParams(),
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("@/components/portal/payment-method", () => ({
  PaymentMethodScreen: ({
    invoiceId,
    returnedFromGcash,
  }: {
    invoiceId: string;
    returnedFromGcash?: boolean;
  }) => (
    <div>
      pay-screen {invoiceId} {returnedFromGcash ? "gcash-return" : ""}
    </div>
  ),
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  logout: () => void;
};

let mockSearchParams: () => URLSearchParams;

describe("PayPage guard", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockSearchParams = () => new URLSearchParams("id=7");
  });

  it("renders nothing until auth state is ready", () => {
    mockUseAuth = () => ({ isAuthenticated: true, ready: false, logout: vi.fn() });

    render(<PayPage />);

    expect(screen.queryByText(/pay-screen/)).not.toBeInTheDocument();
  });

  it("redirects to /auth when not authenticated", () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true, logout: vi.fn() });

    render(<PayPage />);

    expect(mockReplace).toHaveBeenCalledWith("/auth");
  });

  it("renders the payment screen for the invoice in the router search params", async () => {
    mockUseAuth = () => ({ isAuthenticated: true, ready: true, logout: vi.fn() });

    render(<PayPage />);

    expect(await screen.findByText("pay-screen 7")).toBeInTheDocument();
  });

  it("uses the router search params, not a one-shot window.location read", async () => {
    mockSearchParams = () => new URLSearchParams("id=11");
    mockUseAuth = () => ({ isAuthenticated: true, ready: true, logout: vi.fn() });

    render(<PayPage />);

    expect(await screen.findByText("pay-screen 11")).toBeInTheDocument();
  });

  it("passes an empty id to the screen when the query string is missing", async () => {
    mockSearchParams = () => new URLSearchParams("");
    mockUseAuth = () => ({ isAuthenticated: true, ready: true, logout: vi.fn() });

    render(<PayPage />);

    expect(await screen.findByText("pay-screen")).toBeInTheDocument();
  });

  it("flags the gcash return to the screen", async () => {
    mockSearchParams = () => new URLSearchParams("id=7&from=gcash");
    mockUseAuth = () => ({ isAuthenticated: true, ready: true, logout: vi.fn() });

    render(<PayPage />);

    expect(
      await screen.findByText("pay-screen 7 gcash-return")
    ).toBeInTheDocument();
  });
});
