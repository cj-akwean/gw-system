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
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({
        message: "If an account exists for that email, a verification code is on its way.",
      })
    );
    vi.stubGlobal("fetch", fetchSpy);
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: "lost@example.com" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send code" }));

    expect(
      await screen.findByText(/a verification code is on its way/i)
    ).toBeInTheDocument();

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/forgot-password");
    expect(JSON.parse(String(init.body))).toEqual({ email: "lost@example.com" });
  });

  it("surfaces a server error", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse({ message: "Couldn't send the code." }, false, 422)
        )
    );
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

  it("links to the reset page and back to sign in", async () => {
    mockUseAuth = () => ({ isAuthenticated: false, ready: true });

    render(<ForgotPasswordPage />);

    expect(screen.getByText("Back to sign in").closest("a")).toHaveAttribute(
      "href",
      "/auth"
    );
  });
});
