import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import ForgotPasswordPage from "@/app/forgot-password/page";

const mockReplace = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
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

function seedFetch(
  health: { available: boolean; hasPhone: boolean },
  forgotResponse: unknown = {
    message: "If an account exists for that email, a verification code is on its way.",
  }
): ReturnType<typeof vi.fn> {
  const fetchSpy = vi.fn((url: string) => {
    if (String(url).includes("/api/health/sms")) {
      return Promise.resolve(
        jsonResponse({ available: health.available, hasPhone: health.hasPhone })
      );
    }
    return Promise.resolve(jsonResponse(forgotResponse));
  });
  vi.stubGlobal("fetch", fetchSpy);
  return fetchSpy;
}

function forgotCall(fetchSpy: ReturnType<typeof vi.fn>): [string, RequestInit] {
  const call = fetchSpy.mock.calls.find(([url]: [string]) =>
    String(url).includes("/api/forgot-password")
  ) as [string, RequestInit];
  expect(call).toBeDefined();
  return call;
}

describe("ForgotPasswordPage", () => {
  beforeEach(() => {
    mockReplace.mockReset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("redirects authenticated users to the dashboard", () => {
    mockUseAuth = () => ({ isAuthenticated: true, ready: true });

    render(<ForgotPasswordPage />);

    expect(mockReplace).toHaveBeenCalledWith("/dashboard");
  });

  it("sends the code and shows the success state", async () => {
    const fetchSpy = seedFetch({ available: false, hasPhone: false });
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: "lost@example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send code" }));

    expect(
      await screen.findByText(/a verification code is on its way/i)
    ).toBeInTheDocument();

    const [url, init] = forgotCall(fetchSpy);
    expect(url).toBe("http://127.0.0.1:8000/api/forgot-password");
    expect(JSON.parse(String(init.body))).toEqual({
      email: "lost@example.com",
      channel: "email",
    });
  });

  it("surfaces a server error", async () => {
    const fetchSpy = vi.fn((url: string) => {
      if (String(url).includes("/api/health/sms")) {
        return Promise.resolve(
          jsonResponse({ available: false, hasPhone: false })
        );
      }
      return Promise.resolve(
        jsonResponse({ message: "Couldn't send the code." }, false, 422)
      );
    });
    vi.stubGlobal("fetch", fetchSpy);
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: "lost@example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send code" }));

    expect(
      await screen.findByText("Couldn't send the code.")
    ).toBeInTheDocument();
  });

  it("sends the code by SMS when the SMS radio is chosen", async () => {
    const fetchSpy = seedFetch({ available: true, hasPhone: false });
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    const smsRadio = await screen.findByRole("radio", { name: "SMS" });
    fireEvent.click(smsRadio);

    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: "lost@example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send code" }));

    await screen.findByText(/a verification code is on its way/i);

    const [, init] = forgotCall(fetchSpy);
    expect(JSON.parse(String(init.body))).toEqual({
      email: "lost@example.com",
      channel: "sms",
    });
  });

  it("sends the code by email by default when SMS is available", async () => {
    const fetchSpy = seedFetch({ available: true, hasPhone: false });
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    const emailRadio = await screen.findByRole("radio", { name: "Email" });
    expect(emailRadio).toHaveAttribute("aria-checked", "true");

    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: "lost@example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send code" }));

    await screen.findByText(/a verification code is on its way/i);

    const [, init] = forgotCall(fetchSpy);
    expect(JSON.parse(String(init.body))).toEqual({
      email: "lost@example.com",
      channel: "email",
    });
  });

  it("shows the channel picker with capability-level helper text when SMS is available", async () => {
    seedFetch({ available: true, hasPhone: false });
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    expect(
      await screen.findByRole("radiogroup", { name: "Reset code channel" })
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        /SMS codes require a phone number saved on the account; otherwise the code is sent by email/i
      )
    ).toBeInTheDocument();
  });

  it("hides the channel picker when SMS delivery is unavailable", async () => {
    seedFetch({ available: false, hasPhone: false });
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(
      screen.queryByRole("radiogroup", { name: "Reset code channel" })
    ).not.toBeInTheDocument();
  });

  it("links to the reset page and back to sign in", async () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    expect(screen.getByText("Back to sign in").closest("a")).toHaveAttribute(
      "href",
      "/auth"
    );
  });
});