import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import ResetPasswordPage from "@/app/reset-password/page";

const mockReplace = vi.fn();
let mockSearchParams = new URLSearchParams("");

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
  useSearchParams: () => mockSearchParams,
}));

vi.mock("next/link", () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
};

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("@/components/portal/page-loader", () => ({
  PageLoader: () => <div>loading</div>,
}));

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function fillForm() {
  fireEvent.change(screen.getByLabelText("Email"), {
    target: { value: "lost@example.com" },
  });
  fireEvent.change(screen.getByLabelText("Verification code"), {
    target: { value: "123456" },
  });
  fireEvent.change(screen.getByLabelText("New password"), {
    target: { value: "new-password-1" },
  });
  fireEvent.change(screen.getByLabelText("Confirm new password"), {
    target: { value: "new-password-1" },
  });
}

describe("ResetPasswordPage", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockSearchParams = new URLSearchParams("");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("redirects authenticated users to the dashboard", () => {
    mockUseAuth = () => ({ isAuthenticated: true, ready: true });

    render(<ResetPasswordPage />);

    expect(mockReplace).toHaveBeenCalledWith("/dashboard");
  });

  it("prefills the email from the query param", () => {
    mockSearchParams = new URLSearchParams("email=lost%40example.com");
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    expect(screen.getByLabelText("Email")).toHaveValue("lost@example.com");
  });

  it("resets the password and shows the success state", async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ message: "Password reset. You can now sign in." })
      );
    vi.stubGlobal("fetch", fetchSpy);
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    fillForm();
    fireEvent.click(screen.getByRole("button", { name: "Reset password" }));

    expect(await screen.findByText(/password reset/i)).toBeInTheDocument();

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/reset-password");
    expect(JSON.parse(String(init.body))).toEqual({
      email: "lost@example.com",
      otp: "123456",
      password: "new-password-1",
      password_confirmation: "new-password-1",
    });
  });

  it("shows a client-side error when the passwords do not match", async () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    fillForm();
    fireEvent.change(screen.getByLabelText("Confirm new password"), {
      target: { value: "different-1" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Reset password" }));

    expect(
      await screen.findByText("New passwords do not match.")
    ).toBeInTheDocument();
  });

  it("shows a client-side error for a short password", async () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    fillForm();
    fireEvent.change(screen.getByLabelText("New password"), {
      target: { value: "short" },
    });
    fireEvent.change(screen.getByLabelText("Confirm new password"), {
      target: { value: "short" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Reset password" }));

    expect(
      await screen.findByText("New password must be at least 8 characters.")
    ).toBeInTheDocument();
  });

  it("surfaces a server error for an invalid code", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: "That code is invalid or has expired." },
          false,
          422
        )
      )
    );
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    fillForm();
    fireEvent.click(screen.getByRole("button", { name: "Reset password" }));

    expect(
      await screen.findByText("That code is invalid or has expired.")
    ).toBeInTheDocument();
  });

  it("links to request a new code", async () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ResetPasswordPage />);

    expect(screen.getByText("Request a new one").closest("a")).toHaveAttribute(
      "href",
      "/forgot-password"
    );
  });
});
